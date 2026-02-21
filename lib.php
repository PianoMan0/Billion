<?php
// Common helpers for the Billion app
// - secure session start
// - CSRF token helpers
// - small utility wrappers

function secure_session_start() {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $cookieParams = session_get_cookie_params();
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => $cookieParams['lifetime'] ?? 0,
            'path' => $cookieParams['path'] ?? '/',
            'domain' => $cookieParams['domain'] ?? '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        session_set_cookie_params($cookieParams['lifetime'] ?? 0, $cookieParams['path'].'; samesite=Lax', $cookieParams['domain'] ?? '', $secure, true);
    }

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Simple inactivity timeout (1 hour)
    $timeout = 3600;
    if (isset($_SESSION['last_activity']) && (time() - (int)$_SESSION['last_activity'] > $timeout)) {
        session_unset();
        session_destroy();
        session_start();
    }
    $_SESSION['last_activity'] = time();
}

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function get_csrf_token() {
    if (!isset($_SESSION['csrf_token'])) {
        // try random_bytes, fall back to openssl, then to weaker uniqid
        if (function_exists('random_bytes')) {
            try {
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            } catch (Exception $e) {
                $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(32));
            }
        } elseif (function_exists('openssl_random_pseudo_bytes')) {
            $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(32));
        } else {
            $_SESSION['csrf_token'] = bin2hex(sha1(uniqid((string)mt_rand(), true)));
        }
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token($token) {
    if (!isset($_SESSION['csrf_token'])) return false;
    if (function_exists('hash_equals')) {
        return hash_equals((string)$_SESSION['csrf_token'], (string)$token);
    }
    return (string)$_SESSION['csrf_token'] === (string)$token;
}

function require_post_csrf() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';
        if (!verify_csrf_token((string)$token)) {
            http_response_code(400);
            echo 'Invalid CSRF token';
            exit;
        }
    }
}

function db_has_column($db, $table, $col) {
    try {
        if (!is_object($db)) return false;
        $stmt = $db->prepare("PRAGMA table_info(" . $table . ")");
        if ($stmt === false) return false;
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            if (isset($r['name']) && $r['name'] === $col) return true;
        }
    } catch (Exception $e) {
        // assume not present on error
    }
    return false;
}

// Basic constants for uploads
define('BILLION_MAX_IMAGE_BYTES', 3 * 1024 * 1024); // 3 MB
define('BILLION_MAX_AUDIO_BYTES', 6 * 1024 * 1024); // 6 MB

?>