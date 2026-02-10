<?php
// api.php - ShopBot Backend with ENHANCED Buyer-Seller Conversations
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

// CORS Headers
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Max-Age: 86400');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

session_start();

if (!isset($_SESSION['session_id'])) {
    $_SESSION['session_id'] = session_id();
}

$sessionId = $_SESSION['session_id'];

// Log all requests for debugging
error_log("=== NEW REQUEST ===");
error_log("Method: " . $_SERVER['REQUEST_METHOD']);
error_log("Path: " . ($_GET['path'] ?? 'none'));
error_log("Session ID: " . $sessionId);

// Database Configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'shopbot_db');
define('DB_USER', 'root');
define('DB_PASS', '');

// Database Connection Class
class Database {
    private $conn = null;

    public function getConnection() {
        if ($this->conn !== null) {
            return $this->conn;
        }

        try {
            $this->conn = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
            error_log("✅ Database connected successfully");
        } catch(PDOException $e) {
            error_log("❌ Database connection failed: " . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Database connection failed',
                'message' => $e->getMessage()
            ]);
            exit();
        }

        return $this->conn;
    }
}

// Conversation Service
class ConversationService {
    private $db;
    private $conn;

    public function __construct() {
        $this->db = new Database();
        $this->conn = $this->db->getConnection();
    }

    public function getOrCreateConversation($sessionId) {
        try {
            error_log("🔍 Looking for conversation with session: $sessionId");
            
            // First, close any old active conversations for this session
            $closeQuery = "UPDATE conversations SET status = 'closed' WHERE session_id = :session_id AND status = 'active'";
            $closeStmt = $this->conn->prepare($closeQuery);
            $closeStmt->bindParam(':session_id', $sessionId);
            $closeStmt->execute();
            error_log("📦 Closed old conversations for session: $sessionId");
            
            // Always create a fresh new conversation
            error_log("📝 Creating new conversation for session: $sessionId");
            $query = "INSERT INTO conversations (session_id, status) VALUES (:session_id, 'active')";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':session_id', $sessionId);
            $stmt->execute();
            
            $newId = $this->conn->lastInsertId();
            error_log("✅ Created new conversation ID: $newId");
            return $newId;
        } catch(PDOException $e) {
            error_log("❌ Error in getOrCreateConversation: " . $e->getMessage());
            throw new Exception("Error managing conversation: " . $e->getMessage());
        }
    }

    public function sendMessage($conversationId, $senderType, $message) {
        try {
            error_log("📤 Sending message - ConvID: $conversationId, Sender: $senderType, Msg: " . substr($message, 0, 50));
            
            $query = "INSERT INTO messages (conversation_id, sender_type, message) VALUES (:conversation_id, :sender_type, :message)";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':conversation_id', $conversationId, PDO::PARAM_INT);
            $stmt->bindParam(':sender_type', $senderType);
            $stmt->bindParam(':message', $message);
            $result = $stmt->execute();
            
            if ($result) {
                error_log("✅ Message sent successfully");
                
                // Update conversation timestamp
                $updateQuery = "UPDATE conversations SET updated_at = CURRENT_TIMESTAMP WHERE id = :id";
                $updateStmt = $this->conn->prepare($updateQuery);
                $updateStmt->bindParam(':id', $conversationId, PDO::PARAM_INT);
                $updateStmt->execute();
            }
            
            return $result;
        } catch(PDOException $e) {
            error_log("❌ Error sending message: " . $e->getMessage());
            throw new Exception("Error sending message: " . $e->getMessage());
        }
    }

    public function getMessages($conversationId, $limit = 100) {
        try {
            error_log("📥 Fetching messages for conversation: $conversationId");
            
            $query = "SELECT id, sender_type, message, created_at FROM messages WHERE conversation_id = :conversation_id ORDER BY created_at ASC LIMIT :limit";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':conversation_id', $conversationId, PDO::PARAM_INT);
            $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $messages = $stmt->fetchAll();
            
            error_log("✅ Found " . count($messages) . " messages");
            return $messages;
        } catch(PDOException $e) {
            error_log("❌ Error fetching messages: " . $e->getMessage());
            throw new Exception("Error fetching messages: " . $e->getMessage());
        }
    }

    public function getAllActiveConversations() {
        try {
            error_log("📋 Fetching all active conversations");
            
            $query = "SELECT c.id, c.session_id, c.created_at, c.updated_at,
                      (SELECT COUNT(*) FROM messages WHERE conversation_id = c.id) as message_count,
                      (SELECT message FROM messages WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1) as last_message,
                      (SELECT created_at FROM messages WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1) as last_message_time
                      FROM conversations c 
                      WHERE c.status = 'active'
                      HAVING message_count > 0
                      ORDER BY c.updated_at DESC";
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            $conversations = $stmt->fetchAll();
            
            error_log("✅ Found " . count($conversations) . " active conversations with messages");
            foreach ($conversations as $conv) {
                error_log("  - Conversation ID {$conv['id']}: {$conv['message_count']} messages");
            }
            
            return $conversations;
        } catch(PDOException $e) {
            error_log("❌ Error fetching conversations: " . $e->getMessage());
            throw new Exception("Error fetching conversations: " . $e->getMessage());
        }
    }

    public function getConversationBySessionId($sessionId) {
        try {
            error_log("🔍 Getting conversation for session: $sessionId");
            
            $query = "SELECT id FROM conversations WHERE session_id = :session_id AND status = 'active' ORDER BY created_at DESC LIMIT 1";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':session_id', $sessionId);
            $stmt->execute();
            $conversation = $stmt->fetch();
            
            if ($conversation) {
                error_log("✅ Found conversation ID: " . $conversation['id']);
            } else {
                error_log("⚠️ No conversation found for session: $sessionId");
            }
            
            return $conversation;
        } catch(PDOException $e) {
            error_log("❌ Error fetching conversation: " . $e->getMessage());
            throw new Exception("Error fetching conversation: " . $e->getMessage());
        }
    }
    
    public function cleanupOldConversations($daysOld = 1) {
        try {
            // Delete conversations and their messages older than X days
            $query = "DELETE FROM conversations WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':days', $daysOld, PDO::PARAM_INT);
            $stmt->execute();
            $deleted = $stmt->rowCount();
            error_log("🗑️ Cleaned up $deleted old conversations");
            return $deleted;
        } catch(PDOException $e) {
            error_log("❌ Error cleaning conversations: " . $e->getMessage());
            throw new Exception("Error cleaning conversations: " . $e->getMessage());
        }
    }
}

