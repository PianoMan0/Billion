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

        // Also delete associated uploads (images and audio)
        $stmt = $db->prepare("SELECT file_name FROM uploads WHERE post_id = :post_id");
        $stmt->bindParam(':post_id', $post_id);
        $stmt->execute();
        $files = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($files as $file) {
            if (file_exists($file['file_name'])) {
                unlink($file['file_name']);
            }
        }
        $stmt = $db->prepare("DELETE FROM uploads WHERE post_id = :post_id");
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

        $uploadDir = 'uploads/';

        // Handle image upload
        if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
            $image = $_FILES['image'];
            if ($image['error'] === UPLOAD_ERR_OK) {
                $imageInfo = getimagesize($image['tmp_name']);
                if ($imageInfo && $imageInfo['mime'] === 'image/jpeg') {
                    $sourceImage = imagecreatefromjpeg($image['tmp_name']);
                    $originalWidth = $imageInfo[0];
                    $originalHeight = $imageInfo[1];
                    $maxSize = 480;
                    if ($originalWidth > $originalHeight) {
                        $newWidth = $maxSize;
                        $newHeight = intval($originalHeight * $maxSize / $originalWidth);
                    } else {
                        $newHeight = $maxSize;
                        $newWidth = intval($originalWidth * $maxSize / $originalHeight);
                    }
                    $resizedImage = imagecreatetruecolor($newWidth, $newHeight);
                    imagecopyresampled($resizedImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $originalWidth, $originalHeight);
                    $hashedFilename = $uploadDir . md5(uniqid(rand(), true)) . '.jpg';
                    if (imagejpeg($resizedImage, $hashedFilename, 85)) {
                        $stmt = $db->prepare("INSERT INTO uploads (post_id, file_name) VALUES (:post_id, :file_name)");
                        $stmt->bindParam(':post_id', $post_id);
                        $stmt->bindParam(':file_name', $hashedFilename);
                        $stmt->execute();
                    }
                    imagedestroy($sourceImage);
                    imagedestroy($resizedImage);
                }
            }
        }

        // Handle audio upload (voice clip)
        if (isset($_FILES['audio']) && $_FILES['audio']['error'] !== UPLOAD_ERR_NO_FILE) {
            $audio = $_FILES['audio'];
            if ($audio['error'] === UPLOAD_ERR_OK) {
                // Check MIME type and duration
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = finfo_file($finfo, $audio['tmp_name']);
                finfo_close($finfo);

                // Accept only OGG or MP3 or WAV files for voice clips
                $allowedTypes = ['audio/ogg', 'audio/mpeg', 'audio/wav', 'audio/x-wav', 'audio/webm'];
                if (in_array($mimeType, $allowedTypes)) {
                    // Check duration (up to ~30s)
                    // Use ffmpeg to get duration, fallback: store anyway if ffmpeg not available
                    $duration = 0;
                    $ffmpegExists = shell_exec('which ffprobe');
                    if ($ffmpegExists) {
                        $ffmpegCmd = "ffprobe -i " . escapeshellarg($audio['tmp_name']) . " -show_entries format=duration -v quiet -of csv=\"p=0\"";
                        $duration = floatval(trim(shell_exec($ffmpegCmd)));
                    }
                    if ($duration <= 30.5) {
                        $audioExt = pathinfo($audio['name'], PATHINFO_EXTENSION);
                        $audioFilename = $uploadDir . md5(uniqid(rand(), true)) . '.' . $audioExt;
                        if (move_uploaded_file($audio['tmp_name'], $audioFilename)) {
                            $stmt = $db->prepare("INSERT INTO uploads (post_id, file_name) VALUES (:post_id, :file_name)");
                            $stmt->bindParam(':post_id', $post_id);
                            $stmt->bindParam(':file_name', $audioFilename);
                            $stmt->execute();
                        }
                    } else {
                        // Optionally you could display an error: voice clip too long.
                        // Here we just ignore it.
                    }
                }
            }
        }
    }

    header('Location: index.php');
    exit;
}

if ($_COOKIE['last_visited']) {
    $last_visited = $_COOKIE['last_visited'];
    $_SESSION['last_visited'] = $last_visited;
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM messages
        WHERE (to_user_id = :profile_id)
        AND timestamp > DATETIME(:last_visited, 'unixepoch')
    ");
    $stmt->bindParam(':profile_id', $_SESSION['user_id']);
    $stmt->bindParam(':last_visited', $last_visited);
    $stmt->execute();
    $new_messages_count = $stmt->fetchColumn();
}

