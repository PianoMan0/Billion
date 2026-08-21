<?php
require_once __DIR__ . '/lib.php';
secure_session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
        exit;
    }

    require_post_csrf();

    $db = new PDO('sqlite:' . __DIR__ . '/posts.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $user_id = (int)$_SESSION['user_id'];

    $UPLOAD_DIR = __DIR__ . '/uploads/';
    $UPLOAD_DB_PREFIX = 'uploads/';
    if (!is_dir($UPLOAD_DIR)) {
        @mkdir($UPLOAD_DIR, 0755, true);
    }

    $UPLOAD_HAS_FILETYPE = db_has_column($db, 'uploads', 'file_type');

    $maxBytes = 6 * 1024 * 1024; // 6MB GIFs

    if (empty($_FILES['gif']) || !is_uploaded_file($_FILES['gif']['tmp_name']) || $_FILES['gif']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'No GIF uploaded']);
        exit;
    }

    $gif = $_FILES['gif'];
    if (!empty($gif['size']) && $gif['size'] > $maxBytes) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'GIF too large']);
        exit;
    }

    // Basic magic-byte check for GIF: "GIF87a" or "GIF89a"
    $fp = fopen($gif['tmp_name'], 'rb');
    $sig = $fp ? fread($fp, 6) : '';
    if ($fp) fclose($fp);
    $sig = strtolower((string)$sig);
    $isGif = $sig === 'gif87a' || $sig === 'gif89a';
    if (!$isGif) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid GIF file']);
        exit;
    }

    // Store as .gif
    $filename = md5(uniqid((string)mt_rand(), true)) . '.gif';
    $fullPath = $UPLOAD_DIR . $filename;

    if (!move_uploaded_file($gif['tmp_name'], $fullPath)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Upload failed']);
        exit;
    }

    @chmod($fullPath, 0644);

    $storedPath = $UPLOAD_DB_PREFIX . $filename;

    // We store GIFs in uploads table WITHOUT a post_id for now.
    // The feed composer will attach them to the new post in a later step.
    // For now, we just respond with the storedPath; composer will use it.

    echo json_encode(['ok' => true, 'file' => $storedPath]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
    exit;
}