// Product Service
class ProductService {
    private $db;
    private $conn;

    public function __construct() {
        $this->db = new Database();
        $this->conn = $this->db->getConnection();
    }

    public function getAllProducts() {
        try {
            $query = "SELECT id, name, price, image, category, rating, reviews, 
                      warehouse, delivery_days as deliveryDays, stock 
                      FROM products ORDER BY id";
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch(PDOException $e) {
            throw new Exception("Error fetching products: " . $e->getMessage());
        }
    }

    public function getProductById($id) {
        try {
            $query = "SELECT id, name, price, image, category, rating, reviews, 
                      warehouse, delivery_days as deliveryDays, stock 
                      FROM products WHERE id = :id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetch();
        } catch(PDOException $e) {
            throw new Exception("Error fetching product: " . $e->getMessage());
        }
    }

    public function getCheapestProducts($limit = 3) {
        try {
            $query = "SELECT id, name, price, image, category, rating, reviews, 
                      warehouse, delivery_days as deliveryDays, stock 
                      FROM products ORDER BY price ASC LIMIT :limit";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch(PDOException $e) {
            throw new Exception("Error fetching cheapest products: " . $e->getMessage());
        }
    }

    public function getFastestDelivery($location = 'Cikarang', $limit = 3) {
        try {
            $query = "SELECT id, name, price, image, category, rating, reviews, 
                      warehouse, delivery_days as deliveryDays, stock 
                      FROM products WHERE warehouse = :location 
                      ORDER BY delivery_days ASC LIMIT :limit";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':location', $location);
            $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch(PDOException $e) {
            throw new Exception("Error fetching fastest delivery: " . $e->getMessage());
        }
    }

    public function getBestRated($limit = 3) {
        try {
            $query = "SELECT id, name, price, image, category, rating, reviews, 
                      warehouse, delivery_days as deliveryDays, stock 
                      FROM products ORDER BY rating DESC LIMIT :limit";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch(PDOException $e) {
            throw new Exception("Error fetching best rated: " . $e->getMessage());
        }
    }

    public function searchProducts($searchTerm) {
        try {
            $searchTerm = "%{$searchTerm}%";
            $query = "SELECT id, name, price, image, category, rating, reviews, 
                      warehouse, delivery_days as deliveryDays, stock 
                      FROM products WHERE name LIKE :search OR category LIKE :search";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':search', $searchTerm);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch(PDOException $e) {
            throw new Exception("Error searching products: " . $e->getMessage());
        }
    }

    public function getProductsByCategory($category) {
        try {
            $query = "SELECT id, name, price, image, category, rating, reviews, 
                      warehouse, delivery_days as deliveryDays, stock 
                      FROM products WHERE category = :category";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':category', $category);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch(PDOException $e) {
            throw new Exception("Error fetching category: " . $e->getMessage());
        }
    }
}

// Cart Service
class CartService {
    private $db;
    private $conn;

    public function __construct() {
        $this->db = new Database();
        $this->conn = $this->db->getConnection();
    }

    public function getCart($sessionId) {
        try {
            $query = "SELECT c.id as cart_id, c.quantity, 
                      p.id, p.name, p.price, p.image, p.category, p.rating, 
                      p.reviews, p.warehouse, p.delivery_days as deliveryDays, p.stock
                      FROM cart c 
                      JOIN products p ON c.product_id = p.id 
                      WHERE c.session_id = :session_id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':session_id', $sessionId);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch(PDOException $e) {
            throw new Exception("Error fetching cart: " . $e->getMessage());
        }
    }

    public function addToCart($sessionId, $productId, $quantity = 1) {
        try {
            $query = "SELECT id, quantity FROM cart 
                      WHERE session_id = :session_id AND product_id = :product_id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':session_id', $sessionId);
            $stmt->bindParam(':product_id', $productId, PDO::PARAM_INT);
            $stmt->execute();
            $existing = $stmt->fetch();

            if ($existing) {
                $newQuantity = $existing['quantity'] + $quantity;
                $query = "UPDATE cart SET quantity = :quantity WHERE id = :id";
                $stmt = $this->conn->prepare($query);
                $stmt->bindParam(':quantity', $newQuantity, PDO::PARAM_INT);
                $stmt->bindParam(':id', $existing['id'], PDO::PARAM_INT);
                return $stmt->execute();
            } else {
                $query = "INSERT INTO cart (session_id, product_id, quantity) 
                          VALUES (:session_id, :product_id, :quantity)";
                $stmt = $this->conn->prepare($query);
                $stmt->bindParam(':session_id', $sessionId);
                $stmt->bindParam(':product_id', $productId, PDO::PARAM_INT);
                $stmt->bindParam(':quantity', $quantity, PDO::PARAM_INT);
                return $stmt->execute();
            }
        } catch(PDOException $e) {
            throw new Exception("Error adding to cart: " . $e->getMessage());
        }
    }

    public function removeFromCart($sessionId, $cartId) {
        try {
            $query = "DELETE FROM cart WHERE id = :id AND session_id = :session_id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':id', $cartId, PDO::PARAM_INT);
            $stmt->bindParam(':session_id', $sessionId);
            return $stmt->execute();
        } catch(PDOException $e) {
            throw new Exception("Error removing from cart: " . $e->getMessage());
        }
    }

    public function clearCart($sessionId) {
        try {
            $query = "DELETE FROM cart WHERE session_id = :session_id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':session_id', $sessionId);
            return $stmt->execute();
        } catch(PDOException $e) {
            throw new Exception("Error clearing cart: " . $e->getMessage());
        }
    }

    public function getCartTotal($sessionId) {
        try {
            $query = "SELECT SUM(p.price * c.quantity) as total 
                      FROM cart c 
                      JOIN products p ON c.product_id = p.id 
                      WHERE c.session_id = :session_id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':session_id', $sessionId);
            $stmt->execute();
            $result = $stmt->fetch();
            return $result['total'] ?? 0;
        } catch(PDOException $e) {
            throw new Exception("Error calculating cart total: " . $e->getMessage());
        }
    }
}

