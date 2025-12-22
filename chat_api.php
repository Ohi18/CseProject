<?php
session_start();
header('Content-Type: application/json');

// Database configuration
$host = "localhost";
$username = "root";
$password = "";
$database = "goglam";

// Connect to database with error handling
try {
    $conn = new mysqli($host, $username, $password, $database);
    if ($conn->connect_error) {
        throw new Exception("Database connection failed: " . $conn->connect_error);
    }
} catch (mysqli_sql_exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection error: ' . $e->getMessage() . '. Please make sure XAMPP MySQL is running.']);
    exit();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    exit();
}

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$user_id = $_SESSION['user_id'];
$user_type = $_SESSION['user_type'];

// Get or create chat session
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_chat') {
    if ($user_type === 'customer') {
        $saloon_id = isset($_GET['saloon_id']) ? (int)$_GET['saloon_id'] : 0;
        if ($saloon_id <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid saloon_id']);
            exit();
        }
        
        // Get or create chat
        $chat_stmt = $conn->prepare("SELECT chat_id FROM chat WHERE saloon_id = ? AND customer_id = ?");
        $chat_stmt->bind_param("ii", $saloon_id, $user_id);
        $chat_stmt->execute();
        $chat_result = $chat_stmt->get_result();
        
        if ($chat_result->num_rows > 0) {
            $chat_row = $chat_result->fetch_assoc();
            $chat_id = $chat_row['chat_id'];
        } else {
            // Create new chat
            $create_stmt = $conn->prepare("INSERT INTO chat (saloon_id, customer_id) VALUES (?, ?)");
            $create_stmt->bind_param("ii", $saloon_id, $user_id);
            if ($create_stmt->execute()) {
                $chat_id = $conn->insert_id;
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to create chat']);
                exit();
            }
            $create_stmt->close();
        }
        $chat_stmt->close();
        
        // Fetch messages
        $messages_stmt = $conn->prepare("SELECT message_id, message_text, sender_type, time_stamp FROM message WHERE chat_id = ? ORDER BY time_stamp ASC");
        $messages_stmt->bind_param("i", $chat_id);
        $messages_stmt->execute();
        $messages_result = $messages_stmt->get_result();
        
        $messages = [];
        while ($msg_row = $messages_result->fetch_assoc()) {
            $messages[] = [
                'message_id' => $msg_row['message_id'],
                'message_text' => $msg_row['message_text'],
                'sender_type' => $msg_row['sender_type'],
                'time_stamp' => $msg_row['time_stamp']
            ];
        }
        $messages_stmt->close();
        
        echo json_encode(['chat_id' => $chat_id, 'messages' => $messages]);
        
    } elseif ($user_type === 'saloon') {
        // Get list of customers who have chatted with this saloon
        if (isset($_GET['customer_id'])) {
            $customer_id = (int)$_GET['customer_id'];
            
            // Get or create chat
            $chat_stmt = $conn->prepare("SELECT chat_id FROM chat WHERE saloon_id = ? AND customer_id = ?");
            $chat_stmt->bind_param("ii", $user_id, $customer_id);
            $chat_stmt->execute();
            $chat_result = $chat_stmt->get_result();
            
            if ($chat_result->num_rows > 0) {
                $chat_row = $chat_result->fetch_assoc();
                $chat_id = $chat_row['chat_id'];
            } else {
                // Create new chat
                $create_stmt = $conn->prepare("INSERT INTO chat (saloon_id, customer_id) VALUES (?, ?)");
                $create_stmt->bind_param("ii", $user_id, $customer_id);
                if ($create_stmt->execute()) {
                    $chat_id = $conn->insert_id;
                } else {
                    http_response_code(500);
                    echo json_encode(['error' => 'Failed to create chat']);
                    exit();
                }
                $create_stmt->close();
            }
            $chat_stmt->close();
            
            // Fetch messages
            $messages_stmt = $conn->prepare("SELECT message_id, message_text, sender_type, time_stamp FROM message WHERE chat_id = ? ORDER BY time_stamp ASC");
            $messages_stmt->bind_param("i", $chat_id);
            $messages_stmt->execute();
            $messages_result = $messages_stmt->get_result();
            
            $messages = [];
            while ($msg_row = $messages_result->fetch_assoc()) {
                $messages[] = [
                    'message_id' => $msg_row['message_id'],
                    'message_text' => $msg_row['message_text'],
                    'sender_type' => $msg_row['sender_type'],
                    'time_stamp' => $msg_row['time_stamp']
                ];
            }
            $messages_stmt->close();
            
            // Get customer name
            $customer_stmt = $conn->prepare("SELECT name FROM customer WHERE customer_id = ?");
            $customer_stmt->bind_param("i", $customer_id);
            $customer_stmt->execute();
            $customer_result = $customer_stmt->get_result();
            $customer_name = 'Customer';
            if ($customer_result->num_rows > 0) {
                $customer_row = $customer_result->fetch_assoc();
                $customer_name = $customer_row['name'];
            }
            $customer_stmt->close();
            
            echo json_encode(['chat_id' => $chat_id, 'customer_name' => $customer_name, 'messages' => $messages]);
        } else {
            // Get list of all customers who have chatted
            $chats_stmt = $conn->prepare("SELECT DISTINCT c.customer_id, c.name, 
                                        (SELECT time_stamp FROM message WHERE chat_id = chat.chat_id ORDER BY time_stamp DESC LIMIT 1) as last_message_time,
                                        (SELECT message_text FROM message WHERE chat_id = chat.chat_id ORDER BY time_stamp DESC LIMIT 1) as last_message
                                        FROM chat 
                                        INNER JOIN customer c ON chat.customer_id = c.customer_id 
                                        WHERE chat.saloon_id = ? 
                                        ORDER BY last_message_time DESC");
            $chats_stmt->bind_param("i", $user_id);
            $chats_stmt->execute();
            $chats_result = $chats_stmt->get_result();
            
            $chats = [];
            while ($chat_row = $chats_result->fetch_assoc()) {
                $chats[] = [
                    'customer_id' => $chat_row['customer_id'],
                    'customer_name' => $chat_row['name'],
                    'last_message' => $chat_row['last_message'],
                    'last_message_time' => $chat_row['last_message_time']
                ];
            }
            $chats_stmt->close();
            
            echo json_encode(['chats' => $chats]);
        }
    }
}

