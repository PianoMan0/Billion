<?php

// Copyright 2024-2025 PianoMan0

session_start();

// Require users to log in.
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Connect to the database
$db = new PDO('sqlite:posts.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$profile_id = $_SESSION['user_id'];

// Get direct messages between the logged-in user and the user whose profile is being viewed
$stmt = $db->prepare("
    SELECT messages.*, t1.username AS from_username, t2.username AS to_username FROM messages
    JOIN users t1 ON t1.id = messages.from_user_id
    JOIN users t2 ON t2.id = messages.to_user_id
    WHERE (to_user_id = :profile_id) OR (from_user_id = :profile_id)
    ORDER BY timestamp DESC
");
$stmt->bindParam(':profile_id', $profile_id);
$stmt->execute();
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta name='viewport' content='width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0, target-densityDpi=device-dpi, minimal-ui' />
    <meta charset="UTF-8">
    <meta name='viewport' content='width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0, target-densityDpi=device-dpi, minimal-ui' />
    <meta name="description" content="Join the Billion community to share and discover connection">
    <meta name="keywords" content="billion, social media, community, posts">
    <meta name="author" content="PianoMan0">
    <meta property="og:site_name" content="Billion" />
    <meta property="og:title" content="Billion - Messages Feed" />
    <meta property="og:description" content="Join the Billion community to share and discover connection" />
    <meta property="og:type" content="website" />
    <meta property="og:image" content="billion_small.png" />
    <link rel="icon" type="image/png" href="billion_small.png">
    <link rel="apple-touch-icon" sizes="180x180" href="billion_small.png">
    <title>Billion - Messages for <?=$profile['username'];?></title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>

    <div class="logout"><a href="index.php?action=logout">Logout</a></div>

    <a href="index.php"><img src="billion_small.png" height=100 style="margin-bottom:15px"></a><br>

    <h2>Direct Messages <a href="#" title="Refresh page" onclick="location.reload();"><img src="reload.svg" height="20"></a></h2>
    <style>
    .self-bg {
        background-color: #f1e6ff;
    }
    </style>

    <?php if (!empty($messages)): ?>
        <ul>
            <?php foreach ($messages as $message): ?>
                <li <?php if ($message['from_user_id'] == $_SESSION['user_id']) { ?>class="self-bg" <?php } ?>>
                    <div class="post-content">
                        <?php echo htmlspecialchars($message['message']); ?>
                    </div>
                    <div class="post-footer">
                        <strong>
                            <?php if ($message['from_user_id'] == $_SESSION['user_id']) { ?>
                            To <a href="profile.php?id=<?=$message['to_user_id'];?>"><?php echo htmlspecialchars($message['to_username']); ?></a>
                            <?php } else { ?>
                            From <a href="profile.php?id=<?=$message['from_user_id'];?>"><?php echo htmlspecialchars($message['from_username']); ?></a>
                            <?php } ?>
                        </strong>
                        <em>(<?php echo $message['timestamp']; ?>)</em>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <p>No messages yet.</p>
    <?php endif; ?>


    <div style="margin-top:50px"><a href="index.php">&#171; Back to News Feed</a></div>

</body>
</html>
