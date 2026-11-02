<?php
require_once __DIR__ . '/inc/bootstrap.php';
$user = require_login();

// Il gestore va direttamente alla sua attività
if (!is_admin($user)) {
    redirect('card.php?id=' . (int)$user['card_id']);
}

$presets = business_presets();
$p0 = $presets['barbiere'];
$form = ['name' => '', 'color' => '#3b3bd6', 'stamps_required' => 10, 'reward_text' => $p0['reward'],
         'icon' => $p0['icon'], 'unit_singular' => $p0['one'], 'unit_plural' => $p0['many'],
         'address' => '', 'phone' => '', 'username' => '', 'password' => random_password(10)];

/* ---------- Crea attività + carta + utenza gestore ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_card') {
    check_csrf();
    foreach ($form as $k => $v) $form[$k] = trim((string)($_POST[$k] ?? ''));
    $form['stamps_required'] = max(1, min(30, (int)$form['stamps_required']));
    $form['color'] = valid_hex_color($form['color']);
    $form['username'] = strtolower($form['username']);

    $errors = [];
    if ($form['name'] === '') $errors[] = 'Scrivi il nome dell\'attività.';
    if (!preg_match('/^[a-z0-9._-]{3,50}$/', $form['username'])) $errors[] = 'Nome utente: da 3 a 50 caratteri, solo lettere minuscole, numeri, punto e trattino.';
    if (strlen($form['password']) < 8) $errors[] = 'La password deve avere almeno 8 caratteri.';
    $st = db()->prepare('SELECT 1 FROM users WHERE username = ?');
    $st->execute([$form['username']]);
    if ($st->fetchColumn()) $errors[] = 'Il nome utente "' . $form['username'] . '" è già usato: scegline un altro.';

    if ($errors) {
        foreach ($errors as $er) flash(e($er), 'err');
    } else {
        $pdo = db();
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO cards (name, color, stamps_required, reward_text, icon, unit_singular, unit_plural, address, phone)
                       VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$form['name'], $form['color'], $form['stamps_required'], $form['reward_text'] ?: 'Premio',
                       mb_substr($form['icon'] ?: '⭐', 0, 4), $form['unit_singular'] ?: 'visita', $form['unit_plural'] ?: 'visite',
                       $form['address'] ?: null, $form['phone'] ?: null]);
        $cardId = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO users (username, password_hash, role, card_id, full_name) VALUES (?, ?, 'barber', ?, ?)")
            ->execute([$form['username'], password_hash($form['password'], PASSWORD_DEFAULT), $cardId, $form['name']]);
        $pdo->commit();

        flash('Attività <b>' . e($form['name']) . '</b> creata. Accesso del gestore: utente <b>' . e($form['username'])
            . '</b>, password <b>' . e($form['password']) . '</b>. Annotala adesso: non verrà più mostrata.');
        redirect('card.php?id=' . $cardId);
    }
}

$stats = db()->query("SELECT
    (SELECT COUNT(*) FROM cards) AS cards,
    (SELECT COUNT(*) FROM customers) AS customers,
    (SELECT COUNT(*) FROM stamp_log WHERE action = 'stamp' AND created_at >= CURDATE()) AS today")->fetch();

$cards = db()->query("SELECT c.*, u.username,
        (SELECT COUNT(*) FROM customers cu WHERE cu.card_id = c.id) AS n_customers,
        (SELECT COUNT(*) FROM customers cu WHERE cu.card_id = c.id AND cu.stamps >= c.stamps_required) AS n_ready,
        (SELECT MAX(l.created_at) FROM stamp_log l JOIN customers cu ON cu.id = l.customer_id WHERE cu.card_id = c.id) AS last_activity
    FROM cards c
    LEFT JOIN users u ON u.card_id = c.id AND u.role = 'barber'
    ORDER BY c.name")->fetchAll();

$pageTitle = 'Attività';
$active = 'home';
require __DIR__ . '/inc/header.php';
?>
<div class="page-head">
  <h1>Tutte le attività</h1>
  <p>Ogni attività ha la sua carta fedeltà e il suo accesso per il gestore.</p>
</div>

<div class="kpis">
  <div class="kpi"><b><?= (int)$stats['cards'] ?></b><span>attività</span></div>
  <div class="kpi"><b><?= (int)$stats['customers'] ?></b><span>clienti</span></div>
  <div class="kpi good"><b><?= (int)$stats['today'] ?></b><span>timbri oggi</span></div>
</div>

<div class="section-title"><h2>Attività</h2></div>
<?php if (!$cards): ?>
  <div class="list"><div class="empty"><div class="big">🏪</div>Nessuna attività. Creane una qui sotto.</div></div>
<?php else: ?>
  <?php if (count($cards) > 5): ?>
  <div class="search" style="margin-bottom:12px"><?= icon('search', 20) ?><input type="search" placeholder="Cerca attività" data-filter="#cards-list" aria-label="Cerca attività"></div>
  <?php endif; ?>
  <ul class="list" id="cards-list">
    <?php foreach ($cards as $c): ?>
    <li>
      <a class="item" href="<?= e(url('card.php?id=' . (int)$c['id'])) ?>">
        <span class="avatar" style="background:<?= e($c['color']) ?>;font-size:1.3rem"><?= e($c['icon']) ?></span>
        <span class="item-main">
          <b><?= e($c['name']) ?></b>
          <small>Utente <?= e($c['username'] ?? '-') ?>, premio ogni <?= (int)$c['stamps_required'] ?> <?= e($c['unit_plural']) ?></small>
          <small>Ultimo timbro: <?= e(time_ago($c['last_activity'])) ?></small>
        </span>
        <span class="item-side"><?= (int)$c['n_customers'] ?><small>clienti</small>
          <?php if ($c['n_ready']): ?><span class="tag"><?= (int)$c['n_ready'] ?> premi</span><?php endif; ?>
        </span>
      </a>
    </li>
    <?php endforeach; ?>
  </ul>
  <p id="no-results" class="empty" hidden>Nessuna attività con questo nome.</p>
<?php endif; ?>

<div class="section-title"><h2>Nuova attività</h2></div>
<details class="fold" id="nuovo" <?= $form['name'] !== '' ? 'open' : '' ?>>
  <summary>Crea carta fedeltà e accesso gestore<?= icon('plus', 18) ?></summary>
  <div class="fold-body">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create_card">

      <label>Che tipo di attività è?</label>
      <div class="presets">
        <?php foreach ($presets as $key => $p): ?>
          <button type="button" class="preset <?= $key === 'barbiere' ? 'on' : '' ?>"
            data-preset='<?= e(json_encode(['icon' => $p['icon'], 'unit_singular' => $p['one'], 'unit_plural' => $p['many'], 'reward_text' => $p['reward']], JSON_UNESCAPED_UNICODE)) ?>'>
            <span><?= e($p['icon']) ?></span><?= e($p['label']) ?></button>
        <?php endforeach; ?>
      </div>

      <label for="n">Nome dell'attività</label>
      <input id="n" type="text" name="name" value="<?= e($form['name']) ?>" required maxlength="100" placeholder="Es. Barberia da Marco">

      <div class="row">
        <div>
          <label for="sr">Premio ogni quanti timbri?</label>
          <input id="sr" type="number" name="stamps_required" min="1" max="30" value="<?= (int)$form['stamps_required'] ?>" inputmode="numeric">
        </div>
        <div>
          <label for="col">Colore della carta</label>
          <input id="col" type="color" name="color" value="<?= e($form['color']) ?>">
        </div>
      </div>
      <label for="rw">Premio</label>
      <input id="rw" type="text" name="reward_text" value="<?= e($form['reward_text']) ?>" maxlength="150">
      <div class="row">
        <div><label for="us">Un timbro vale un…</label><input id="us" type="text" name="unit_singular" value="<?= e($form['unit_singular']) ?>" maxlength="30"></div>
        <div><label for="up">Al plurale</label><input id="up" type="text" name="unit_plural" value="<?= e($form['unit_plural']) ?>" maxlength="30"></div>
        <div style="flex:0 1 90px"><label for="ic">Icona</label><input id="ic" type="text" name="icon" value="<?= e($form['icon']) ?>" maxlength="4" style="text-align:center;font-size:1.3rem"></div>
      </div>
      <div class="row">
        <div><label for="ad">Indirizzo</label><input id="ad" type="text" name="address" value="<?= e($form['address']) ?>" maxlength="150"></div>
        <div><label for="ph">Telefono</label><input id="ph" type="tel" name="phone" value="<?= e($form['phone']) ?>" maxlength="30"></div>
      </div>

      <hr>
      <h3>Accesso del gestore</h3>
      <p class="small muted">Con questo accesso il gestore vede solo la sua attività e i suoi clienti.</p>
      <div class="row">
        <div><label for="un">Nome utente</label><input id="un" type="text" name="username" value="<?= e($form['username']) ?>" autocapitalize="none" required placeholder="es. marco"></div>
        <div><label for="pw">Password</label><input id="pw" type="text" name="password" value="<?= e($form['password']) ?>" required minlength="8"></div>
      </div>
      <p class="hint">La password è già generata, puoi cambiarla.</p>
      <br>
      <button class="btn btn-primary btn-block btn-xl">Crea attività</button>
    </form>
  </div>
</details>
<?php require __DIR__ . '/inc/footer.php'; ?>
