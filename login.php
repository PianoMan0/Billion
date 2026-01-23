<?php

// Copyright 2024-2026 PianoMan0

require_once __DIR__ . '/lib.php';
secure_session_start();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $dbPath = __DIR__ . '/posts.db';
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $username = trim((string)($_POST['username'] ?? ''));
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Please enter both username and password.';
    } else {
        // Fetch user by username and verify password (support hashed or plain for backwards compatibility)
        $stmt = $db->prepare("SELECT * FROM users WHERE username = :username LIMIT 1");
        $stmt->execute([':username' => $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $stored = (string)($user['password'] ?? '');
            $ok = false;
            if ($stored !== '' && password_verify($password, $stored)) {
                $ok = true;
            } elseif ($password === $stored) {
                // fallback to plain text comparison if DB has old plaintext passwords
                $ok = true;
            }

            if ($ok) {
                // regenerate session id to prevent fixation
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                // If password was stored in plaintext, re-hash it into a secure hash
                if (!password_needs_rehash($stored, PASSWORD_DEFAULT) && $stored === $password) {
                    $newHash = password_hash($password, PASSWORD_DEFAULT);
                    if ($newHash) {
                        $u = $db->prepare('UPDATE users SET password = :pw WHERE id = :id');
                        $u->execute([':pw' => $newHash, ':id' => (int)$user['id']]);
                    }
                }
                header('Location: index.php');
                exit;
            }
        }

        $error = 'Invalid username or password.';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta name='viewport' content='width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0, target-densityDpi=device-dpi, minimal-ui' />
    <meta charset="UTF-8">
    <meta name='viewport' content='width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0, target-densityDpi=device-dpi, minimal-ui' />
    <meta name="description" content="Join the Billion community to share and discover connection">
    <meta name="keywords" content="billion, social media, community, posts">
    <meta name="author" content="PianoMan0">
    <meta property="og:site_name" content="Billion" />
    <meta property="og:title" content="Billion - Login" />
    <meta property="og:description" content="Join the Billion community to share and discover connection" />
    <meta property="og:type" content="website" />
    <meta property="og:image" content="billion_small.png" />
    <link rel="icon" type="image/png" href="billion_small.png">
    <link rel="apple-touch-icon" sizes="180x180" href="billion_small.png">
    <title>Login</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <h1>Login</h1>
    <form action="login.php" method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo h(get_csrf_token()); ?>">
        <label for="username">Username:</label>
        <input type="text" id="username" name="username" required>
        <br>
        <label for="password">Password:</label>
        <input type="password" id="password" name="password" required>
        <br>
        <button type="submit">Login</button>
    </form>
    <?php if ($error): ?>
        <p style="color: red;"><?php echo $error; ?></p>
    <?php endif; ?>
</body>
</html>
