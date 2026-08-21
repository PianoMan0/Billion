<?php
require_once __DIR__ . '/lib.php';
secure_session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

try {
    $db = new PDO('sqlite:' . __DIR__ . '/posts.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    echo 'Database error';
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$saved = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $notify_dm = !empty($_POST['notify_dm']) ? '1' : '0';
    $notify_mentions = !empty($_POST['notify_mentions']) ? '1' : '0';

    $settings_to_save = [
        'notify_dm' => $notify_dm,
        'notify_mentions' => $notify_mentions
    ];

    foreach ($settings_to_save as $key => $value) {
        $up = $db->prepare('UPDATE user_settings SET value = :value WHERE user_id = :user_id AND key = :key');
        $up->execute([':value' => $value, ':user_id' => $user_id, ':key' => $key]);
        if ($up->rowCount() === 0) {
            $ins = $db->prepare('INSERT INTO user_settings (user_id, key, value) VALUES (:user_id, :key, :value)');
            $ins->execute([':user_id' => $user_id, ':key' => $key, ':value' => $value]);
        }
    }
    $saved = true;
}

$get = $db->prepare('SELECT key, value FROM user_settings WHERE user_id = :user_id');
$get->execute([':user_id' => $user_id]);
$rows = $get->fetchAll(PDO::FETCH_ASSOC);
$settings = [];
foreach ($rows as $r) $settings[$r['key']] = $r['value'];

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Settings</title>
    <style>
        /* Base Styles & Theme Variables */
        :root {
            --bg-color: #f0f8ff;
            --card-bg: #e6f7ff;
            --text-color: #333;
            --primary-color: #1e90ff;
            --secondary-color: #4682b4;
            --border-color: #b0c4de;
            --success-color: #2e7d32;
            --input-bg: #ffffff;
        }

        [data-theme="dark"], body.dark-mode {
            --bg-color: #121212;
            --card-bg: #1e1e1e;
            --text-color: #e6e6e6;
            --primary-color: #90caf9;
            --secondary-color: #b3e5fc;
            --border-color: #333;
            --input-bg: #2c2c2c;
        }

        * { box-sizing: border-box; }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--bg-color);
            color: var(--text-color);
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            min-height: 100vh;
        }

        .container {
            width: 100%;
            max-width: 500px;
            margin-top: 50px;
            padding: 20px;
        }

        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        h1 {
            color: var(--primary-color);
            margin: 0;
            font-size: 1.8rem;
        }

        .back-link {
            text-decoration: none;
            color: var(--secondary-color);
            font-weight: bold;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
        }

        .back-link:hover { text-decoration: underline; }

        /* Card Styling */
        .settings-card {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }

        .alert {
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 0.9rem;
            text-align: center;
            background-color: rgba(46, 125, 50, 0.1);
            color: var(--success-color);
            border: 1px solid var(--success-color);
        }

        /* Form Elements */
        .setting-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 15px 0;
            border-bottom: 1px solid var(--border-color);
        }

        .setting-row:last-of-type { border-bottom: none; }

        .setting-info {
            flex: 1;
            padding-right: 15px;
        }

        .setting-label {
            display: block;
            font-weight: 600;
            color: var(--secondary-color);
            margin-bottom: 4px;
        }

        .setting-description {
            font-size: 0.85rem;
            opacity: 0.8;
        }

        /* Custom Checkbox/Toggle Look */
        input[type="checkbox"] {
            width: 20px;
            height: 20px;
            cursor: pointer;
            accent-color: var(--primary-color);
        }

        .save-btn {
            width: 100%;
            background-color: var(--secondary-color);
            color: white;
            padding: 12px;
            border: none;
            border-radius: 6px;
            font-size: 1rem;
            font-weight: bold;
            cursor: pointer;
            margin-top: 20px;
            transition: background 0.2s;
        }

        .save-btn:hover {
            background-color: var(--primary-color);
        }

        /* Responsive */
        @media (max-width: 480px) {
            .container { margin-top: 20px; }
            .settings-card { padding: 20px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>Settings</h1>
            <a href="index.php" class="back-link">← Back</a>
        </header>

        <div class="settings-card">
            <?php if ($saved): ?>
                <div class="alert">✓ Settings saved successfully.</div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo h(get_csrf_token()); ?>">
                
                <div class="setting-row">
                    <div class="setting-info">
                        <label class="setting-label" for="notify_dm">Direct Messages</label>
                        <span class="setting-description">Receive in-app notifications for new DMs.</span>
                    </div>
                    <input type="checkbox" id="notify_dm" name="notify_dm" value="1" 
                        <?php if (!empty($settings['notify_dm']) && $settings['notify_dm'] === '1') echo 'checked'; ?>>
                </div>

                <div class="setting-row">
                    <div class="setting-info">
                        <label class="setting-label" for="notify_mentions">Mentions</label>
                        <span class="setting-description">Receive in-app notifications when someone mentions you.</span>
                    </div>
                    <input type="checkbox" id="notify_mentions" name="notify_mentions" value="1" 
                        <?php if (!empty($settings['notify_mentions']) && $settings['notify_mentions'] === '1') echo 'checked'; ?>>
                </div>

                <button type="submit" class="save-btn">Save Changes</button>
            </form>
        </div>
    </div>
</body>
</html>