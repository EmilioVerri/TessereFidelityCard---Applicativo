<?php
require_once __DIR__ . '/inc/bootstrap.php';
$user = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $cur  = (string)($_POST['current'] ?? '');
    $new  = (string)($_POST['new'] ?? '');
    $new2 = (string)($_POST['new2'] ?? '');

    $st = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
    $st->execute([$user['id']]);
    $hash = (string)$st->fetchColumn();

    if (!password_verify($cur, $hash)) {
        flash('La password attuale non è corretta.', 'err');
    } elseif (strlen($new) < 8) {
        flash('La nuova password deve avere almeno 8 caratteri.', 'err');
    } elseif ($new !== $new2) {
        flash('Le due nuove password non coincidono.', 'err');
    } else {
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
        session_regenerate_id(true);
        flash('Password aggiornata.');
    }
    redirect('account.php');
}

$pageTitle = 'Account';
$active = 'account';
require __DIR__ . '/inc/header.php';
?>
<div class="page-head"><h1>Il tuo account</h1></div>
<div class="panel">
  <p class="muted" style="margin-top:0">Utente: <b><?= e($user['username']) ?></b> · Ruolo: <b><?= is_admin($user) ? "Amministratore" : "Gestore" ?></b></p>
  <form method="post">
    <?= csrf_field() ?>
    <h2>Cambia password</h2>
    <label>Password attuale</label>
    <input type="password" name="current" autocomplete="current-password" required>
    <label>Nuova password (min. 8 caratteri)</label>
    <input type="password" name="new" autocomplete="new-password" minlength="8" required>
    <label>Ripeti nuova password</label>
    <input type="password" name="new2" autocomplete="new-password" minlength="8" required>
    <br><br>
    <button class="btn btn-primary btn-block">Salva nuova password</button>
  </form>
</div>
<a class="btn btn-danger btn-block" href="<?= e(url('logout.php')) ?>">Esci</a>
<?php require __DIR__ . '/inc/footer.php'; ?>
