<?php
require_once __DIR__ . '/auth.php';

function admin_header(string $title, string $active = ''): void
{
    $nav = [
        'dashboard' => ['Dashboard', 'index.php'],
        'books'     => ['Books', 'books.php'],
        'categories' => ['Categories', 'categories.php'],
    ];
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> &middot; Admin</title>
<link rel="stylesheet" href="admin.css">
</head>
<body>
<header class="bar">
  <a class="bar-brand" href="index.php">Strand <em>Admin</em></a>
  <nav>
    <?php foreach ($nav as $key => [$label, $href]): ?>
      <a href="<?= e($href) ?>"<?= $active === $key ? ' class="on"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <div class="bar-right">
    <a class="btn ghost sm" href="<?= e(PUBLIC_INDEX) ?>" target="_blank" rel="noopener">View site</a>
    <form method="post" action="logout.php" class="inline">
      <?= csrf_field() ?>
      <button class="btn ghost sm" type="submit">Log out</button>
    </form>
  </div>
</header>
<main class="page">
<?php
}

function admin_footer(): void
{
    ?>
</main>
</body>
</html>
<?php
}

function flash(string $msg, string $kind = 'ok'): void
{
    $_SESSION['flash'] = [$msg, $kind];
}

function flash_show(): void
{
    if (!empty($_SESSION['flash'])) {
        [$msg, $kind] = $_SESSION['flash'];
        unset($_SESSION['flash']);
        echo '<p class="flash ' . e($kind) . '">' . e($msg) . '</p>';
    }
}
