// Copyright 2024 PianoMan0
<?php

session_start();

// Require users to log in.
if (!isset($_SESSION['username'])) {
    header('Location: login.php');
    exit;
}

// Connect to the database
$db = new PDO('sqlite:posts.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if (empty($_GET['id'])) {
    echo "No user profile ID specified.";
    exit;
}

$profile_id = $_GET['id'];

// Determine if new content has been submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $profile = $_POST['profile'] ?? null;
    $from_user_id = $_POST['from_user_id'] ?? null;
    $to_user_id = $_POST['to_user_id'] ?? null;
    $message = $_POST['message'] ?? null;

    if (!empty($profile)) {
        // Update the user's profile
        $stmt = $db->prepare("UPDATE users SET profile = :profile WHERE id = :user_id");
        $stmt->bindParam(':user_id', $_SESSION['user_id']);
        $stmt->bindParam(':profile', $profile);
        $stmt->execute();
    }

    if (isset($_FILES['image'])) {
        $uploadDir = 'uploads/';

        // Process the uploaded file
        $image = $_FILES['image'];

        if ($image['error'] === UPLOAD_ERR_OK) {

            // Get image info
            $imageInfo = getimagesize($image['tmp_name']);

            if ($imageInfo) {

                // Check if the file is a JPG image
                $mimeType = $imageInfo['mime'];

                if ($mimeType === 'image/jpeg') {

                    $sourceImage = imagecreatefromjpeg($image['tmp_name']);

                    // Get original dimensions
                    $originalWidth = $imageInfo[0];
                    $originalHeight = $imageInfo[1];

                    // Calculate new dimensions while maintaining aspect ratio
                    $maxSize = 60;
                    if ($originalWidth > $originalHeight) {
                        $newWidth = $maxSize;
                        $newHeight = intval($originalHeight * $maxSize / $originalWidth);
                    } else {
                        $newHeight = $maxSize;
                        $newWidth = intval($originalWidth * $maxSize / $originalHeight);
                    }

                    // Create a new true color image with the new dimensions
                    $resizedImage = imagecreatetruecolor($newWidth, $newHeight);
                    imagecopyresampled($resizedImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $originalWidth, $originalHeight);

                    // Generate a unique hashed filename
                    $hashedFilename = $uploadDir . 'profile_' . $profile_id . '.jpg';

                    // Save the resized image
                    if (imagejpeg($resizedImage, $hashedFilename, 85)) {
                        
                    } else {
                        error_log('imagejpeg failed');
                    }

                    // Free up memory
                    imagedestroy($sourceImage);
                    imagedestroy($resizedImage);
                } else { error_log('wrong mimetype'); }
            } else { error_log('missing image info'); }
        } else { error_log('missing UPLOAD_ERR_OK '); }
    } else { error_log('file not set'); }      

    if (!empty($from_user_id) && !empty($to_user_id) && !empty($message)) {
        // Add a new direct message
        $stmt = $db->prepare("INSERT INTO messages (to_user_id, from_user_id, message) VALUES (:to_user_id, :from_user_id, :message)");
        $stmt->bindParam(':to_user_id', $to_user_id);
        $stmt->bindParam(':from_user_id', $from_user_id);
        $stmt->bindParam(':message', $message);
        $stmt->execute();
    }

    header("Location: profile.php?id=".$profile_id);
    exit;

}

// Get the profile of the user
$stmt = $db->prepare("SELECT * FROM users WHERE id = :profile_id");
$stmt->bindParam(':profile_id', $profile_id);
$stmt->execute();
$profile = $stmt->fetch(PDO::FETCH_ASSOC);

// Get direct messages between the logged-in user and the user whose profile is being viewed
$stmt = $db->prepare("
    SELECT messages.*, users.username AS from_username FROM messages
    JOIN users ON users.id = messages.from_user_id
    WHERE (to_user_id = :profile_id AND from_user_id = :user_id)
    OR (from_user_id = :profile_id AND to_user_id = :user_id)
    ORDER BY timestamp DESC
");
$stmt->bindParam(':profile_id', $profile_id);
$stmt->bindParam(':user_id', $_SESSION['user_id']);
$stmt->execute();
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta name='viewport' content='width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0, target-densityDpi=device-dpi, minimal-ui' />
    <title>Billion - Profile page for <?=$profile['username'];?></title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>

    <div class="logout"><a href="index.php?action=logout">Logout</a></div>

    <a href="index.php"><img src="billion_small.png" height=100 style="margin-bottom:15px"></a><br>

    <? if ($profile_id == $_SESSION['user_id']) { ?>
    <form action="profile.php?id=<?=$profile_id;?>" method="POST" enctype="multipart/form-data">

        <textarea style="min-height: 150px" id="profile" name="profile" placeholder="Add some profile text, <?=$_SESSION['username'];?>!"><?
        
        if (!empty($profile['profile'])) { echo $profile['profile']; }

        ?></textarea>
        <br><input type="file" name="image" id="image" accept="image/jpeg"><br>
        <button type="submit">Submit</button>
    </form>
    <? } ?>

    <img src="uploads/profile_<?= $profile_id; ?>.jpg" onerror="this.onerror=null; this.src='uploads/placeholder-image.svg';" alt="Profile Picture">

    <h2>Profile page for <?=$profile['username'];?></h2>
    <? if (!empty($profile['profile'])) {
        echo nl2br($profile['profile']);
    } else {
        echo "No profile yet.";
    } ?>

    <h2>Direct Messages</h2>

    <form action="profile.php?id=<?=$profile_id;?>" method="POST">
        <input type="hidden" id="to_user_id" name="to_user_id" value="<?=$profile_id;?>">
        <input type="hidden" id="from_user_id" name="from_user_id" value="<?=$_SESSION['user_id'];?>">
        <textarea id="message" name="message" required placeholder="What's on your mind, <?=$_SESSION['username'];?>?"></textarea>
        <br>
        <button type="submit">Submit</button>
    </form>

    <?php if (!empty($messages)): ?>
        <ul>
            <?php foreach ($messages as $message): ?>
                <li>
                    <div class="post-content">
                        <?php echo htmlspecialchars($message['message']); ?>
                    </div>
                    <div class="post-footer">
                        <strong>
                            <a href="profile.php?id=<?=$message['from_user_id'];?>"><?php echo htmlspecialchars($message['from_username']); ?></a>
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
