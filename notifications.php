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

// handle marking read
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();

    if (!empty($_POST['mark_all'])) {
        $u = $db->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = :user_id');
        $u->execute([':user_id' => $user_id]);
    } elseif (!empty($_POST['mark_id'])) {
        $u = $db->prepare('UPDATE notifications SET is_read = 1 WHERE id = :id AND user_id = :user_id');
        $u->execute([':id' => (int)$_POST['mark_id'], ':user_id' => $user_id]);
    }

    header('Location: notifications.php');
    exit;
}

$stmt = $db->prepare('SELECT n.*, u.username AS from_username FROM notifications n LEFT JOIN users u ON u.id = n.from_user_id WHERE n.user_id = :user_id ORDER BY n.timestamp DESC');
$stmt->execute([':user_id' => $user_id]);
$notes = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0, target-densityDpi=device-dpi, minimal-ui" />
    <title>Notifications</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        /* Page-local clean social-ui styling */
        .notif-shell {
            max-width: 860px;
            margin: 0 auto;
        }

        .notif-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 10px;
        }

        .notif-header h2 {
            margin: 0;
            color: #1e90ff;
        }

        .notif-subtitle {
            margin: 0;
            font-size: 0.95em;
            opacity: 0.85;
        }

        .notif-toolbar {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .notif-actions {
            margin-top: 12px;
        }

        .notif-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .notif-item {
            background: rgba(230, 247, 255, 0.65);
            border: 1px solid #b0c4de;
            border-radius: 14px;
            margin-bottom: 10px;
            overflow: hidden;
        }

        .notif-item-inner {
            padding: 12px 14px;
            display: flex;
            gap: 12px;
            align-items: flex-start;
        }

        .notif-dot {
            width: 10px;
            height: 10px;
            border-radius: 999px;
            background: rgba(30, 144, 255, 0.95);
            flex: 0 0 auto;
            margin-top: 6px;
            box-shadow: 0 0 0 4px rgba(30, 144, 255, 0.12);
        }

        .notif-dot.read {
            background: transparent;
            box-shadow: none;
        }

        .notif-content {
            flex: 1 1 auto;
            min-width: 0;
        }

        .notif-type {
            font-weight: 800;
            color: #1e90ff;
            line-height: 1.25;
        }

        .notif-meta {
            margin-top: 3px;
            font-size: 0.92em;
            opacity: 0.85;
        }

        .notif-cta {
            margin-top: 8px;
            font-size: 0.95em;
        }

        .notif-cta a {
            color: #4682b4;
            font-weight: 700;
            text-decoration: none;
            border-bottom: 1px solid rgba(70, 130, 180, 0.35);
        }
        .notif-cta a:hover {
            border-bottom-color: rgba(70, 130, 180, 0.85);
        }

        .notif-read-btn {
            margin-top: 8px;
        }

        /* dark mode */
        body.dark-mode .notif-item,
        [data-theme="dark"] .notif-item {
            background: #0f1820;
            border: 1px solid #1f2a36;
        }

        body.dark-mode .notif-type,
        [data-theme="dark"] .notif-type {
            color: #90caf9;
        }

        body.dark-mode .notif-cta a,
        [data-theme="dark"] .notif-cta a {
            color: #90caf9;
            border-bottom-color: rgba(144, 202, 249, 0.35);
        }

        body.dark-mode .notif-cta a:hover,
        [data-theme="dark"] .notif-cta a:hover {
            border-bottom-color: rgba(144, 202, 249, 0.9);
        }
    </style>
</head>
<body>
<div class="notif-shell">

    <div class="notif-header">
        <div>
            <h2>Notifications</h2>
            <p class="notif-subtitle">Your recent activity</p>
        </div>

        <div class="notif-toolbar">
            <form method="POST" style="display:inline">
                <input type="hidden" name="csrf_token" value="<?php echo h(get_csrf_token()); ?>">
                <button type="submit" name="mark_all" value="1">Mark all read</button>
            </form>
        </div>

    </div>

    <p><a href="index.php">Back to feed</a></p>

    <ul class="notif-list">
        <?php foreach ($notes as $n): ?>
            <?php
                $type = (string)($n['type'] ?? '');
                $isRead = !empty($n['is_read']);
                $from = !empty($n['from_username']) ? (string)$n['from_username'] : '';
            ?>
            <li class="notif-item">
                <div class="notif-item-inner">
                    <span class="notif-dot <?php echo $isRead ? 'read' : ''; ?>" aria-hidden="true"></span>

                    <div class="notif-content">
                        <div class="notif-type"><?php echo h($type); ?></div>
                        <div class="notif-meta">
                            <?php if ($from !== ''): ?>
                                <?php
                                    $fromDisplay = h($from);
                                    // link to profile page
                                    $fromUserId = null;
                                    if (!empty($n['from_user_id'])) $fromUserId = (int)$n['from_user_id'];
                                    if ($fromUserId) {
                                        echo 'from <a href="profile.php?id=' . (int)$fromUserId . '">' . $fromDisplay . '</a> · ';
                                    } else {
                                        echo 'from ' . $fromDisplay . ' · ';
                                    }
                                ?>
                            <?php endif; ?>
                            <?php echo h($n['timestamp']); ?>
                        </div>


                        <div class="notif-cta">
                            <?php
                                if ($type === 'dm') {
                                    echo '<a href="messages.php">Open messages</a>';
                                } elseif (($type === 'like' || $type === 'mention') && !empty($n['ref_id'])) {
                                    echo '<a href="index.php">View feed</a>';
                                }
                            ?>
                        </div>

                        <?php if (!$isRead): ?>
                            <div class="notif-read-btn">
                                <form method="POST" style="display:inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo h(get_csrf_token()); ?>">
                                    <input type="hidden" name="mark_id" value="<?php echo (int)$n['id']; ?>">
                                    <button type="submit">Mark read</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
    <center><div style="margin-top:50px"><a href="index.php">&#171; Back to News Feed</a></div></center>
</div>
</body>
</html>

