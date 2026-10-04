<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_layout.php';

admin_require();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'rename') {
    csrf_check();
    $from = canon_category((string) ($_POST['from'] ?? ''));
    $to   = canon_category(trim((string) ($_POST['to'] ?? '')));

    if ($from === '' || $to === '') {
        flash('Category name cannot be empty.', 'err');
    } elseif ($from === $to) {
        flash('"' . $to . '" is already the stored name for that category.', 'err');
    } else {
        $n = 0;
        foreach (rows('SELECT PID, Category FROM products') as $r) {
            if (canon_category((string) $r['Category']) === $from) {
                q('UPDATE products SET Category = ? WHERE PID = ?', [$to, $r['PID']]);
                $n++;
            }
        }
        flash('Renamed "' . $from . '" to "' . $to . '" on ' . $n . ' books. Rebuild to publish. '
            . 'The category URL changes to /browse/' . cat_slug($to) . '/.', 'ok');
    }
    admin_redirect('categories.php');
}

$cats = categories();
$authors = rows('SELECT Author AS name, COUNT(*) AS count FROM products
                 GROUP BY Author ORDER BY count DESC, Author');

admin_header('Categories', 'categories');
flash_show();
?>
<h1>Categories <span class="muted">(<?= count($cats) ?>)</span></h1>
<p class="muted">Renaming a category rewrites it on every book and changes its page URL.</p>

<table class="tbl">
  <thead><tr><th>Category</th><th>Books</th><th>Slug</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($cats as $c): ?>
    <tr>
      <td><?= e($c['label']) ?></td>
      <td><?= (int) $c['count'] ?></td>
      <td><code>/browse/<?= e(cat_slug($c['label'])) ?>/</code></td>
      <td>
        <form method="post" action="categories.php" class="inline rename">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="rename">
          <input type="hidden" name="from" value="<?= e($c['label']) ?>">
          <input name="to" value="<?= e($c['label']) ?>" aria-label="New name for <?= e($c['label']) ?>">
          <button class="btn sm" type="submit">Rename</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<h1>Authors <span class="muted">(<?= count($authors) ?>)</span></h1>
<table class="tbl">
  <thead><tr><th>Author</th><th>Books</th><th>Slug</th></tr></thead>
  <tbody>
  <?php foreach ($authors as $a): ?>
    <tr>
      <td><?= e($a['name']) ?></td>
      <td><?= (int) $a['count'] ?></td>
      <td><code>/authors/<?= e(author_slug($a['name'])) ?>/</code></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php admin_footer(); ?>