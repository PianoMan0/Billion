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

// uploads dir
$UPLOAD_DIR = __DIR__ . '/uploads/';
$UPLOAD_DB_PREFIX = 'uploads/';
if (!is_dir($UPLOAD_DIR)) {
    @mkdir($UPLOAD_DIR, 0755, true);
}

// detect file_type column presence to stay compatible with older DBs
$UPLOAD_HAS_FILETYPE = false;
try { $UPLOAD_HAS_FILETYPE = db_has_column($db, 'uploads', 'file_type'); } catch (Exception $e) { $UPLOAD_HAS_FILETYPE = false; }

// Handle sending a new direct message with optional uploads
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $from_user = (int)$_SESSION['user_id'];
    $to_username = trim($_POST['to_username'] ?? '');
    $message_text = trim($_POST['message'] ?? '');

    if ($to_username !== '') {
        $s = $db->prepare('SELECT id FROM users WHERE username = :username LIMIT 1');
        $s->execute([':username' => $to_username]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $to_user = (int)$row['id'];
            $stmt = $db->prepare('INSERT INTO messages (to_user_id, from_user_id, message) VALUES (:to_user, :from_user, :message)');
            $stmt->execute([':to_user' => $to_user, ':from_user' => $from_user, ':message' => $message_text]);
            $message_id = (int)$db->lastInsertId();

            // Handle image upload
            if (!empty($_FILES['image']) && is_uploaded_file($_FILES['image']['tmp_name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
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
                                    @chmod($fullPath, 0644);
                                    $realFull = realpath($fullPath);
                                    if ($realFull && strpos($realFull, realpath($UPLOAD_DIR)) === 0 && file_exists($realFull)) {
                                        $storedPath = $UPLOAD_DB_PREFIX . $filename;
                                        if (!empty($UPLOAD_HAS_FILETYPE)) {
                                            $i = $db->prepare('INSERT INTO uploads (message_id, file_name, file_type) VALUES (:message_id, :file_name, :file_type)');
                                            $i->execute([':message_id' => $message_id, ':file_name' => $storedPath, ':file_type' => $mime]);
                                        } else {
                                            $i = $db->prepare('INSERT INTO uploads (message_id, file_name) VALUES (:message_id, :file_name)');
                                            $i->execute([':message_id' => $message_id, ':file_name' => $storedPath]);
                                        }
                                    } else {
                                        error_log('Message image upload failed or outside uploads dir: ' . $fullPath);
                                    }
                                }
                                imagedestroy($sourceImage);
                                imagedestroy($resizedImage);
                            }
                        }
                    }
                }
                }
            }

            // Handle audio upload
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
                        @chmod($fullPath, 0644);
                        $realFull = realpath($fullPath);
                        if ($realFull && strpos($realFull, realpath($UPLOAD_DIR)) === 0 && file_exists($realFull)) {
                            $storedPath = $UPLOAD_DB_PREFIX . $filename;
                            if (!empty($UPLOAD_HAS_FILETYPE)) {
                                $i = $db->prepare('INSERT INTO uploads (message_id, file_name, file_type) VALUES (:message_id, :file_name, :file_type)');
                                $i->execute([':message_id' => $message_id, ':file_name' => $storedPath, ':file_type' => $mimeType]);
                            } else {
                                $i = $db->prepare('INSERT INTO uploads (message_id, file_name) VALUES (:message_id, :file_name)');
                                $i->execute([':message_id' => $message_id, ':file_name' => $storedPath]);
                            }
                        } else {
                            error_log('Message audio upload failed or outside uploads dir: ' . $fullPath);
                        }
                    }
                }
                }
            }

            // Handle poll creation attached to this message
            if (!empty($_POST['is_poll']) && !empty($_POST['options']) && is_array($_POST['options'])) {
                $question = trim($_POST['poll_question'] ?? '');
                if ($question !== '') {
                    $p = $db->prepare('INSERT INTO polls (message_id, user_id, question) VALUES (:message_id, :user_id, :question)');
                    $p->execute([':message_id' => $message_id, ':user_id' => $from_user, ':question' => $question]);
                    $poll_id = (int)$db->lastInsertId();
                    $stmtOpt = $db->prepare('INSERT INTO poll_options (poll_id, option_text) VALUES (:poll_id, :option_text)');
                    foreach ($_POST['options'] as $opt) {
                        $opt = trim((string)$opt);
                        if ($opt === '') continue;
                        $stmtOpt->execute([':poll_id' => $poll_id, ':option_text' => $opt]);
                    }
                }
            }

            // Create a notification for recipient
            $n = $db->prepare('INSERT INTO notifications (user_id, from_user_id, type, ref_id) VALUES (:user_id, :from_user, :type, :ref)');
            $n->execute([':user_id' => $to_user, ':from_user' => $from_user, ':type' => 'dm', ':ref' => $message_id]);
        }
    }

    header('Location: messages.php');
    exit;
}

