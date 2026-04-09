<?php
require __DIR__ . '/bootstrap.php';

if (!is_admin()) {
    http_response_code(403);
    exit('Forbidden');
}

$items = load_json(activity_file(), []);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Audit Log - <?= h(cfg('site_name')) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="app-shell">
    <header class="topbar">
        <div>
            <h1>Audit Log</h1>
            <div class="topbar-sub">Recent activity inside the app</div>
        </div>
        <div class="top-actions">
            <a class="ghost-btn" href="<?= h(cfg('admin_secret_slug')) ?>.php">Back to Admin</a>
        </div>
    </header>

    <section class="card">
        <div class="file-list">
            <?php if (!$items): ?>
                <div class="file-card"><div class="muted">No activity yet.</div></div>
            <?php else: ?>
                <?php foreach ($items as $item): ?>
                    <div class="file-card">
                        <div><strong><?= h(isset($item['text']) ? $item['text'] : '') ?></strong></div>
                        <div class="file-top-meta"><?= h(isset($item['time']) ? $item['time'] : '') ?></div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>
</div>
</body>
</html>