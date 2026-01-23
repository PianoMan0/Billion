<?php
// Copyright 2024-2026 PianoMan0

require_once __DIR__ . '/lib.php';
secure_session_start();

// Require users to log in.
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Connect to the database
$dbPath = __DIR__ . '/posts.db';
$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$profile_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($profile_id <= 0) {
    echo "No user profile ID specified.";
    exit;
}

// Determine if new content has been submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $profileText = trim((string)($_POST['profile'] ?? ''));
    $from_user_id = isset($_POST['from_user_id']) ? (int)$_POST['from_user_id'] : 0;
    $to_user_id = isset($_POST['to_user_id']) ? (int)$_POST['to_user_id'] : 0;
    $message = trim((string)($_POST['message'] ?? ''));

    if ($profileText !== '' && isset($_SESSION['user_id'])) {
        // Update the user's profile
        $stmt = $db->prepare('UPDATE users SET profile = :profile WHERE id = :user_id');
        $stmt->execute([':profile' => $profileText, ':user_id' => (int)$_SESSION['user_id']]);
    }

    // Handle profile image upload
    if (!empty($_FILES['image']) && is_uploaded_file($_FILES['image']['tmp_name'])) {
        $UPLOAD_DIR = __DIR__ . '/uploads/';
        if (!is_dir($UPLOAD_DIR)) {
            @mkdir($UPLOAD_DIR, 0755, true);
        }

        $image = $_FILES['image'];
        if ($image['error'] === UPLOAD_ERR_OK) {
            if (!empty($image['size']) && $image['size'] > BILLION_MAX_IMAGE_BYTES) {
                // oversized; ignore
            } else {
            $imageInfo = getimagesize($image['tmp_name']);
            if ($imageInfo) {
                $mimeType = $imageInfo['mime'] ?? '';
                if ($mimeType === 'image/jpeg') {
                    $sourceImage = imagecreatefromjpeg($image['tmp_name']);
                    if ($sourceImage !== false) {
                        $originalWidth = $imageInfo[0];
                        $originalHeight = $imageInfo[1];
                        $maxSize = 60;
                        if ($originalWidth > $originalHeight) {
                            $newWidth = $maxSize;
                            $newHeight = intval($originalHeight * $maxSize / $originalWidth);
                        } else {
                            $newHeight = $maxSize;
                            $newWidth = intval($originalWidth * $maxSize / $originalHeight);
                        }
                        $resizedImage = imagecreatetruecolor($newWidth, $newHeight);
                        imagecopyresampled($resizedImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $originalWidth, $originalHeight);

                        $dest = $UPLOAD_DIR . 'profile_' . $profile_id . '.jpg';
                        if (!imagejpeg($resizedImage, $dest, 85)) {
                            error_log('imagejpeg failed');
                        }
                        imagedestroy($sourceImage);
                        imagedestroy($resizedImage);
                    }
                } else {
                    error_log('wrong mimetype for profile image');
                }
            } else {
                error_log('missing image info for profile image');
            }
            }
        }
    }

    if ($from_user_id > 0 && $to_user_id > 0 && $message !== '') {
        // Add a new direct message
        $stmt = $db->prepare('INSERT INTO messages (to_user_id, from_user_id, message) VALUES (:to_user_id, :from_user_id, :message)');
        $stmt->execute([':to_user_id' => $to_user_id, ':from_user_id' => $from_user_id, ':message' => $message]);
    }

    header('Location: profile.php?id=' . $profile_id);
    exit;
}

// Get the profile of the user
$stmt = $db->prepare('SELECT * FROM users WHERE id = :profile_id LIMIT 1');
$stmt->execute([':profile_id' => $profile_id]);
$profile = $stmt->fetch(PDO::FETCH_ASSOC);

$stmt = $db->prepare('SELECT timestamp FROM posts WHERE user_id = :profile_id ORDER BY timestamp DESC LIMIT 1');
$stmt->execute([':profile_id' => $profile_id]);
$last_post = $stmt->fetch(PDO::FETCH_ASSOC);

// Get direct messages between the logged-in user and the user whose profile is being viewed
$stmt = $db->prepare("SELECT messages.*, users.username AS from_username FROM messages JOIN users ON users.id = messages.from_user_id WHERE (to_user_id = :profile_id AND from_user_id = :user_id) OR (from_user_id = :profile_id AND to_user_id = :user_id) ORDER BY timestamp DESC");
$stmt->execute([':profile_id' => $profile_id, ':user_id' => (int)$_SESSION['user_id']]);
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name='viewport' content='width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0' />
    <meta name="description" content="Join the Billion community to share and discover connection">
    <meta name="keywords" content="billion, social media, community, posts">
    <meta name="author" content="PianoMan0">
    <title>Billion - Profile page for <?= htmlspecialchars($profile['username'] ?? ''); ?></title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>

    <div class="logout"><a href="index.php?action=logout">Logout</a></div>

    <a href="index.php"><img src="billion_small.png" height=100 style="margin-bottom:15px"></a><br>

    <?php if ($profile_id === (int)($_SESSION['user_id'] ?? 0)): ?>
    <form action="profile.php?id=<?= $profile_id; ?>" method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?php echo h(get_csrf_token()); ?>">
        <textarea style="min-height: 150px" id="profile" name="profile" placeholder="Add some profile text, <?= htmlspecialchars($_SESSION['username'] ?? ''); ?>!"><?= htmlspecialchars($profile['profile'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
        <br><input type="file" name="image" id="image" accept="image/jpeg"><br>
        <button type="submit">Submit</button>
    </form>
    <?php endif; ?>

    <img src="uploads/profile_<?= $profile_id; ?>.jpg" onerror="this.onerror=null; this.src='uploads/placeholder-image.svg';" alt="Profile Picture">

    <h2>Profile page for <?= htmlspecialchars($profile['username'] ?? '', ENT_QUOTES, 'UTF-8'); ?></h2>
    <?php if (!empty($profile['profile'])): ?>
        <?= nl2br(htmlspecialchars($profile['profile'], ENT_QUOTES, 'UTF-8')); ?>
    <?php else: ?>
        <p>No profile yet.</p>
    <?php endif; ?>
    <br><br>

    <?php if (!empty($last_post['timestamp'])): ?>
        <p>Date of last post: <?= htmlspecialchars($last_post['timestamp'], ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>

 <h2>Direct Messages <a href="#" title="Refresh page" onclick="location.reload();"><img src="reload.svg" height="20"></a></h2>

    <form action="profile.php?id=<?= $profile_id; ?>" method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo h(get_csrf_token()); ?>">
        <input type="hidden" id="to_user_id" name="to_user_id" value="<?= $profile_id; ?>">
        <input type="hidden" id="from_user_id" name="from_user_id" value="<?= (int)$_SESSION['user_id']; ?>">
        <textarea id="message" name="message" required placeholder="What's on your mind, <?= htmlspecialchars($_SESSION['username'] ?? ''); ?>?"></textarea>
        <br>
        <button type="submit">Submit</button>
    </form>

    <?php if (!empty($messages)): ?>
        <ul>
            <?php foreach ($messages as $message): ?>
                <li>
                    <div class="post-content">
                        <?= htmlspecialchars($message['message'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <div class="post-footer">
                        <strong>
                            <a href="profile.php?id=<?= (int)$message['from_user_id']; ?>"><?= htmlspecialchars($message['from_username'] ?? '', ENT_QUOTES, 'UTF-8'); ?></a>
                        </strong>
                        <em>(<?= htmlspecialchars($message['timestamp'] ?? '', ENT_QUOTES, 'UTF-8'); ?>)</em>
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
