<?php
require_once __DIR__ . '/lib.php';
secure_session_start();

$ADMIN_PASSWORD = 'GoDodgers!';

// DB connect
try {
    $db = new PDO('sqlite:' . __DIR__ . '/posts.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    echo 'Database error';
    exit;
}

// login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_password'])) {
    $pw = (string)($_POST['admin_password']);
    if (hash_equals($ADMIN_PASSWORD, $pw)) {
        $_SESSION['is_admin'] = 1;
    } else {
        $error = 'Incorrect password';
    }
}

// handle logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    unset($_SESSION['is_admin']);
    header('Location: index.php');
    exit;
}

if (empty($_SESSION['is_admin'])) {
    ?>
    <!doctype html>
    <html><head><meta charset="utf-8"><title>Admin Login</title></head><body>
    <h2>Admin Login</h2>
    <?php if (!empty($error)) echo '<p style="color:red">' . h($error) . '</p>'; ?>
    <form method="POST">
        <label>Password: <input type="password" name="admin_password"></label>
        <button type="submit">Login</button>
    </form>
    <p><a href="index.php">Back</a></p>
    </body></html>
    <?php
    exit;
}

$totalUsers = (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();
$totalPosts = (int)$db->query('SELECT COUNT(*) FROM posts')->fetchColumn();
$totalMessages = (int)$db->query('SELECT COUNT(*) FROM messages')->fetchColumn();
$totalUploads = (int)$db->query('SELECT COUNT(*) FROM uploads')->fetchColumn();
$totalLikes = (int)$db->query('SELECT COUNT(*) FROM likes')->fetchColumn();
$totalPolls = (int)$db->query('SELECT COUNT(*) FROM polls')->fetchColumn();

$top = $db->query('SELECT users.username, COUNT(posts.id) AS c FROM posts JOIN users ON users.id = posts.user_id GROUP BY users.id ORDER BY c DESC LIMIT 10')->fetchAll(PDO::FETCH_ASSOC);

// total storage in uploads dir (sum file sizes)
$storageBytes = 0;
$uploadRows = $db->query('SELECT file_name FROM uploads')->fetchAll(PDO::FETCH_ASSOC);
foreach ($uploadRows as $r) {
    $candidate = realpath(__DIR__ . '/' . ($r['file_name'] ?? ''));
    if ($candidate && strpos($candidate, realpath(__DIR__ . '/uploads/')) === 0 && file_exists($candidate)) {
        $storageBytes += filesize($candidate);
    }
}

?>
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Admin Dashboard</title></head>
<body>
<h1>Admin Dashboard</h1>
<p><a href="index.php">Back to site</a> | <a href="admin.php?action=logout">Logout</a></p>
<h2>Overview</h2>
<ul>
    <li>Total users: <?php echo $totalUsers; ?></li>
    <li>Total posts: <?php echo $totalPosts; ?></li>
    <li>Total messages: <?php echo $totalMessages; ?></li>
    <li>Total uploads: <?php echo $totalUploads; ?></li>
    <li>Total likes: <?php echo $totalLikes; ?></li>
    <li>Total polls: <?php echo $totalPolls; ?></li>
    <li>Storage used: <?php echo number_format($storageBytes); ?> bytes</li>
</ul>

<h2>Top Posters</h2>
<ol>
<?php foreach ($top as $t) { echo '<li>' . h($t['username']) . ' — ' . (int)$t['c'] . ' posts</li>'; } ?>
</ol>

<h2>Recent Posts</h2>
<ul>
<?php
$recent = $db->query('SELECT posts.id, posts.content, users.username, posts.timestamp FROM posts JOIN users ON users.id = posts.user_id ORDER BY posts.timestamp DESC LIMIT 20')->fetchAll(PDO::FETCH_ASSOC);
foreach ($recent as $r) {
    $preview = (function($s){ if (function_exists('mb_substr')) return mb_substr($s,0,200); return substr($s,0,200); })($r['content'] ?? '');
    echo '<li><strong>' . h($r['username']) . '</strong> @ ' . h($r['timestamp']) . ': ' . h($preview) . '</li>';
}
?>
</ul>

</body>
</html>

