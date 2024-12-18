<?php
session_start();

try {
    // Connect to the SQLite database
    $db = new PDO('sqlite:posts.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Check if user is logged in by verifying session
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['status' => 'error', 'message' => 'User not logged in']);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['action'])) {
            $action = $_POST['action'];

            // Load chat rooms
            if ($action === 'load_rooms') {
                $stmt = $db->query("SELECT id, room_name FROM chat_rooms");
                $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['status' => 'success', 'rooms' => $rooms]);
                exit;
            }

            // Create a new chat room
            if ($action === 'create_room' && isset($_POST['room_name'])) {
                try {
                    $stmt = $db->prepare("INSERT INTO chat_rooms (room_name) VALUES (:room_name)");
                    $stmt->bindParam(':room_name', $_POST['room_name']);
                    $stmt->execute();
                    echo json_encode(['status' => 'success']);
                    exit;
                } catch (PDOException $e) {
                    echo json_encode(['status' => 'error', 'message' => 'Error creating room: ' . $e->getMessage()]);
                }
            }

            // Send a message to a chat room
            if ($action === 'send_message' && isset($_POST['room_id'], $_POST['message'])) {
                try {
                    $stmt = $db->prepare("INSERT INTO chat_messages (room_id, user_id, message) VALUES (:room_id, :user_id, :message)");
                    $stmt->bindParam(':room_id', $_POST['room_id']);
                    $stmt->bindParam(':user_id', $_SESSION['user_id']);
                    $stmt->bindParam(':message', $_POST['message']);
                    $stmt->execute();
                    echo json_encode(['status' => 'success']);
                    exit;
                } catch (PDOException $e) {
                    echo json_encode(['status' => 'error', 'message' => 'Error sending message: ' . $e->getMessage()]);
                }
            }

            // Retrieve messages from a chat room
            if ($action === 'get_messages' && isset($_POST['room_id'])) {
                $stmt = $db->prepare("SELECT user_id, message, timestamp FROM chat_messages WHERE room_id = :room_id ORDER BY timestamp ASC");
                $stmt->bindParam(':room_id', $_POST['room_id']);
                $stmt->execute();
                $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode($messages);
                exit;
            }
        }
    }
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
