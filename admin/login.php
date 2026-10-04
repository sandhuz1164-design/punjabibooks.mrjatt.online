<?php
require_once __DIR__ . '/auth.php';

$error = '';

if (admin_logged_in()) {
    admin_redirect('index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Enter both a username and a password.';
    } elseif (admin_login($username, $password)) {
        admin_redirect('index.php');
    } else {
        $error = 'Incorrect username or password.';
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Log in &middot; Admin</title>
<link rel="stylesheet" href="admin.css">
</head>
<body class="centered">
<form class="login" method="post" action="login.php" autocomplete="off">
  <h1>Strand <em>Admin</em></h1>
  <?php if ($error !== ''): ?>
    <p class="flash err"><?= e($error) ?></p>
  <?php endif; ?>
  <?= csrf_field() ?>
  <label>Username
    <input type="text" name="username" required autofocus value="<?= e($_POST['username'] ?? '') ?>">
  </label>
  <label>Password
    <input type="password" name="password" required>
  </label>
  <button class="btn" type="submit">Log in</button>
</form>
</body>
</html>