// Chatbot Service
class ChatbotService {
    private $productService;

    public function __construct() {
        $this->productService = new ProductService();
    }

    public function processMessage($message, $cartCount = 0) {
        $message = strtolower(trim($message));
        $response = [
            'content' => '',
            'products' => null,
            'filterType' => null,
            'quickReplies' => null
        ];

        try {
            if (strpos($message, 'hello') !== false || strpos($message, 'hi') !== false) {
                $response['content'] = "Hello! What are you looking for today?";
                $response['quickReplies'] = ['Browse Products', 'Cheapest Items', 'Fastest Delivery', 'Best Rated'];
            }
            elseif (strpos($message, 'cheap') !== false || strpos($message, 'budget') !== false) {
                $products = $this->productService->getCheapestProducts();
                if (!empty($products)) {
                    $response['content'] = "Here are our most affordable products. The cheapest is " . 
                                         $products[0]['name'] . " at $" . $products[0]['price'];
                    $response['products'] = $products;
                    $response['filterType'] = 'cheapest';
                    $response['quickReplies'] = ['Show More', 'Chat with Seller'];
                }
            }
            elseif (strpos($message, 'fast') !== false || strpos($message, 'quick') !== false || strpos($message, 'delivery') !== false) {
                $products = $this->productService->getFastestDelivery();
                if (!empty($products)) {
                    $response['content'] = "These products can be delivered fastest from our Cikarang warehouse!";
                    $response['products'] = $products;
                    $response['filterType'] = 'fastest';
                    $response['quickReplies'] = ['Show More', 'Chat with Seller'];
                }
            }
            elseif (strpos($message, 'best') !== false || strpos($message, 'rated') !== false || strpos($message, 'top') !== false) {
                $products = $this->productService->getBestRated();
                if (!empty($products)) {
                    $response['content'] = "Here are our highest-rated products. Top rated is " . 
                                         $products[0]['name'] . " with " . $products[0]['rating'] . " stars!";
                    $response['products'] = $products;
                    $response['filterType'] = 'best';
                    $response['quickReplies'] = ['Show More', 'Chat with Seller'];
                }
            }
            elseif (strpos($message, 'cart') !== false) {
                if ($cartCount == 0) {
                    $response['content'] = "Your cart is empty. Would you like to browse our products?";
                    $response['quickReplies'] = ['Browse Products', 'Cheapest Items'];
                } else {
                    $response['content'] = "You have $cartCount item(s) in your cart.";
                    $response['quickReplies'] = ['Chat with Seller', 'Continue Shopping'];
                }
            }
            elseif (strpos($message, 'electronics') !== false) {
                $products = $this->productService->getProductsByCategory('electronics');
                $response['content'] = "Here are our electronics:";
                $response['products'] = $products;
                $response['quickReplies'] = ['Cheapest Items', 'Best Rated'];
            }
            elseif (strpos($message, 'sports') !== false) {
                $products = $this->productService->getProductsByCategory('sports');
                $response['content'] = "Here are our sports products:";
                $response['products'] = $products;
                $response['quickReplies'] = ['Cheapest Items', 'Best Rated'];
            }
            elseif (strpos($message, 'browse') !== false || strpos($message, 'show') !== false || strpos($message, 'product') !== false) {
                $products = array_slice($this->productService->getAllProducts(), 0, 3);
                $response['content'] = "Here are some of our popular products:";
                $response['products'] = $products;
                $response['quickReplies'] = ['Cheapest Items', 'Fastest Delivery', 'Best Rated'];
            }
            elseif (strpos($message, 'help') !== false) {
                $response['content'] = "I can help you browse products, find the cheapest items, fastest delivery options, or best rated products!";
                $response['quickReplies'] = ['Cheapest Items', 'Fastest Delivery', 'Best Rated'];
            }
            else {
                $searchResults = $this->productService->searchProducts($message);
                if (count($searchResults) > 0) {
                    $response['content'] = "I found some products matching your search:";
                    $response['products'] = array_slice($searchResults, 0, 3);
                    $response['quickReplies'] = ['Show More', 'Chat with Seller'];
                } else {
                    $response['content'] = "I'm here to help! You can ask me to show products, find deals, or check delivery options.";
                    $response['quickReplies'] = ['Cheapest Items', 'Fastest Delivery', 'Best Rated'];
                }
            }
        } catch(Exception $e) {
            $response['content'] = "I'm having trouble processing that request. Please try again!";
            $response['quickReplies'] = ['Browse Products', 'Help'];
        }

        return $response;
    }
}

