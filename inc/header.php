<?php
/** @var string $pageTitle */
$u = current_user();
$active = $active ?? '';
$bodyClass = $bodyClass ?? ($u ? 'logged' : 'guest');
$scanUrl = $u ? (is_admin($u) ? 'scan.php' : 'scan.php?card=' . (int)$u['card_id']) : '';
?><!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#ffffff">
<meta name="robots" content="noindex, nofollow">
<meta name="apple-mobile-web-app-capable" content="yes">
<title><?= e(($pageTitle ?? '') ? $pageTitle . ' · ' . APP_NAME : APP_NAME) ?></title>
<link rel="icon" href="<?= e(url('assets/logo.png')) ?>">
<link rel="apple-touch-icon" href="<?= e(url('assets/logo.png')) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600;12..96,700;12..96,800&family=Figtree:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="<?= e(url('assets/style.css')) ?>?v=2">
</head>
<body class="<?= e($bodyClass) ?>">
<?php if ($u): ?>
<header class="topbar">
  <div class="topbar-in">
    <a class="brand" href="<?= e(url('dashboard.php')) ?>"><span class="brand-mark"><?= icon('check', 20) ?></span><?= e(APP_NAME) ?></a>
    <nav class="desk-nav" aria-label="Menu">
      <a href="<?= e(url('dashboard.php')) ?>" class="<?= $active === 'home' ? 'on' : '' ?>"><?= icon('home', 18) ?>Home</a>
      <a href="<?= e(url($scanUrl)) ?>" class="scan"><?= icon('camera', 18) ?>Scansiona</a>
      <a href="<?= e(url('account.php')) ?>" class="<?= $active === 'account' ? 'on' : '' ?>"><?= icon('user', 18) ?>Account</a>
      <a href="<?= e(url('logout.php')) ?>"><?= icon('logout', 18) ?>Esci</a>
    </nav>
    <div class="who"><b><?= e($u['full_name'] ?: $u['username']) ?></b><?= is_admin($u) ? 'Amministratore' : 'Gestore' ?></div>
  </div>
</header>
<?php endif; ?>
<main class="wrap">
<?php foreach (take_flashes() as $f): ?>
  <div class="flash flash-<?= e($f['type']) ?>" role="status"><?= $f['msg'] /* i testi dinamici sono già passati da e() */ ?></div>
<?php endforeach; ?>
