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
        <title>Notifications</title>
    </head>
    <body>
        <h1>Notifications</h1>
        <p><a href="index.php">Back to feed</a></p>
        <form method="POST" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?php echo h(get_csrf_token()); ?>">
            <button type="submit" name="mark_all" value="1">Mark all read</button>
        </form>
        <ul>
        <?php foreach ($notes as $n): ?>
            <li style="<?php echo $n['is_read'] ? 'opacity:0.6' : ''; ?>">
                <strong><?php echo h($n['type']); ?></strong>
                <?php if (!empty($n['from_username'])) echo ' from ' . h($n['from_username']); ?>
                — <?php echo h($n['timestamp']); ?>
                <?php if (!$n['is_read']): ?>
                    <form method="POST" style="display:inline; margin-left:8px">
                        <input type="hidden" name="csrf_token" value="<?php echo h(get_csrf_token()); ?>">
                        <input type="hidden" name="mark_id" value="<?php echo (int)$n['id']; ?>">
                        <button type="submit">Mark read</button>
                    </form>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
        </ul>

    </body>
</html>