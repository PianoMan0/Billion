<?php
// Copyright 2024-2026 PianoMan0

require_once __DIR__ . '/lib.php';
secure_session_start();

// Require users to log in.
if (!isset($_SESSION['username']) || !isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

try {
    // Use absolute path for the SQLite file so paths are deterministic
    $dbPath = __DIR__ . '/posts.db';
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Enable foreign keys if schema relies on them
    $db->exec('PRAGMA foreign_keys = ON');
} catch (Exception $e) {
    // Fail early if DB cannot be opened
    http_response_code(500);
    echo 'Database error';
    exit;
}

// ensure defaults
$new_messages_count = 0;

// Utility: uploads directory (absolute on disk), store relative paths in DB
$UPLOAD_DIR = __DIR__ . '/uploads/';
$UPLOAD_DB_PREFIX = 'uploads/';

// Ensure uploads directory exists
if (!is_dir($UPLOAD_DIR)) {
    @mkdir($UPLOAD_DIR, 0755, true);
}

// Handle GET actions (likes, unlikes, delete, logout)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? null;
    $post_id = isset($_GET['post_id']) ? (int)$_GET['post_id'] : null;

    // Log out
    if (!empty($action) && $action === 'logout') {
        session_unset();
        session_destroy();
        header('Location: login.php');
        exit;
    }

    // Increase the like count for the specified post
    if (!empty($action) && $action === 'like' && !empty($post_id)) {
        $stmt = $db->prepare("INSERT OR IGNORE INTO likes (user_id, post_id) VALUES (:user_id, :post_id)");
        $stmt->bindValue(':user_id', (int)$_SESSION['user_id'], PDO::PARAM_INT);
        $stmt->bindValue(':post_id', $post_id, PDO::PARAM_INT);
        $stmt->execute();

        header('Location: index.php');
        exit;
    }

    // Decrease the like count for the specified post
    if (!empty($action) && $action === 'unlike' && !empty($post_id)) {
        $stmt = $db->prepare("DELETE FROM likes WHERE user_id = :user_id AND post_id = :post_id");
        $stmt->bindValue(':user_id', (int)$_SESSION['user_id'], PDO::PARAM_INT);
        $stmt->bindValue(':post_id', $post_id, PDO::PARAM_INT);
        $stmt->execute();

        header('Location: index.php');
        exit;
    }

    // Delete the specified post (only owner's allowed)
    if (!empty($action) && $action === 'delete' && !empty($post_id)) {
        try {
            // Verify owner before deleting anything
            $ownerStmt = $db->prepare("SELECT user_id FROM posts WHERE id = :post_id LIMIT 1");
            $ownerStmt->bindValue(':post_id', $post_id, PDO::PARAM_INT);
            $ownerStmt->execute();
            $owner = $ownerStmt->fetchColumn();
            if ((int)$owner !== (int)$_SESSION['user_id']) {
                header('Location: index.php');
                exit;
            }

            // Fetch uploads for the post first (so we can remove files)
            $stmt = $db->prepare("SELECT file_name FROM uploads WHERE post_id = :post_id");
            $stmt->bindValue(':post_id', $post_id, PDO::PARAM_INT);
            $stmt->execute();
            $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($files as $file) {
                $fileName = $file['file_name'] ?? '';
                if ($fileName === '') continue;

                // Build absolute path and ensure it is inside the uploads directory
                $candidate = realpath(__DIR__ . '/' . $fileName);
                if ($candidate && strpos($candidate, realpath($UPLOAD_DIR)) === 0 && file_exists($candidate)) {
                    @unlink($candidate);
                } else {
                    // as a fallback try basename (in case DB has just filename)
                    $candidate2 = $UPLOAD_DIR . basename($fileName);
                    if (file_exists($candidate2)) {
                        @unlink($candidate2);
                    }
                }
            }

            // Remove uploads rows (if table exists)
            $db->exec("DELETE FROM uploads WHERE post_id = " . (int)$post_id);

            // Remove post_tags rows (if table exists)
            try {
                $db->exec("DELETE FROM post_tags WHERE post_id = " . (int)$post_id);
            } catch (Exception $e) {
                // table may not exist in older DBs; ignore
            }

            // Remove the post itself
            $stmt = $db->prepare("DELETE FROM posts WHERE id = :post_id");
            $stmt->bindValue(':post_id', $post_id, PDO::PARAM_INT);
            $stmt->execute();

        } catch (Exception $e) {
            error_log('Delete post failed: ' . $e->getMessage());
            // avoid exposing details to users; redirect back
        }

        header('Location: index.php');
        exit;
    }

    // Vote on poll
    if (!empty($action) && $action === 'vote') {
        $poll_id = isset($_GET['poll_id']) ? (int)$_GET['poll_id'] : null;
        $option_id = isset($_GET['option_id']) ? (int)$_GET['option_id'] : null;
        if (!empty($poll_id) && !empty($option_id) && !empty($_SESSION['user_id'])) {
            // ensure option belongs to poll
            $s = $db->prepare('SELECT id FROM poll_options WHERE id = :option_id AND poll_id = :poll_id LIMIT 1');
            $s->execute([':option_id' => $option_id, ':poll_id' => $poll_id]);
            $ok = $s->fetchColumn();
            if ($ok) {
                $ins = $db->prepare('INSERT OR REPLACE INTO poll_votes (poll_id, option_id, user_id) VALUES (:poll_id, :option_id, :user_id)');
                $ins->execute([':poll_id' => $poll_id, ':option_id' => $option_id, ':user_id' => (int)$_SESSION['user_id']]);
            }
        }
        header('Location: index.php');
        exit;
    }
}

