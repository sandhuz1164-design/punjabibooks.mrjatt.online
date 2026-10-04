<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_layout.php';

admin_require();

$rebuildOutput = '';
$rebuildAt = (string) ($_SESSION['rebuild_at'] ?? '');
$startedAt = microtime(true);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'rebuild') {
    csrf_check();
    if (!is_file(BUILD_SCRIPT)) {
        flash('Build script not found.', 'err');
        admin_redirect('index.php');
    }
    $cmd = escapeshellcmd(PHP_BIN)
        . ' -c ' . escapeshellarg(EXT_INI)
        . ' -d ' . escapeshellarg('extension=' . PDO_EXT)
        . ' ' . escapeshellarg(BUILD_SCRIPT) . ' 2>&1';
    $rebuildOutput = (string) shell_exec($cmd);
    $_SESSION['rebuild_out'] = $rebuildOutput;
    $_SESSION['rebuild_at'] = date('Y-m-d H:i:s');
    flash($rebuildOutput === ''
        ? 'Rebuild finished with no output.'
        : 'Rebuild finished in ' . number_format(microtime(true) - $startedAt, 2) . 's.', 'ok');
    admin_redirect('index.php');
}

$totalBooks   = (int) (one('SELECT COUNT(*) c FROM products')['c'] ?? 0);
$totalAuthors = (int) (one('SELECT COUNT(DISTINCT Author) c FROM products')['c'] ?? 0);
$outOfStock   = (int) (one('SELECT COUNT(*) c FROM products WHERE Available <= 0')['c'] ?? 0);
$lowStock     = (int) (one('SELECT COUNT(*) c FROM products WHERE Available > 0 AND Available <= 5')['c'] ?? 0);
$totalCats    = count(categories());

$recent = rows('SELECT PID, Title, Author, Price, Available, UpdatedAt
                FROM products ORDER BY UpdatedAt IS NULL, UpdatedAt DESC, Title LIMIT 8');

$lastBuild = null;
$buildFile = __DIR__ . '/../public/index.html';
if (is_file($buildFile)) {
    $lastBuild = date('Y-m-d H:i', (int) filemtime($buildFile));
}

admin_header('Dashboard', 'dashboard');
flash_show();
?>
<h1>Dashboard</h1>

<div class="stats">
  <div class="stat"><b><?= $totalBooks ?></b><span>Books</span></div>
  <div class="stat"><b><?= $totalCats ?></b><span>Categories</span></div>
  <div class="stat"><b><?= $totalAuthors ?></b><span>Authors</span></div>
  <div class="stat"><b><?= $lowStock ?></b><span>Low stock (&le;5)</span></div>
  <div class="stat"><b><?= $outOfStock ?></b><span>Out of stock</span></div>
</div>

<div class="panel">
  <h2>Static site</h2>
  <p>The public site is pre-rendered. Database changes appear only after a rebuild.</p>
  <p>Last build: <b><?= $lastBuild ? e($lastBuild) : 'never' ?></b></p>
  <form method="post" action="index.php">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="rebuild">
    <button class="btn" type="submit">Rebuild site</button>
  </form>
</div>

<?php if (!empty($_SESSION['rebuild_out'])): ?>
<div class="panel">
  <h2>Last rebuild output<?= $rebuildAt ? ' <span class="muted">&middot; ' . e($rebuildAt) . '</span>' : '' ?></h2>
  <pre class="out"><?= e((string) $_SESSION['rebuild_out']) ?></pre>
</div>
<?php endif; ?>

<div class="panel">
  <h2>Recently updated</h2>
  <table class="tbl">
    <thead><tr><th>PID</th><th>Title</th><th>Author</th><th>Price</th><th>Stock</th><th>Updated</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($recent as $r): ?>
      <tr>
        <td><code><?= e($r['PID']) ?></code></td>
        <td><?= e($r['Title']) ?></td>
        <td><?= e($r['Author']) ?></td>
        <td>&#8377;<?= money($r['Price']) ?></td>
        <td><?= (int) $r['Available'] ?></td>
        <td><?= $r['UpdatedAt'] ? e(substr((string) $r['UpdatedAt'], 0, 16)) : '<span class="muted">&mdash;</span>' ?></td>
        <td><a class="btn sm" href="book-edit.php?pid=<?= e(urlencode((string) $r['PID'])) ?>">Edit</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php admin_footer(); ?>