$profile_id = $_SESSION['user_id'];

// Get direct messages with attachments
$clear = $db->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = :user_id AND type = :type');
$clear->execute([':user_id' => $profile_id, ':type' => 'dm']);

$stmt = $db->prepare(" 
    SELECT messages.*, t1.username AS from_username, t2.username AS to_username,
    GROUP_CONCAT(DISTINCT uploads.file_name) AS file_names
    FROM messages
    JOIN users t1 ON t1.id = messages.from_user_id
    JOIN users t2 ON t2.id = messages.to_user_id
    LEFT JOIN uploads ON uploads.message_id = messages.id
    WHERE (to_user_id = :profile_id) OR (from_user_id = :profile_id)
    GROUP BY messages.id
    ORDER BY timestamp DESC
");
$stmt->bindValue(':profile_id', (int)$profile_id, PDO::PARAM_INT);
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
    <title>Billion - Messages</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>

    <?php $csrf_query = 'csrf_token=' . urlencode(get_csrf_token()); ?>
    <div class="logout"><a href="<?php echo h('index.php?action=logout&' . $csrf_query); ?>">Logout</a></div>

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
                        <?php echo nl2br(htmlspecialchars($message['message'])); ?>
                        <?php
                        if (!empty($message['file_names'])) {
                            $files = explode(',', $message['file_names']);
                            foreach ($files as $file) {
                                $file = trim($file);
                                if ($file === '') continue;
                                $base = basename($file);
                                $safeUrl = htmlspecialchars($UPLOAD_DB_PREFIX . $base, ENT_QUOTES, 'UTF-8');
                                if (preg_match('/\.jpg$/i', $base)) {
                                    echo "<p><img src='" . $safeUrl . "' alt='attachment' style='max-width:280px'></p>";
                                } elseif (preg_match('/\.(ogg|mp3|wav|webm)$/i', $base)) {
                                    echo "<p><audio controls src='" . $safeUrl . "'></audio></p>";
                                }
                            }
                        }
                        ?>
                        <?php
                        // Show poll if exists for this message
                        $pollStmt = $db->prepare('SELECT id, question FROM polls WHERE message_id = :message_id LIMIT 1');
                        $pollStmt->execute([':message_id' => (int)$message['id']]);
                        $poll = $pollStmt->fetch(PDO::FETCH_ASSOC);
                        if ($poll) {
                            $optStmt = $db->prepare('SELECT id, option_text, (SELECT COUNT(*) FROM poll_votes pv WHERE pv.option_id = poll_options.id) AS votes FROM poll_options WHERE poll_id = :poll_id');
                            $optStmt->execute([':poll_id' => (int)$poll['id']]);
                            $options = $optStmt->fetchAll(PDO::FETCH_ASSOC);

                            $userVoteStmt = $db->prepare('SELECT option_id FROM poll_votes WHERE poll_id = :poll_id AND user_id = :user_id LIMIT 1');
                            $userVoteStmt->execute([':poll_id' => (int)$poll['id'], ':user_id' => (int)$_SESSION['user_id']]);
                            $userVoted = (int)$userVoteStmt->fetchColumn();

                            echo '<div class="poll"><strong>' . htmlspecialchars($poll['question'], ENT_QUOTES, 'UTF-8') . '</strong><ul>';
                            foreach ($options as $opt) {
                                $optId = (int)$opt['id'];
                                $votes = (int)$opt['votes'];
                                if ($userVoted) {
                                    echo '<li>' . htmlspecialchars($opt['option_text'], ENT_QUOTES, 'UTF-8') . ' — ' . $votes . ' votes</li>';
                                } else {
                                    $voteUrl = 'index.php?action=vote&poll_id=' . (int)$poll['id'] . '&option_id=' . $optId . '&' . $csrf_query;
                                    echo '<li><a href="' . h($voteUrl) . '">' . htmlspecialchars($opt['option_text'], ENT_QUOTES, 'UTF-8') . '</a></li>';
                                }
                            }
                            echo '</ul></div>';
                        }
                        ?>
                    </div>
                    <div class="post-footer">
                        <strong>
                            <?php if ($message['from_user_id'] == $_SESSION['user_id']) { ?>
                            To <a href="profile.php?id=<?=$message['to_user_id'];?>"><?php echo htmlspecialchars($message['to_username']); ?></a>
                            <?php } else { ?>
                            From <a href="profile.php?id=<?=$message['from_user_id'];?>"><?php echo htmlspecialchars($message['from_username']); ?></a>
                            <?php } ?>
                        </strong>
                        <em>(<?php echo h($message['timestamp']); ?>)</em>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <p>No messages yet.</p>
    <?php endif; ?>

    <h3>Send a message</h3>
    <form action="messages.php" method="POST" enctype="multipart/form-data" id="dmForm">
        <input type="hidden" name="csrf_token" value="<?php echo h(get_csrf_token()); ?>">
        <label>To (username): <input type="text" name="to_username" required></label><br>
        <textarea name="message" placeholder="Write a message..."></textarea><br>
        <input type="file" name="image" accept="image/jpeg" id="dmImage" style="display:none">
        <input type="file" name="audio" accept="audio/ogg, audio/mpeg, audio/wav, audio/webm" id="dmAudio" style="display:none">
        <button type="button" onclick="document.getElementById('dmImage').click();">Attach Image</button>
        <button type="button" id="startRecBtn">Record Voice</button>
        <label style="margin-left:8px"><input type="checkbox" id="isPoll" name="is_poll" value="1"> Create poll</label>
        <div id="pollArea" style="display:none; margin-top:8px">
            <input type="text" name="poll_question" placeholder="Poll question" style="width:100%"><br>
            <div id="pollOptions">
                <input type="text" name="options[]" placeholder="Option 1"><br>
                <input type="text" name="options[]" placeholder="Option 2"><br>
            </div>
            <button type="button" id="addOptionBtn">Add option</button>
        </div>
        <button type="submit">Send</button>
    </form>

        <script>
        const dmIsPoll = document.getElementById('isPoll');
        const dmPollArea = document.getElementById('pollArea');
        const dmAddOption = document.getElementById('addOptionBtn');
        if (dmIsPoll) {
                dmIsPoll.addEventListener('change', () => {
                        dmPollArea.style.display = dmIsPoll.checked ? 'block' : 'none';
                });
        }
        if (dmAddOption) {
                dmAddOption.addEventListener('click', () => {
                        const div = document.getElementById('pollOptions');
                        const index = div.querySelectorAll('input[name="options[]"]').length + 1;
                        const input = document.createElement('input');
                        input.type = 'text';
                        input.name = 'options[]';
                        input.placeholder = 'Option ' + index;
                        div.appendChild(input);
                        div.appendChild(document.createElement('br'));
                });
        }

        // Inline recorder code (missing recorder.js previously). Attaches recording to #dmAudio
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
                                const audioInput = document.getElementById('dmAudio');
                                if (audioInput) {
                                    audioInput.files = dt.files;
                                    alert('Voice attached. Send message to upload.');
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
                    recorder.stop();
                }
            });
        })();
        </script>


    <div style="margin-top:50px"><a href="index.php">&#171; Back to News Feed</a></div>

</body>
</html>