// Determine if new content has been submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    // Use session user id to avoid spoofing via hidden form field
    $user_id = (int)$_SESSION['user_id'];
    $content = trim($_POST['content'] ?? '');

    if (!empty($user_id) && $content !== '') {
        // Insert the new post into the database
        $stmt = $db->prepare("INSERT INTO posts (user_id, content) VALUES (:user_id, :content)");
        $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->bindValue(':content', $content, PDO::PARAM_STR);
        $stmt->execute();
        $post_id = (int)$db->lastInsertId();

        // parse @username mentions and insert into post_tags
        preg_match_all('/@([A-Za-z0-9_]+)/', $content, $matches);
        $mentioned = array_values(array_unique($matches[1] ?? []));
        if (count($mentioned) > 0) {
            $placeholders = implode(',', array_fill(0, count($mentioned), '?'));
            $stmtUsers = $db->prepare("SELECT id, username FROM users WHERE username IN ($placeholders)");
            $stmtUsers->execute($mentioned);
            $found = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);

            $stmtInsertTag = $db->prepare("INSERT OR IGNORE INTO post_tags (post_id, user_id) VALUES (:post_id, :user_id)");
            foreach ($found as $f) {
                $stmtInsertTag->bindValue(':post_id', $post_id, PDO::PARAM_INT);
                $stmtInsertTag->bindValue(':user_id', (int)$f['id'], PDO::PARAM_INT);
                $stmtInsertTag->execute();
            }
        }

        // Handle image upload (only if file actually uploaded)
        if (!empty($_FILES['image']) && is_uploaded_file($_FILES['image']['tmp_name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            // simple size limit
            if (!empty($_FILES['image']['size']) && $_FILES['image']['size'] > BILLION_MAX_IMAGE_BYTES) {
                // ignore oversized image
            } else {
            
            $image = $_FILES['image'];
            // Determine mime type; prefer fileinfo if available
            $mime = '';
            if (function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                if ($finfo !== false) {
                    $mime = finfo_file($finfo, $image['tmp_name']);
                    finfo_close($finfo);
                }
            }
            if ($mime === '') {
                $mime = $image['type'] ?? '';
            }

                if ($mime === 'image/jpeg' || $mime === 'image/pjpeg') {
                // ensure GD available
                if (function_exists('imagecreatefromjpeg')) {
                    $imageInfo = getimagesize($image['tmp_name']);
                    if ($imageInfo !== false) {
                        $sourceImage = imagecreatefromjpeg($image['tmp_name']);
                        if ($sourceImage !== false) {
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

                            $filename = md5(uniqid((string)rand(), true)) . '.jpg';
                            $fullPath = $UPLOAD_DIR . $filename;
                            if (imagejpeg($resizedImage, $fullPath, 85)) {
                                $storedPath = $UPLOAD_DB_PREFIX . $filename;
                                $stmt = $db->prepare("INSERT INTO uploads (post_id, file_name) VALUES (:post_id, :file_name)");
                                $stmt->bindValue(':post_id', $post_id, PDO::PARAM_INT);
                                $stmt->bindValue(':file_name', $storedPath, PDO::PARAM_STR);
                                $stmt->execute();
                            }
                            imagedestroy($sourceImage);
                            imagedestroy($resizedImage);
                        }
                    }
                }
            }
            }
        }

        // Handle audio upload (voice clip)
        if (!empty($_FILES['audio']) && is_uploaded_file($_FILES['audio']['tmp_name']) && $_FILES['audio']['error'] === UPLOAD_ERR_OK) {
            if (!empty($_FILES['audio']['size']) && $_FILES['audio']['size'] > BILLION_MAX_AUDIO_BYTES) {
                // ignore oversized audio
            } else {
            $audio = $_FILES['audio'];
            $mimeType = '';
            if (function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                if ($finfo !== false) {
                    $mimeType = finfo_file($finfo, $audio['tmp_name']);
                    finfo_close($finfo);
                }
            }
            if ($mimeType === '') {
                $mimeType = $audio['type'] ?? '';
            }

            $allowedTypes = ['audio/ogg', 'audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/x-wav', 'audio/webm', 'video/webm'];
            // Accept if it's an audio type or a webm (some browsers report video/webm)
            $accepted = false;
            if (stripos($mimeType, 'audio/') === 0) {
                $accepted = true;
            }
            if (stripos($mimeType, 'webm') !== false) {
                $accepted = true;
            }
            if (in_array($mimeType, $allowedTypes, true)) {
                $accepted = true;
            }

                if ($accepted) {
                // Try ffprobe for duration if available
                $duration = 0;
                $ffprobeAvailable = false;
                $probeCheck = @shell_exec('which ffprobe 2>/dev/null || where ffprobe 2>NUL');
                if (!empty($probeCheck)) {
                    $ffprobeAvailable = true;
                }
                if ($ffprobeAvailable) {
                    $ffmpegCmd = "ffprobe -i " . escapeshellarg($audio['tmp_name']) . " -show_entries format=duration -v quiet -of csv=\"p=0\" 2>&1";
                    $out = @shell_exec($ffmpegCmd);
                    $duration = floatval(trim($out));
                }
                // Accept if duration unknown or <= 30.5s
                    if ($duration <= 30.5) {
                    // Derive extension from mime where possible
                    $extMap = [
                        'audio/ogg' => 'ogg',
                        'audio/webm' => 'webm',
                        'video/webm' => 'webm',
                        'audio/mpeg' => 'mp3',
                        'audio/mp3' => 'mp3',
                        'audio/wav' => 'wav',
                        'audio/x-wav' => 'wav',
                    ];
                    $ext = strtolower(pathinfo($audio['name'], PATHINFO_EXTENSION));
                    if (empty($ext) || strlen($ext) > 6) {
                        $ext = $extMap[$mimeType] ?? '';
                    }
                    if ($ext === '') {
                        $ext = $extMap[$mimeType] ?? 'webm';
                    }
                    $ext = preg_replace('/[^a-z0-9]/', '', $ext);
                    $filename = md5(uniqid((string)rand(), true)) . '.' . $ext;
                    $fullPath = $UPLOAD_DIR . $filename;
                        if (move_uploaded_file($audio['tmp_name'], $fullPath)) {
                        $storedPath = $UPLOAD_DB_PREFIX . $filename;
                        $stmt = $db->prepare("INSERT INTO uploads (post_id, file_name) VALUES (:post_id, :file_name)");
                        $stmt->bindValue(':post_id', $post_id, PDO::PARAM_INT);
                        $stmt->bindValue(':file_name', $storedPath, PDO::PARAM_STR);
                        $stmt->execute();
                    }
                }
            }
            }
        }
    }

    // Handle poll creation (if requested)
    if (!empty($_POST['is_poll']) && !empty($_POST['options']) && is_array($_POST['options'])) {
        $question = trim($_POST['poll_question'] ?? '');
        if ($question !== '') {
            $stmtPoll = $db->prepare('INSERT INTO polls (post_id, user_id, question) VALUES (:post_id, :user_id, :question)');
            $stmtPoll->bindValue(':post_id', $post_id, PDO::PARAM_INT);
            $stmtPoll->bindValue(':user_id', $user_id, PDO::PARAM_INT);
            $stmtPoll->bindValue(':question', $question, PDO::PARAM_STR);
            $stmtPoll->execute();
            $poll_id = (int)$db->lastInsertId();
            $stmtOpt = $db->prepare('INSERT INTO poll_options (poll_id, option_text) VALUES (:poll_id, :option_text)');
            foreach ($_POST['options'] as $opt) {
                $opt = trim((string)$opt);
                if ($opt === '') continue;
                $stmtOpt->execute([':poll_id' => $poll_id, ':option_text' => $opt]);
            }
        }
    }

    header('Location: index.php');
    exit;
}