// Initialize services
$productService = new ProductService();
$cartService = new CartService();
$chatbotService = new ChatbotService();
$conversationService = new ConversationService();

// Cleanup old conversations automatically (older than 1 day)
// This runs on every 10th request to avoid overhead
if (rand(1, 10) === 1) {
    try {
        $conversationService->cleanupOldConversations(1);
    } catch (Exception $e) {
        error_log("Cleanup failed: " . $e->getMessage());
    }
}

$requestMethod = $_SERVER['REQUEST_METHOD'];
$path = isset($_GET['path']) ? $_GET['path'] : '';

error_log("Routing to: $path");

// Router
try {
    switch($path) {
        // Conversation endpoints
        case 'conversations/start':
            if ($requestMethod === 'POST') {
                $conversationId = $conversationService->getOrCreateConversation($sessionId);
                echo json_encode([
                    'success' => true,
                    'conversationId' => $conversationId,
                    'sessionId' => $sessionId
                ]);
            }
            break;

        case 'conversations/send':
            if ($requestMethod === 'POST') {
                $input = json_decode(file_get_contents('php://input'), true);
                $message = $input['message'] ?? '';
                $senderType = $input['senderType'] ?? 'buyer';
                
                error_log("Received send request - Message: $message, Sender: $senderType");
                
                $conversationId = $conversationService->getOrCreateConversation($sessionId);
                $conversationService->sendMessage($conversationId, $senderType, $message);
                
                echo json_encode([
                    'success' => true,
                    'conversationId' => $conversationId,
                    'sessionId' => $sessionId
                ]);
            }
            break;

        case 'conversations/messages':
            if ($requestMethod === 'GET') {
                $conversation = $conversationService->getConversationBySessionId($sessionId);
                
                if ($conversation) {
                    $messages = $conversationService->getMessages($conversation['id']);
                    echo json_encode([
                        'success' => true,
                        'data' => $messages,
                        'conversationId' => $conversation['id']
                    ]);
                } else {
                    echo json_encode([
                        'success' => true,
                        'data' => [],
                        'conversationId' => null
                    ]);
                }
            }
            break;

        case 'conversations/all':
            if ($requestMethod === 'GET') {
                $conversations = $conversationService->getAllActiveConversations();
                echo json_encode([
                    'success' => true,
                    'data' => $conversations
                ]);
            }
            break;

        case 'conversations/by-id':
            if ($requestMethod === 'GET') {
                $conversationId = isset($_GET['id']) ? intval($_GET['id']) : 0;
                $messages = $conversationService->getMessages($conversationId);
                echo json_encode([
                    'success' => true,
                    'data' => $messages
                ]);
            }
            break;

        case 'conversations/reply':
            if ($requestMethod === 'POST') {
                $input = json_decode(file_get_contents('php://input'), true);
                $conversationId = $input['conversationId'] ?? 0;
                $message = $input['message'] ?? '';
                
                error_log("Seller reply - ConvID: $conversationId, Message: $message");
                
                $conversationService->sendMessage($conversationId, 'seller', $message);
                
                echo json_encode([
                    'success' => true
                ]);
            }
            break;
        
        case 'conversations/cleanup':
            if ($requestMethod === 'POST') {
                $input = json_decode(file_get_contents('php://input'), true);
                $days = $input['days'] ?? 1;
                
                $deleted = $conversationService->cleanupOldConversations($days);
                
                echo json_encode([
                    'success' => true,
                    'deleted' => $deleted,
                    'message' => "Deleted $deleted old conversations"
                ]);
            }
            break;

        // Product endpoints
        case 'products':
            if ($requestMethod === 'GET') {
                echo json_encode([
                    'success' => true,
                    'data' => $productService->getAllProducts()
                ]);
            }
            break;

        case 'products/cheapest':
            if ($requestMethod === 'GET') {
                $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 3;
                echo json_encode([
                    'success' => true,
                    'data' => $productService->getCheapestProducts($limit)
                ]);
            }
            break;

        case 'products/fastest':
            if ($requestMethod === 'GET') {
                $location = isset($_GET['location']) ? $_GET['location'] : 'Cikarang';
                $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 3;
                echo json_encode([
                    'success' => true,
                    'data' => $productService->getFastestDelivery($location, $limit)
                ]);
            }
            break;

        case 'products/best':
            if ($requestMethod === 'GET') {
                $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 3;
                echo json_encode([
                    'success' => true,
                    'data' => $productService->getBestRated($limit)
                ]);
            }
            break;

        case 'products/add':
            if ($requestMethod === 'POST') {
                $input = json_decode(file_get_contents('php://input'), true);
                
                $query = "INSERT INTO products (name, price, image, category, rating, reviews, warehouse, delivery_days, stock) 
                          VALUES (:name, :price, :image, :category, :rating, :reviews, :warehouse, :delivery_days, :stock)";
                $stmt = $productService->conn->prepare($query);
                $stmt->bindParam(':name', $input['name']);
                $stmt->bindParam(':price', $input['price']);
                $stmt->bindParam(':image', $input['image']);
                $stmt->bindParam(':category', $input['category']);
                $stmt->bindParam(':rating', $input['rating']);
                $stmt->bindParam(':reviews', $input['reviews'], PDO::PARAM_INT);
                $stmt->bindParam(':warehouse', $input['warehouse']);
                $stmt->bindParam(':delivery_days', $input['delivery_days'], PDO::PARAM_INT);
                $stmt->bindParam(':stock', $input['stock'], PDO::PARAM_INT);
                
                if ($stmt->execute()) {
                    echo json_encode([
                        'success' => true,
                        'message' => 'Product added successfully',
                        'productId' => $productService->conn->lastInsertId()
                    ]);
                }
            }
            break;

        case 'products/delete':
            if ($requestMethod === 'POST') {
                $input = json_decode(file_get_contents('php://input'), true);
                $productId = $input['productId'];
                
                $query = "DELETE FROM products WHERE id = :id";
                $stmt = $productService->conn->prepare($query);
                $stmt->bindParam(':id', $productId, PDO::PARAM_INT);
                
                if ($stmt->execute()) {
                    echo json_encode([
                        'success' => true,
                        'message' => 'Product deleted successfully'
                    ]);
                }
            }
            break;

        case 'products/update-stock':
            if ($requestMethod === 'POST') {
                $input = json_decode(file_get_contents('php://input'), true);
                $productId = $input['productId'];
                $stock = $input['stock'];
                
                $query = "UPDATE products SET stock = :stock WHERE id = :id";
                $stmt = $productService->conn->prepare($query);
                $stmt->bindParam(':stock', $stock, PDO::PARAM_INT);
                $stmt->bindParam(':id', $productId, PDO::PARAM_INT);
                
                if ($stmt->execute()) {
                    echo json_encode([
                        'success' => true,
                        'message' => 'Stock updated successfully'
                    ]);
                }
            }
            break;

        // Chat endpoint
        case 'chat':
            if ($requestMethod === 'POST') {
                $input = json_decode(file_get_contents('php://input'), true);
                $message = isset($input['message']) ? $input['message'] : '';
                
                $cartItems = $cartService->getCart($sessionId);
                $cartCount = count($cartItems);
                
                $response = $chatbotService->processMessage($message, $cartCount);
                
                echo json_encode([
                    'success' => true,
                    'data' => $response
                ]);
            }
            break;

        // Cart endpoints
        case 'cart':
            if ($requestMethod === 'GET') {
                $cartItems = $cartService->getCart($sessionId);
                $total = $cartService->getCartTotal($sessionId);
                
                echo json_encode([
                    'success' => true,
                    'data' => [
                        'items' => $cartItems,
                        'total' => round($total, 2),
                        'count' => count($cartItems)
                    ]
                ]);
            }
            break;

        case 'cart/add':
            if ($requestMethod === 'POST') {
                $input = json_decode(file_get_contents('php://input'), true);
                $productId = isset($input['productId']) ? intval($input['productId']) : 0;
                $quantity = isset($input['quantity']) ? intval($input['quantity']) : 1;
                
                $product = $productService->getProductById($productId);
                if ($product) {
                    $cartService->addToCart($sessionId, $productId, $quantity);
                    $cartItems = $cartService->getCart($sessionId);
                    
                    echo json_encode([
                        'success' => true,
                        'message' => $product['name'] . ' added to cart',
                        'cartCount' => count($cartItems)
                    ]);
                } else {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Product not found'
                    ]);
                }
            }
            break;

        case 'cart/remove':
            if ($requestMethod === 'POST') {
                $input = json_decode(file_get_contents('php://input'), true);
                $cartId = isset($input['cartId']) ? intval($input['cartId']) : 0;
                
                $cartService->removeFromCart($sessionId, $cartId);
                echo json_encode([
                    'success' => true,
                    'message' => 'Item removed from cart'
                ]);
            }
            break;

        case 'cart/clear':
            if ($requestMethod === 'DELETE' || $requestMethod === 'POST') {
                $cartService->clearCart($sessionId);
                echo json_encode([
                    'success' => true,
                    'message' => 'Cart cleared'
                ]);
            }
            break;

        default:
            error_log("❌ Invalid endpoint: $path");
            echo json_encode([
                'success' => false,
                'message' => 'Invalid endpoint: ' . $path
            ]);
            break;
    }
} catch (Exception $e) {
    error_log("❌ Exception: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}

error_log("=== END REQUEST ===\n");
?>
