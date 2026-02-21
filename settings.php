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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $notify_dm = !empty($_POST['notify_dm']) ? '1' : '0';
    $notify_mentions = !empty($_POST['notify_mentions']) ? '1' : '0';

    // Preferred: try update then insert if missing (more compatible across SQLite versions)
    $up = $db->prepare('UPDATE user_settings SET value = :value WHERE user_id = :user_id AND key = :key');
    $up->execute([':value' => $notify_dm, ':user_id' => $user_id, ':key' => 'notify_dm']);
    if ($up->rowCount() === 0) {
        $ins = $db->prepare('INSERT INTO user_settings (user_id, key, value) VALUES (:user_id, :key, :value)');
        $ins->execute([':user_id' => $user_id, ':key' => 'notify_dm', ':value' => $notify_dm]);
    }

    $up->execute([':value' => $notify_mentions, ':user_id' => $user_id, ':key' => 'notify_mentions']);
    if ($up->rowCount() === 0) {
        $ins = $db->prepare('INSERT INTO user_settings (user_id, key, value) VALUES (:user_id, :key, :value)');
        $ins->execute([':user_id' => $user_id, ':key' => 'notify_mentions', ':value' => $notify_mentions]);
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
<html><head><meta charset="utf-8"><title>Settings</title></head><body>
<h1>Settings</h1>
<p><a href="index.php">Back</a></p>
<?php if (!empty($saved)) echo '<p style="color:green">Saved.</p>'; ?>
<form method="POST">
    <input type="hidden" name="csrf_token" value="<?php echo h(get_csrf_token()); ?>">
    <label><input type="checkbox" name="notify_dm" value="1" <?php if (!empty($settings['notify_dm']) && $settings['notify_dm'] === '1') echo 'checked'; ?>> In-app notifications for new DMs</label><br>
    <label><input type="checkbox" name="notify_mentions" value="1" <?php if (!empty($settings['notify_mentions']) && $settings['notify_mentions'] === '1') echo 'checked'; ?>> In-app notifications for mentions</label><br>
    <button type="submit">Save</button>
</form>
</body></html>