// Unread notifications count (DMs, etc.)
$stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND is_read = 0");
$stmt->bindValue(':user_id', (int)$_SESSION['user_id'], PDO::PARAM_INT);
$stmt->execute();
$new_messages_count = (int)$stmt->fetchColumn();

// Get a list of recent posts, with like counts and uploads
$stmt = $db->prepare("
    SELECT posts.id, posts.content, posts.timestamp, users.id AS user_id, users.username, 
    COUNT(DISTINCT likes.user_id) AS like_count,
    CASE WHEN COUNT(DISTINCT likes2.user_id) > 0 THEN 1 ELSE 0 END AS user_liked,
    GROUP_CONCAT(DISTINCT uploads.file_name) AS file_names
    FROM posts 
    JOIN users ON posts.user_id = users.id 
    LEFT JOIN likes ON likes.post_id = posts.id
    LEFT JOIN likes AS likes2 ON likes2.post_id = posts.id AND likes2.user_id = :user_id
    LEFT JOIN uploads ON posts.id = uploads.post_id
    GROUP BY posts.id, posts.content, posts.timestamp, users.id, users.username
    ORDER BY posts.timestamp DESC
");
$stmt->bindValue(':user_id', (int)$_SESSION['user_id'], PDO::PARAM_INT);
$stmt->execute();
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name='viewport' content='width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0, target-densityDpi=device-dpi, minimal-ui' />
    <meta name="description" content="Join the Billion community to share and discover connection">
    <meta name="keywords" content="billion, social media, community, posts">
    <meta name="author" content="PianoMan0">
    <meta property="og:site_name" content="Billion" />
    <meta property="og:title" content="Billion - User Posts Feed" />
    <meta property="og:description" content="Join the Billion community to share and discover connection" />
    <meta property="og:type" content="website" />
    <meta property="og:image" content="billion_small.png" />
    <link rel="icon" type="image/png" href="billion_small.png">
    <link rel="apple-touch-icon" sizes="180x180" href="billion_small.png">
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
            echo (int)$new_messages_count;
        } ?>
        <a href="messages.php">Messages</a> | <button id="theme-toggle">Toggle Dark Mode</button>
        <a href="index.php?action=logout">Logout</a>
    </div>

    <img id="logo" src="billion_small.png" height=100 style="margin-bottom:15px"><br>

    <form action="index.php" method="POST" enctype="multipart/form-data" id="postForm">
        <input type="hidden" name="csrf_token" value="<?php echo h(get_csrf_token()); ?>">
        <!-- server uses session user_id; don't trust client-supplied ids -->
        <textarea id="content" name="content" required placeholder="What's on your mind, <?= htmlspecialchars($_SESSION['username'], ENT_QUOTES, 'UTF-8'); ?>?"></textarea>
        <button type="button" id="startRecBtn">Record Voice</button>
        <button type="submit">Submit</button>

        <!-- Native file inputs hidden; server expects them on submit -->
        <input type="file" name="image" id="image" accept="image/jpeg" style="display:none">
        <input type="file" name="audio" id="audio" accept="audio/ogg, audio/mpeg, audio/wav, audio/x-wav, audio/webm" style="display:none">


    </form>

    <h2>Recent Posts <a href="#" title="Refresh page" onclick="location.reload();"><img src="reload.svg" height="20" alt="reload"></a></h2>
    <?php if (!empty($posts)): ?>
        <ul>
            <?php foreach ($posts as $post): ?>
                <li>
                    <div class="right">
                       <span style="margin-right: 6px"><?= (int)$post['like_count']; ?> Likes</span>
                       <?php if ($post['user_liked']) { ?><a href="index.php?action=unlike&post_id=<?= (int)$post['id']; ?>">Unlike</a><?php }
                       else { ?><a href="index.php?action=like&post_id=<?= (int)$post['id']; ?>">Like</a><?php } ?>
                    </div>
                    <div class="left">
                        <img src="<?= htmlspecialchars($UPLOAD_DB_PREFIX . 'profile_' . (int)$post['user_id'] . '.jpg'); ?>" onerror="this.onerror=null; this.src='uploads/placeholder-image.svg';" alt="avatar">
                    </div>
                    <div class="post-content">
                        <?php
                        // Render content safely and convert @username -> profile link for existing users
                        $escaped = htmlspecialchars($post['content'], ENT_QUOTES, 'UTF-8');
                        $rendered = preg_replace_callback('/@([A-Za-z0-9_]+)/', function($m) use ($db) {
                            $username = $m[1];
                            $s = $db->prepare('SELECT id FROM users WHERE username = :username LIMIT 1');
                            $s->execute([':username' => $username]);
                            $row = $s->fetch(PDO::FETCH_ASSOC);
                            if ($row) {
                                return '<a href="profile.php?id=' . intval($row['id']) . '">@' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . '</a>';
                            }
                            return '@' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
                        }, $escaped);

                        // Convert http/https links into safe clickable anchors
                        $rendered = preg_replace_callback('/\bhttps?:\/\/[^\s<]+/i', function($m) {
                            $urlEscaped = $m[0];
                            // decode any entities produced by htmlspecialchars earlier
                            $url = html_entity_decode($urlEscaped, ENT_QUOTES, 'UTF-8');

                            // Validate URL and allow only http/https
                            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                                return $urlEscaped;
                            }
                            $parts = parse_url($url);
                            $scheme = strtolower($parts['scheme'] ?? '');
                            if ($scheme !== 'http' && $scheme !== 'https') {
                                return $urlEscaped;
                            }

                            // Safe href and display text
                            $safeHref = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
                            $display = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');

                            // Shorten display for very long URLs
                            if (mb_strlen($display) > 60) {
                                $display = htmlspecialchars(mb_substr($url, 0, 57, 'UTF-8') . '...', ENT_QUOTES, 'UTF-8');
                            }

                            return '<a href="' . $safeHref . '" target="_blank" rel="noopener noreferrer">' . $display . '</a>';
                        }, $rendered);

                        echo nl2br($rendered);
                        ?>
                        <?php
                        // Show uploads (image/audio)
                        if (!empty($post['file_names'])) {
                            $files = explode(',', $post['file_names']);
                            foreach ($files as $file) {
                                $file = trim($file);
                                if ($file === '') continue;
                                // Only allow rendering files from the uploads directory; use basename to avoid traversal
                                $base = basename($file);
                                $safeUrl = htmlspecialchars($UPLOAD_DB_PREFIX . $base, ENT_QUOTES, 'UTF-8');
                                if (preg_match('/\.jpg$/i', $base)) {
                                    echo "<p><img src='" . $safeUrl . "' alt='post image'></p>";
                                } elseif (preg_match('/\.(ogg|mp3|wav|webm)$/i', $base)) {
                                    echo "<p><audio controls src='" . $safeUrl . "'></audio></p>";
                                }
                            }
                        }

                        
                        ?>
                    </div>
                    <div class="post-footer">
                        <strong>
                            <a href="profile.php?id=<?= (int)$post['user_id']; ?>"><?php echo htmlspecialchars($post['username'], ENT_QUOTES, 'UTF-8'); ?></a>
                            <?php if ($post['username'] === $_SESSION['username']) { ?>
                                <a href="index.php?action=delete&post_id=<?= (int)$post['id']; ?>" title="Delete post"> &#128465;</a>
                            <?php } ?>
                        </strong>
                        <em><?php
                        try {
                            $timestamp = $post['timestamp'] ?: 'now';
                            $date = new DateTime($timestamp, new DateTimeZone('UTC'));
                            $date->setTimezone(new DateTimeZone('America/New_York'));
                            $formattedDate = $date->format('F j, Y - g:i a');
                        } catch (Exception $e) {
                            $formattedDate = htmlspecialchars((string)$post['timestamp'], ENT_QUOTES, 'UTF-8');
                        }
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

