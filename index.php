<?php
require_once __DIR__ . '/inc/bootstrap.php';

if (current_user()) redirect('dashboard.php');

$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    // max 8 tentativi falliti in 15 minuti per IP
    $st = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at > (NOW() - INTERVAL 15 MINUTE)');
    $st->execute([client_ip()]);
    if ((int)$st->fetchColumn() >= 8) {
        $error = 'Troppi tentativi. Riprova tra 15 minuti.';
    } else {
        $st = db()->prepare('SELECT * FROM users WHERE username = ?');
        $st->execute([$username]);
        $user = $st->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['uid'] = (int)$user['id'];
            db()->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$user['id']]);
            db()->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([client_ip()]);
            if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                    ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
            }
            redirect('dashboard.php');
        }
        db()->prepare('INSERT INTO login_attempts (ip, username) VALUES (?, ?)')->execute([client_ip(), substr($username, 0, 50)]);
        $error = 'Utente o password non corretti.';
    }
}

$pageTitle = 'Accedi';
$bodyClass = 'guest';
require __DIR__ . '/inc/header.php';
$demoCard = ['color' => '#3b3bd6', 'stamps_required' => 8, 'icon' => '⭐', 'reward_text' => 'Premio',
             'unit_singular' => 'visita', 'unit_plural' => 'visite'];
?>
</main>
<section class="login">
  <div class="login-in">
    <?= stamp_card_html($demoCard, ['stamps' => 5], APP_NAME, 'La tua carta fedeltà digitale') ?>
    <form method="post" class="login-form" autocomplete="on">
      <h1>Accedi</h1>
      <p class="muted">Inserisci utente e password per gestire le carte e i timbri.</p>
      <?php if ($error): ?><div class="flash flash-err" role="alert"><?= e($error) ?></div><?php endif; ?>
      <?= csrf_field() ?>
      <label for="u">Utente</label>
      <input id="u" type="text" name="username" value="<?= e($username) ?>" autocapitalize="none" autocorrect="off" autocomplete="username" required <?= $username === '' ? 'autofocus' : '' ?>>
      <label for="p">Password</label>
      <input id="p" type="password" name="password" autocomplete="current-password" required <?= $username !== '' ? 'autofocus' : '' ?>>
      <br><br>
      <button class="btn btn-primary btn-block btn-xl" type="submit">Entra</button>
    </form>
    <p class="login-foot">Sei un cliente? Apri il link della tua tessera che ti ha mandato il negozio.</p>
  </div>
</section>
<main>
<?php require __DIR__ . '/inc/footer.php'; ?>
