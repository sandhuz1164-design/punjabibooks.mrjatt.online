<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_layout.php';

admin_require();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'delete') {
    csrf_check();
    $pid = (string) ($_POST['pid'] ?? '');
    if ($pid === '') {
        flash('Missing book id.', 'err');
    } else {
        q('DELETE FROM products WHERE PID = ?', [$pid]);
        flash('Deleted ' . $pid . '. Rebuild to publish the change.');
    }
    admin_redirect('books.php');
}

$q       = trim((string) ($_GET['q'] ?? ''));
$cat     = (string) ($_GET['cat'] ?? '');
$sortKey = (string) ($_GET['sort'] ?? 'title');
$page    = max(1, (int) ($_GET['page'] ?? 1));
$per     = 25;

$where = [];
$params = [];

if ($q !== '') {
    $where[] = '(PID LIKE ? OR Title LIKE ? OR Author LIKE ?)';
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    array_push($params, $like, $like, $like);
}
if ($cat !== '') {
    $where[] = "REPLACE(LOWER(Category),' and ',' & ') = REPLACE(LOWER(?),' and ',' & ')";
    $params[] = $cat;
}
$sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sortSql = [
    'title'  => 'Title ASC',
    'price'  => 'Price DESC',
    'stock'  => 'Available ASC',
    'recent' => 'UpdatedAt IS NULL, UpdatedAt DESC',
][$sortKey] ?? 'Title ASC';

$total = (int) (one("SELECT COUNT(*) c FROM products $sqlWhere", $params)['c'] ?? 0);
$pages = max(1, (int) ceil($total / $per));
$page  = min($page, $pages);
$offset = ($page - 1) * $per;

$list = rows("SELECT PID, Title, Author, Price, Discount, Available, Category, UpdatedAt
              FROM products $sqlWhere ORDER BY $sortSql LIMIT $per OFFSET $offset", $params);

function page_url(array $over = []): string
{
    global $q, $cat, $sortKey, $page;
    return 'books.php?' . http_build_query(array_merge(
        ['q' => $q, 'cat' => $cat, 'sort' => $sortKey, 'page' => $page],
        $over
    ));
}

admin_header('Books', 'books');
flash_show();
?>
<h1>Books <span class="muted">(<?= $total ?>)</span></h1>

<form class="filters" method="get" action="books.php">
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search PID, title or author">
  <select name="cat">
    <option value="">All categories</option>
    <?php foreach (categories() as $c): ?>
      <option value="<?= e($c['label']) ?>"<?= $cat === $c['label'] ? ' selected' : '' ?>>
        <?= e($c['label']) ?> (<?= (int) $c['count'] ?>)
      </option>
    <?php endforeach; ?>
  </select>
  <select name="sort">
    <?php foreach (['title' => 'Title A-Z', 'price' => 'Price high-low', 'stock' => 'Stock low-high', 'recent' => 'Recently updated'] as $k => $lbl): ?>
      <option value="<?= e($k) ?>"<?= $sortKey === $k ? ' selected' : '' ?>><?= e($lbl) ?></option>
    <?php endforeach; ?>
  </select>
  <button class="btn" type="submit">Filter</button>
  <a class="btn ghost" href="books.php">Reset</a>
  <a class="btn" href="book-edit.php">+ Add book</a>
</form>

<table class="tbl">
  <thead>
    <tr><th>Cover</th><th>PID</th><th>Title</th><th>Author</th><th>Price</th><th>Stock</th><th>Category</th><th></th></tr>
  </thead>
  <tbody>
  <?php if (!$list): ?>
    <tr><td colspan="8" class="muted">No books match.</td></tr>
  <?php endif; ?>
  <?php foreach ($list as $r): ?>
    <?php $pid = (string) $r['PID']; ?>
    <tr>
      <td><img class="thumb" src="<?= e(PUBLIC_URL) ?>/assets/img/books/<?= e($pid) ?>.jpg" alt="" width="34" height="48" loading="lazy" onerror="this.style.visibility='hidden'"></td>
      <td><code><?= e($pid) ?></code></td>
      <td><?= e($r['Title']) ?></td>
      <td class="muted"><?= e($r['Author']) ?></td>
      <td>&#8377;<?= money($r['Price']) ?><?= (int) $r['Discount'] > 0 ? ' <span class="off">' . (int) $r['Discount'] . '%</span>' : '' ?></td>
      <td><?= (int) $r['Available'] ?></td>
      <td class="muted"><?= e(canon_category((string) $r['Category'])) ?></td>
      <td class="rowacts">
        <a class="btn sm" href="book-edit.php?pid=<?= e(urlencode($pid)) ?>">Edit</a>
        <a class="btn sm" href="<?= e(PUBLIC_URL) ?>/books/<?= e(book_slug($pid)) ?>.html" target="_blank" rel="noopener">View</a>
        <form method="post" action="books.php" class="inline"
              onsubmit="return confirm('Delete <?= e($pid) ?> permanently?')">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="delete">
          <input type="hidden" name="pid" value="<?= e($pid) ?>">
          <button class="btn sm danger" type="submit">Delete</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<?php if ($pages > 1): ?>
<nav class="pager">
  <?php if ($page > 1): ?>
    <a class="btn sm ghost" href="<?= e(page_url(['page' => $page - 1])) ?>">&larr; Prev</a>
  <?php endif; ?>
  <span class="muted">Page <?= $page ?> of <?= $pages ?></span>
  <?php if ($page < $pages): ?>
    <a class="btn sm ghost" href="<?= e(page_url(['page' => $page + 1])) ?>">Next &rarr;</a>
  <?php endif; ?>
</nav>
<?php endif; ?>
<?php admin_footer(); ?>