(function(){
  let recorder = null;
  let chunks = [];
  const startBtn = document.getElementById('startRecBtn');
  if (!startBtn) return;

  function canRecord() {
    return !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia && window.MediaRecorder);
  }

  startBtn.addEventListener('click', async function() {
    if (!canRecord()) {
      alert('Recording not supported in this browser');
      return;
    }

    if (!recorder) {
      try {
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        recorder = new MediaRecorder(stream);
        chunks = [];
        recorder.ondataavailable = e => { if (e.data && e.data.size) chunks.push(e.data); };
        recorder.onstop = () => {
          const blob = new Blob(chunks, { type: chunks[0]?.type || 'audio/webm' });
          const filename = 'voice_' + Date.now() + (blob.type.includes('ogg') ? '.ogg' : '.webm');
          try {
            const file = new File([blob], filename, { type: blob.type });
            const dt = new DataTransfer();
            dt.items.add(file);
            // Attach to first audio input on page
            const audioInput = document.querySelector('input[type=file][name=audio]');
            if (audioInput) {
              audioInput.files = dt.files;
              alert('Voice attached. Submit the form to upload.');
            } else {
              alert('No audio input found to attach recording.');
            }
          } catch (err) {
            alert('Failed to attach recording: ' + err.message);
          }
          recorder = null;
          startBtn.textContent = 'Record Voice';
        };
        recorder.start();
        startBtn.textContent = 'Stop & Attach';
      } catch (err) {
        alert('Could not start recording: ' + err.message);
        recorder = null;
      }
    } else {
      // stop
      recorder.stop();
    }
  });
})();

</script>

</html>