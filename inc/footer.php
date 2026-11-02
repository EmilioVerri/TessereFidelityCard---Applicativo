</main>
<?php if ($u = current_user()):
    $scanUrl = is_admin($u) ? 'scan.php' : 'scan.php?card=' . (int)$u['card_id'];
    $homeUrl = 'dashboard.php'; ?>
<nav class="tabbar" aria-label="Menu">
  <a href="<?= e(url($homeUrl)) ?>" class="<?= $active === 'home' ? 'on' : '' ?>"><?= icon('home') ?>Home</a>
  <a href="<?= e(url('account.php')) ?>" class="<?= $active === 'account' ? 'on' : '' ?>"><?= icon('user') ?>Account</a>
  <a href="<?= e(url($scanUrl)) ?>" class="scan" aria-label="Scansiona tessera"><span class="bubble"><?= icon('camera', 26) ?></span></a>
  <a href="<?= e(url($homeUrl)) ?>#nuovo" class=""><?= icon('plus') ?><?= is_admin($u) ? 'Attività' : 'Cliente' ?></a>
  <a href="<?= e(url('logout.php')) ?>"><?= icon('logout') ?>Esci</a>
</nav>
<?php endif; ?>
<div class="toast" id="toast" role="status"></div>
<script src="<?= e(url('assets/app.js')) ?>?v=2"></script>
</body>
</html>