// Get a list of recent posts, along with their like counts and uploads (images/audio)
$stmt = $db->prepare("
    SELECT posts.id, posts.content, posts.timestamp, users.id AS user_id, users.username, 
    COUNT(likes.post_id) AS like_count, COUNT(likes2.post_id) AS user_liked, GROUP_CONCAT(uploads.file_name) AS file_names
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
    <style>
        .dark-mode #logo {
            filter: brightness(0) invert(1);
        }
        .dark-mode img[src="reload.svg"] {
            filter: brightness(0) invert(1);
        }
    </style>
</head>
<body>

    <div class="logout">
        <?php if ($new_messages_count > 0) {
            echo $new_messages_count;
        } ?>
        <a href="messages.php">Messages</a> | <button id="theme-toggle">Toggle Dark Mode</button>
        <a href="index.php?action=logout">Logout</a>
    </div>

    <img id="logo" src="billion_small.png" height=100 style="margin-bottom:15px"><br>

    <form action="index.php" method="POST" enctype="multipart/form-data">
        <input type="hidden" id="user_id" name="user_id" value="<?=$_SESSION['user_id'];?>">
        <textarea id="content" name="content" required placeholder="What's on your mind, <?=$_SESSION['username'];?>?"></textarea>
        <br>
        <label for="image">Image (JPG): </label>
        <input type="file" name="image" id="image" accept="image/jpeg"><br>
        <label for="audio">Voice Clip (max 30s, MP3/OGG/WAV): </label>
        <input type="file" name="audio" id="audio" accept="audio/ogg, audio/mpeg, audio/wav, audio/x-wav, audio/webm"><br>
        <button type="submit">Submit</button>
    </form>

    <h2>Recent Posts <a href="#" title="Refresh page" onclick="location.reload();"><img src="reload.svg" height="20"></a></h2>
    <?php if (!empty($posts)): ?>
        <ul>
            <?php foreach ($posts as $post): ?>
                <li>
                    <div class="right">
                       <span style="margin-right: 6px"><?=$post['like_count'];?> Likes</span>
                       <?php if ($post['user_liked']) { ?><a href="index.php?action=unlike&post_id=<?=$post['id'];?>">Unlike</a><?php }
                       else { ?><a href="index.php?action=like&post_id=<?=$post['id'];?>">Like</a><?php } ?>
                    </div>
                    <div class="left">
                        <img src="uploads/profile_<?= $post['user_id']; ?>.jpg" onerror="this.onerror=null; this.src='uploads/placeholder-image.svg';">
                    </div>
                    <div class="post-content">
                        <?php echo nl2br(htmlspecialchars($post['content'])); ?>
                        <?php
                        // Show uploads (image/audio)
                        if (!empty($post['file_names'])) {
                            $files = explode(',', $post['file_names']);
                            foreach ($files as $file) {
                                $file = trim($file);
                                if (preg_match('/\.jpg$/i', $file)) {
                                    echo "<p><img src='" . htmlspecialchars($file) . "'></p>";
                                } elseif (preg_match('/\.(ogg|mp3|wav|webm)$/i', $file)) {
                                    // Accept only audio types
                                    echo "<p><audio controls src='" . htmlspecialchars($file) . "'></audio></p>";
                                }
                            }
                        }
                        ?>
                    </div>
                    <div class="post-footer">
                        <strong>
                            <a href="profile.php?id=<?=$post['user_id'];?>"><?php echo htmlspecialchars($post['username']); ?></a>
                            <?php if ($post['username'] == $_SESSION['username']) { ?>
                                <a href="index.php?action=delete&post_id=<?=$post['id'];?>" title="Delete post"> &#128465;</a>
                            <?php } ?>
                        </strong>
                        <em><?php 
                        $date = new DateTime($post['timestamp'], new DateTimeZone('UTC'));
                        $date->setTimezone(new DateTimeZone('America/New_York'));
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
<script>
const toggleButton = document.getElementById('theme-toggle');

const savedTheme = localStorage.getItem('theme');
if (savedTheme) {
  document.body.classList.toggle('dark-mode', savedTheme === 'dark');
}

toggleButton.addEventListener('click', () => {
  const isDarkMode = document.body.classList.toggle('dark-mode');
  localStorage.setItem('theme', isDarkMode ? 'dark' : 'light');
});
</script>

</html>