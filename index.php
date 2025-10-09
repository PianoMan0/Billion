<?php
// Copyright 2024-2025 PianoMan0

session_start();

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

        // Remove uploads rows
        $stmt = $db->prepare("DELETE FROM uploads WHERE post_id = :post_id");
        $stmt->bindValue(':post_id', $post_id, PDO::PARAM_INT);
        $stmt->execute();

        // Remove post_tags rows
        $stmt = $db->prepare("DELETE FROM post_tags WHERE post_id = :post_id");
        $stmt->bindValue(':post_id', $post_id, PDO::PARAM_INT);
        $stmt->execute();

        // Remove the post itself (only if owned by current user)
        $stmt = $db->prepare("DELETE FROM posts WHERE user_id = :user_id AND id = :post_id");
        $stmt->bindValue(':user_id', (int)$_SESSION['user_id'], PDO::PARAM_INT);
        $stmt->bindValue(':post_id', $post_id, PDO::PARAM_INT);
        $stmt->execute();

        header('Location: index.php');
        exit;
    }
}

// Determine if new content has been submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
            $image = $_FILES['image'];
            // Use finfo to validate mime as well
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $image['tmp_name']);
            finfo_close($finfo);

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

        // Handle audio upload (voice clip)
        if (!empty($_FILES['audio']) && is_uploaded_file($_FILES['audio']['tmp_name']) && $_FILES['audio']['error'] === UPLOAD_ERR_OK) {
            $audio = $_FILES['audio'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $audio['tmp_name']);
            finfo_close($finfo);

            $allowedTypes = ['audio/ogg', 'audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/x-wav', 'audio/webm'];
            if (in_array($mimeType, $allowedTypes, true)) {
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
                    $ext = strtolower(pathinfo($audio['name'], PATHINFO_EXTENSION)) ?: 'webm';
                    $filename = md5(uniqid((string)rand(), true)) . '.' . preg_replace('/[^a-z0-9]/', '', $ext);
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

    header('Location: index.php');
    exit;
}

// New messages count based on last_visited cookie
if (!empty($_COOKIE['last_visited'])) {
    $last_visited = (int)$_COOKIE['last_visited'];
    $_SESSION['last_visited'] = $last_visited;
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM messages
        WHERE (to_user_id = :profile_id)
        AND timestamp > DATETIME(:last_visited, 'unixepoch')
    ");
    $stmt->bindValue(':profile_id', (int)$_SESSION['user_id'], PDO::PARAM_INT);
    $stmt->bindValue(':last_visited', $last_visited, PDO::PARAM_INT);
    $stmt->execute();
    $new_messages_count = (int)$stmt->fetchColumn();
}

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
        <!-- server uses session user_id; don't trust client-supplied ids -->
        <textarea id="content" name="content" required placeholder="What's on your mind, <?= htmlspecialchars($_SESSION['username'], ENT_QUOTES, 'UTF-8'); ?>?"></textarea>

        <div class="capture-row">
            <div class="capture-panel">
                <label>Photo</label>
                <div class="camera-wrapper">
                    <video id="cameraVideo" autoplay playsinline muted></video>
                    <canvas id="photoCanvas" style="display:none"></canvas>
                    <div class="photo-preview" id="photoPreview"></div>
                </div>
                <div class="capture-actions">
                    <button type="button" id="start-camera">Open Camera</button>
                    <button type="button" id="take-photo" disabled>Capture</button>
                    <button type="button" id="retake-photo" style="display:none">Retake</button>
                    <button type="button" id="close-camera" style="display:none">Close</button>
                </div>
            </div>

          </div>

        <!-- Native file inputs hidden; server expects them on submit -->
        <input type="file" name="image" id="image" accept="image/jpeg" style="display:none">
        <input type="file" name="audio" id="audio" accept="audio/ogg, audio/mpeg, audio/wav, audio/x-wav, audio/webm" style="display:none">

        <button type="submit">Submit</button>
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
                        echo nl2br($rendered);
                        ?>
                        <?php
                        // Show uploads (image/audio)
                        if (!empty($post['file_names'])) {
                            $files = explode(',', $post['file_names']);
                            foreach ($files as $file) {
                                $file = trim($file);
                                if ($file === '') continue;
                                $safeUrl = htmlspecialchars($file, ENT_QUOTES, 'UTF-8');
                                if (preg_match('/\.jpg$/i', $file)) {
                                    echo "<p><img src='" . $safeUrl . "' alt='post image'></p>";
                                } elseif (preg_match('/\.(ogg|mp3|wav|webm)$/i', $file)) {
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
</script>

<script>
// Media capture logic: photo and audio (unchanged except defensive checks)
(function(){
    // Photo capture
    const startCameraBtn = document.getElementById('start-camera');
    const takePhotoBtn = document.getElementById('take-photo');
    const retakePhotoBtn = document.getElementById('retake-photo');
    const closeCameraBtn = document.getElementById('close-camera');
    const video = document.getElementById('cameraVideo');
    const canvas = document.getElementById('photoCanvas');
    const photoPreview = document.getElementById('photoPreview');
    const hiddenImageInput = document.getElementById('image');
    let stream = null;

    async function openCamera(){
        try{
            stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false });
            if (video) {
                video.srcObject = stream;
            }
            if (takePhotoBtn) takePhotoBtn.disabled = false;
            if (closeCameraBtn) closeCameraBtn.style.display = 'inline-block';
            if (startCameraBtn) startCameraBtn.style.display = 'none';
        }catch(err){
            alert('Camera access denied or not available.');
        }
    }

    function stopCamera(){
        if(stream){
            stream.getTracks().forEach(t => t.stop());
            stream = null;
        }
        if (video) video.srcObject = null;
        if (takePhotoBtn) takePhotoBtn.disabled = true;
        if (closeCameraBtn) closeCameraBtn.style.display = 'none';
        if (startCameraBtn) startCameraBtn.style.display = 'inline-block';
    }

    function capturePhoto(){
        if (!video || !canvas) return;
        const w = video.videoWidth;
        const h = video.videoHeight;
        if(!w || !h) return;
        canvas.width = w;
        canvas.height = h;
        const ctx = canvas.getContext('2d');
        ctx.drawImage(video, 0, 0, w, h);

        canvas.toBlob(function(blob){
            if (!blob) return;
            const url = URL.createObjectURL(blob);
            if (photoPreview) {
                photoPreview.innerHTML = '';
                const img = document.createElement('img');
                img.src = url;
                photoPreview.appendChild(img);
            }

            // write to hidden file input using DataTransfer
            try {
                const file = new File([blob], 'capture.jpg', { type: 'image/jpeg' });
                const dt = new DataTransfer();
                dt.items.add(file);
                if (hiddenImageInput) hiddenImageInput.files = dt.files;
            } catch(e) {
                // older browsers: ignore
            }

            if (retakePhotoBtn) retakePhotoBtn.style.display = 'inline-block';
            if (takePhotoBtn) takePhotoBtn.style.display = 'none';
        }, 'image/jpeg', 0.85);
    }

    if (startCameraBtn) startCameraBtn.addEventListener('click', openCamera);
    if (closeCameraBtn) closeCameraBtn.addEventListener('click', stopCamera);
    if (takePhotoBtn) takePhotoBtn.addEventListener('click', capturePhoto);
    if (retakePhotoBtn) retakePhotoBtn.addEventListener('click', ()=>{
        if (photoPreview) photoPreview.innerHTML = '';
        if (retakePhotoBtn) retakePhotoBtn.style.display = 'none';
        if (takePhotoBtn) takePhotoBtn.style.display = 'inline-block';
        // clear file input
        if (hiddenImageInput) hiddenImageInput.value = '';
    });

    // Audio recording — initialize only if elements exist
    const startRecordBtn = document.getElementById('start-record');
    const stopRecordBtn = document.getElementById('stop-record');
    const audioPreview = document.getElementById('audioPreview');
    const hiddenAudioInput = document.getElementById('audio');
    const recordTimer = document.getElementById('record-timer');

    let mediaRecorder = null;
    let audioChunks = [];
    let recordInterval = null;
    let seconds = 0;
    const MAX_SECONDS = 30;

    function formatTime(s){
        return String(Math.floor(s/60)).padStart(2,'0') + ':' + String(s%60).padStart(2,'0');
    }

    async function startRecording(){
        try{
            const s = await navigator.mediaDevices.getUserMedia({ audio: true, video: false });
            mediaRecorder = new MediaRecorder(s);
            audioChunks = [];
            mediaRecorder.ondataavailable = e => { if(e.data && e.data.size>0) audioChunks.push(e.data); };
            mediaRecorder.onstop = () => {
                if (audioChunks.length === 0) {
                    s.getTracks().forEach(t=>t.stop());
                    return;
                }
                const blob = new Blob(audioChunks, { type: audioChunks[0]?.type || 'audio/webm' });
                const url = URL.createObjectURL(blob);
                if (audioPreview) {
                    audioPreview.innerHTML = '';
                    const audioEl = document.createElement('audio');
                    audioEl.controls = true;
                    audioEl.src = url;
                    audioPreview.appendChild(audioEl);
                }

                // write to hidden file input
                try {
                    const ext = blob.type.includes('mpeg') ? 'mp3' : (blob.type.includes('wav') ? 'wav' : 'webm');
                    const file = new File([blob], 'record.'+ext, { type: blob.type });
                    const dt = new DataTransfer();
                    dt.items.add(file);
                    if (hiddenAudioInput) hiddenAudioInput.files = dt.files;
                } catch(e) {
                    // ignore on unsupported browsers
                }

                // stop all audio tracks
                s.getTracks().forEach(t=>t.stop());
            };
            mediaRecorder.start();
            if (startRecordBtn) startRecordBtn.disabled = true;
            if (stopRecordBtn) stopRecordBtn.disabled = false;
            seconds = 0;
            if (recordTimer) recordTimer.textContent = formatTime(seconds);
            recordInterval = setInterval(()=>{
                seconds++;
                if (recordTimer) recordTimer.textContent = formatTime(seconds);
                if(seconds >= MAX_SECONDS){
                    stopRecording();
                }
            }, 1000);
        }catch(err){
            alert('Microphone access denied or not available.');
        }
    }

    function stopRecording(){
        if(mediaRecorder && mediaRecorder.state !== 'inactive'){
            mediaRecorder.stop();
        }
        if(recordInterval){ clearInterval(recordInterval); recordInterval = null; }
        if (startRecordBtn) startRecordBtn.disabled = false;
        if (stopRecordBtn) stopRecordBtn.disabled = true;
    }

    if (startRecordBtn) startRecordBtn.addEventListener('click', startRecording);
    if (stopRecordBtn) stopRecordBtn.addEventListener('click', stopRecording);

    // Before submitting the form, ensure any active streams are stopped
    const postForm = document.getElementById('postForm');
    if (postForm) {
        postForm.addEventListener('submit', ()=>{
            stopCamera();
            // if recording, stop and let onstop handler attach file
            if(mediaRecorder && mediaRecorder.state === 'recording'){
                mediaRecorder.stop();
            }
        });
    }

})();
</script>

</html>