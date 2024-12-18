<?php
session_start();

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php"); // Redirect to login if not logged in
    exit;
}

// Database connection
try {
    $db = new PDO('sqlite:posts.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Could not connect to the database: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat Application</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container">
        <h1>Chat Application</h1>
        
        <div id="roomCreation">
            <input type="text" id="roomNameInput" placeholder="Enter room name">
            <button id="createRoomButton">Create Room</button>
        </div>

        <div id="roomList">
            <h2>Rooms</h2>
            <!-- Chat rooms will be populated here -->
        </div>

        <div id="messagesContainer">
            <h2>Messages</h2>
            <!-- Messages will be displayed here -->
        </div>

        <div id="messageInputArea" style="display: none;">
            <input type="text" id="messageInput" placeholder="Type your message...">
            <button id="sendMessageButton">Send Message</button>
        </div>

        <div>
            <p><a href="logout.php">Logout</a></p>
        </div>
    </div>

    <script src="chat_script.js"></script>
</body>
</html>