// Send message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_message') {
    $chat_id = isset($_POST['chat_id']) ? (int)$_POST['chat_id'] : 0;
    $message_text = isset($_POST['message_text']) ? trim($_POST['message_text']) : '';
    
    if ($chat_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid chat_id']);
        exit();
    }
    
    if (empty($message_text)) {
        http_response_code(400);
        echo json_encode(['error' => 'Message cannot be empty']);
        exit();
    }
    
    // Verify chat belongs to user
    if ($user_type === 'customer') {
        $verify_stmt = $conn->prepare("SELECT chat_id FROM chat WHERE chat_id = ? AND customer_id = ?");
        $verify_stmt->bind_param("ii", $chat_id, $user_id);
    } else {
        $verify_stmt = $conn->prepare("SELECT chat_id FROM chat WHERE chat_id = ? AND saloon_id = ?");
        $verify_stmt->bind_param("ii", $chat_id, $user_id);
    }
    $verify_stmt->execute();
    $verify_result = $verify_stmt->get_result();
    
    if ($verify_result->num_rows === 0) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        exit();
    }
    $verify_stmt->close();
    
    // Sanitize message
    $message_text = htmlspecialchars($message_text, ENT_QUOTES, 'UTF-8');
    
    // Insert message
    $insert_stmt = $conn->prepare("INSERT INTO message (chat_id, message_text, sender_type) VALUES (?, ?, ?)");
    $insert_stmt->bind_param("iss", $chat_id, $message_text, $user_type);
    
    if ($insert_stmt->execute()) {
        $message_id = $conn->insert_id;
        
        // Get actual timestamp from database
        $time_stmt = $conn->prepare("SELECT time_stamp FROM message WHERE message_id = ?");
        $time_stmt->bind_param("i", $message_id);
        $time_stmt->execute();
        $time_result = $time_stmt->get_result();
        $time_row = $time_result->fetch_assoc();
        $time_stamp = $time_row['time_stamp'];
        $time_stmt->close();
        
        echo json_encode([
            'success' => true,
            'message_id' => $message_id,
            'message_text' => $message_text,
            'sender_type' => $user_type,
            'time_stamp' => $time_stamp
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to send message']);
    }
    $insert_stmt->close();
}

$conn->close();
?>

