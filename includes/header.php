<?php require_once __DIR__ . '/../db.php';
$page_title = $page_title ?? 'Strand Bookstore';
$nav_categories = categories();
$active = $active ?? '';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($page_title) ?> &middot; Strand Bookstore</title>
<meta name="description" content="Browse books by category or author, search the catalogue, and read full descriptions.">
<link rel="stylesheet" href="css/style.css">
</head>
<body>
<a class="skip" href="#main">Skip to content</a>

<header class="site-head">
  <div class="wrap head-inner">
    <a class="brand" href="index.php">
      <img src="img/logo.svg" alt="" width="34" height="34">
      <span>Strand<em>Bookstore</em></span>
    </a>

    <form class="search" action="search.php" method="get" role="search">
      <input type="search" name="q" placeholder="Search by title, author, publisher&hellip;"
             value="<?= e($_GET['q'] ?? '') ?>" aria-label="Search books">
      <button type="submit">Search</button>
    </form>

    <nav class="site-nav" aria-label="Main">
      <a href="index.php"<?= $active === 'home' ? ' class="on"' : '' ?>>Home</a>
      <div class="drop">
        <button type="button" aria-haspopup="true">Categories</button>
        <div class="drop-menu">
          <?php foreach ($nav_categories as $c): ?>
            <a href="browse.php?category=<?= e(urlencode($c['label'])) ?>">
              <?= e($c['label']) ?><span class="n"><?= $c['count'] ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
      <a href="author.php">Authors</a>
    </nav>
  </div>
</header>

<main id="main" class="wrap">
