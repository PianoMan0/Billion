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


// Adjust "Like" count for post
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? null;
    $post_id = $_GET['post_id'] ?? null;

    // Log out
    if (!empty($action) && $action == 'logout') {
        session_destroy();
        header('Location: index.php');
        exit;
    }

    // Increase the like count for the specified post
    if (!empty($action) && $action == 'like' && !empty($post_id)) {
        $stmt = $db->prepare("INSERT OR IGNORE INTO likes (user_id, post_id) VALUES (:user_id, :post_id)");
        $stmt->bindParam(':user_id', $_SESSION['user_id']);
        $stmt->bindParam(':post_id', $post_id);
        $stmt->execute();

        header('Location: index.php');
        exit;
    }

    // Decrease the like count for the specified post
    if (!empty($action) && $action == 'unlike' && !empty($post_id)) {
        $stmt = $db->prepare("DELETE FROM likes WHERE user_id = :user_id AND post_id = :post_id");
        $stmt->bindParam(':user_id', $_SESSION['user_id']);
        $stmt->bindParam(':post_id', $post_id);
        $stmt->execute();

        header('Location: index.php');
        exit;
    }

    // Delete the specified post
    if (!empty($action) && $action == 'delete' && !empty($post_id)) {
        $stmt = $db->prepare("DELETE FROM posts WHERE user_id = :user_id AND id = :post_id");
        $stmt->bindParam(':user_id', $_SESSION['user_id']);
        $stmt->bindParam(':post_id', $post_id);
        $stmt->execute();

        header('Location: index.php');
        exit;
    }

}

// Determine if new content has been submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_POST['user_id'];
    $content = $_POST['content'];

    if (!empty($user_id) && !empty($content)) {
        
        // Insert the new post into the database
        $stmt = $db->prepare("INSERT INTO posts (user_id, content) VALUES (:user_id, :content)");
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':content', $content);
        $stmt->execute();
        $post_id = $db->lastInsertId();

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
                        $maxSize = 480;
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
                        $hashedFilename = $uploadDir . md5(uniqid(rand(), true)) . '.jpg';

                        // Save the resized image
                        if (imagejpeg($resizedImage, $hashedFilename, 85)) {
                            
                            $stmt = $db->prepare("INSERT INTO uploads (post_id, file_name) VALUES (:post_id, :file_name)");
                            $stmt->bindParam(':post_id', $post_id);
                            $stmt->bindParam(':file_name', $hashedFilename);
                            $stmt->execute();

                        } 

                        // Free up memory
                        imagedestroy($sourceImage);
                        imagedestroy($resizedImage);
                    }
                }
            }
        }    
    }

    header('Location: index.php');
    exit;

}

// Get a list of recent posts, along with their like counts
$stmt = $db->prepare("
    SELECT posts.id, posts.content, posts.timestamp, users.id AS user_id, users.username, 
    COUNT(likes.post_id) AS like_count, COUNT(likes2.post_id) AS user_liked, uploads.file_name
    FROM posts 
    JOIN users ON posts.user_id = users.id 
    LEFT JOIN likes ON likes.post_id = posts.id
    LEFT JOIN likes AS likes2 ON likes2.post_id = posts.id AND likes2.user_id = :user_id
    LEFT JOIN uploads ON posts.id = uploads.post_id
    GROUP BY posts.id, posts.content, posts.timestamp, users.username
    ORDER BY posts.timestamp DESC
");
$stmt->bindParam(':user_id', $_SESSION['user_id']);
$stmt->execute();
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta name='viewport' content='width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0, target-densityDpi=device-dpi, minimal-ui' />
    <title>Billion - User Posts Feed</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>

    <div class="logout"><a href="index.php?action=logout">Logout</a></div>
    
    <img src="billion_small.png" height=100 style="margin-bottom:15px"><br>

    <form action="index.php" method="POST" enctype="multipart/form-data">
        <input type="hidden" id="user_id" name="user_id" value="<?=$_SESSION['user_id'];?>">
        <textarea id="content" name="content" required placeholder="What's on your mind, <?=$_SESSION['username'];?>?"></textarea>
        <br><input type="file" name="image" id="image" accept="image/jpeg"><br>
        <button type="submit">Submit</button>
    </form>

    <h2>Recent Posts <a href="#" title="Refresh page" onclick="location.reload();"><img src="reload.svg" height="20"></a></h2>
    <?php if (!empty($posts)): ?>
        <ul>
            <?php foreach ($posts as $post): ?>
                <li>
                    <div class="right">
                       <span style="margin-right: 6px"><?=$post['like_count'];?> Likes</span>
                       <? if ($post['user_liked']) { ?><a href="index.php?action=unlike&post_id=<?=$post['id'];?>">Unlike</a><? }
                       else { ?><a href="index.php?action=like&post_id=<?=$post['id'];?>">Like</a><? } ?>
                    </div>
                    <div class="left">
                        <img src="uploads/profile_<?= $post['user_id']; ?>.jpg" onerror="this.onerror=null; this.src='uploads/placeholder-image.svg';">
                    </div>
                    <div class="post-content">
                        <?php echo nl2br(htmlspecialchars($post['content'])); ?>
                        <? if (!empty($post['file_name'])) { echo "<p><img src='".$post['file_name']."'></p>"; } ?>
                    </div>
                    <div class="post-footer">
                        <strong>
                            <a href="profile.php?id=<?=$post['user_id'];?>"><?php echo htmlspecialchars($post['username']); ?></a>
                            <? if ($post['username'] == $_SESSION['username']) { ?>
                                <a href="index.php?action=delete&post_id=<?=$post['id'];?>" title="Delete post"> &#128465;</a>
                            <? } ?>
                        </strong>
                        <em><?php 
                        
                        // Get the original time, in UTC
                        $date = new DateTime($post['timestamp'], new DateTimeZone('UTC'));

                        // Convert to Eastern Time
                        $date->setTimezone(new DateTimeZone('America/New_York'));

                        // Format the date as desired
                        $formattedDate = $date->format('F j, Y - g:i a');
                        echo $formattedDate; 
                        
                        ?></em>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <p>No posts yet.</p>
    <?php endif; ?>

</body>
</html>
