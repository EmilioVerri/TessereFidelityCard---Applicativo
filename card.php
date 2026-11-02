<?php
require_once __DIR__ . '/inc/bootstrap.php';
$user = require_login();

$cardId = (int)($_GET['id'] ?? 0);
if (!is_admin($user)) $cardId = (int)$user['card_id'];   // il gestore vede solo la sua attività
$card = load_card($cardId);
if (!$card || !can_access_card($user, $cardId)) {
    http_response_code(404);
    exit('Attività non trovata.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_customer') {
        $name  = trim((string)($_POST['name'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $first = !empty($_POST['first_stamp']);
        if ($name === '') {
            flash('Scrivi il nome del cliente.', 'err');
            redirect('card.php?id=' . $cardId . '#nuovo');
        }
        $code = generate_card_code();
        db()->prepare('INSERT INTO customers (card_id, code, name, phone, email, stamps, total_stamps, last_visit) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$cardId, $code, mb_substr($name, 0, 100), $phone ?: null, $email ?: null,
                       $first ? 1 : 0, $first ? 1 : 0, $first ? date('Y-m-d H:i:s') : null]);
        $newId = (int)db()->lastInsertId();
        if ($first) {
            db()->prepare("INSERT INTO stamp_log (customer_id, user_id, action, stamps_after) VALUES (?, ?, 'stamp', 1)")
                ->execute([$newId, $user['id']]);
        }
        flash('Tessera creata per <b>' . e($name) . '</b>. Ora mandagli il link con WhatsApp o fagli inquadrare il QR.');
        redirect('customer.php?id=' . $newId . '&new=1');
    }

    if ($action === 'settings') {
        $req = max(1, min(30, (int)($_POST['stamps_required'] ?? 10)));
        $f = fn($k, $def, $max) => mb_substr(trim((string)($_POST[$k] ?? '')) ?: $def, 0, $max);
        $name = is_admin($user) ? $f('name', $card['name'], 100) : $card['name'];
        db()->prepare('UPDATE cards SET name = ?, stamps_required = ?, reward_text = ?, color = ?, icon = ?, unit_singular = ?, unit_plural = ?, address = ?, phone = ? WHERE id = ?')
            ->execute([$name, $req, $f('reward_text', 'Premio', 150), valid_hex_color((string)($_POST['color'] ?? $card['color'])),
                       $f('icon', '⭐', 4), $f('unit_singular', 'visita', 30), $f('unit_plural', 'visite', 30),
                       trim((string)($_POST['address'] ?? '')) ?: null, trim((string)($_POST['phone'] ?? '')) ?: null, $cardId]);
        flash('Impostazioni salvate. Premio ogni ' . $req . ' timbri.');
        redirect('card.php?id=' . $cardId);
    }

    if ($action === 'reset_password' && is_admin($user)) {
        $pwd = random_password(10);
        db()->prepare("UPDATE users SET password_hash = ? WHERE card_id = ? AND role = 'barber'")
            ->execute([password_hash($pwd, PASSWORD_DEFAULT), $cardId]);
        flash('Nuova password del gestore: <b>' . e($pwd) . '</b>. Annotala adesso.');
        redirect('card.php?id=' . $cardId);
    }

    if ($action === 'delete_card' && is_admin($user)) {
        db()->prepare('DELETE FROM cards WHERE id = ?')->execute([$cardId]);
        flash('Attività "' . e($card['name']) . '" eliminata con tutti i clienti.', 'warn');
        redirect('dashboard.php');
    }
    redirect('card.php?id=' . $cardId);
}

$req = (int)$card['stamps_required'];
$st = db()->prepare('SELECT * FROM customers WHERE card_id = ? ORDER BY last_visit IS NULL, last_visit DESC, name');
$st->execute([$cardId]);
$all = $st->fetchAll();
$ready = array_values(array_filter($all, fn($c) => (int)$c['stamps'] >= $req));

$st = db()->prepare("SELECT COUNT(*) FROM stamp_log l JOIN customers c ON c.id = l.customer_id
                     WHERE c.card_id = ? AND l.action = 'stamp' AND l.created_at >= CURDATE()");
$st->execute([$cardId]);
$today = (int)$st->fetchColumn();

$barber = null;
if (is_admin($user)) {
    $st = db()->prepare("SELECT username, last_login FROM users WHERE card_id = ? AND role = 'barber' LIMIT 1");
    $st->execute([$cardId]);
    $barber = $st->fetch();
}

/** Riga cliente nella lista */
function customer_row(array $c, array $card): string
{
    $req = (int)$card['stamps_required'];
    $s = (int)$c['stamps'];
    $isReady = $s >= $req;
    $sub = $isReady ? 'Premio pronto: ' . mb_strtolower($card['reward_text']) : 'Ultima visita: ' . time_ago($c['last_visit']);
    return '<li class="' . ($isReady ? 'ready' : '') . '"><a class="item" href="' . e(url('customer.php?id=' . (int)$c['id'])) . '">'
        . '<span class="avatar" style="' . ($isReady ? '' : 'background:' . e($card['color'])) . '">' . e(initials($c['name'])) . '</span>'
        . '<span class="item-main"><b>' . e($c['name']) . '</b><small>' . e($sub) . '</small>' . pips($s, $req) . '</span>'
        . '<span class="item-side">' . $s . '/' . $req . '<small>' . e($c['phone'] ?? '') . '</small></span>'
        . '<span hidden>' . e($c['code']) . '</span></a></li>';
}

$pageTitle = $card['name'];
$active = 'home';
require __DIR__ . '/inc/header.php';
?>
<?php if (is_admin($user)): ?><a class="back" href="<?= e(url('dashboard.php')) ?>"><?= icon('back', 18) ?>Tutte le attività</a><?php endif; ?>

<div class="page-head" style="display:flex;gap:14px;align-items:center">
  <span class="avatar" style="width:56px;height:56px;border-radius:18px;font-size:1.7rem;background:<?= e($card['color']) ?>"><?= e($card['icon']) ?></span>
  <div>
    <h1 style="margin:0"><?= e($card['name']) ?></h1>
    <p><?= e($card['reward_text']) ?> ogni <?= $req ?> <?= e($card['unit_plural']) ?></p>
  </div>
</div>

<div class="quick">
  <a class="btn btn-primary" href="<?= e(url('scan.php?card=' . $cardId)) ?>"><?= icon('camera') ?>Scansiona tessera</a>
  <a class="btn btn-ink" href="#nuovo"><?= icon('plus') ?>Cliente</a>
</div>

<div class="kpis" style="margin-top:14px">
  <div class="kpi"><b><?= count($all) ?></b><span>clienti</span></div>
  <div class="kpi"><b><?= $today ?></b><span>timbri oggi</span></div>
  <div class="kpi good"><b><?= count($ready) ?></b><span>premi pronti</span></div>
</div>

<?php if ($ready): ?>
  <div class="section-title"><h2>Premio pronto</h2><span class="small muted">al prossimo passaggio</span></div>
  <ul class="list"><?php foreach ($ready as $c) echo customer_row($c, $card); ?></ul>
<?php endif; ?>

<div class="section-title"><h2>Clienti</h2><span class="small muted"><?= count($all) ?> tessere</span></div>
<?php if (!$all): ?>
  <div class="list"><div class="empty"><div class="big"><?= e($card['icon']) ?></div>Nessun cliente ancora.<br>Tocca <b>Cliente</b> per creare la prima tessera.</div></div>
<?php else: ?>
  <div class="search" style="margin-bottom:12px"><?= icon('search', 20) ?>
    <input type="search" placeholder="Cerca per nome, telefono o codice" data-filter="#clienti" aria-label="Cerca cliente">
  </div>
  <ul class="list" id="clienti"><?php foreach ($all as $c) echo customer_row($c, $card); ?></ul>
  <div id="no-results" class="empty" hidden>Nessun cliente trovato.</div>
<?php endif; ?>

<div class="section-title"><h2>Gestione</h2></div>
<details class="fold" id="nuovo">
  <summary>Nuovo cliente<?= icon('plus', 18) ?></summary>
  <div class="fold-body">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_customer">
      <label for="cn">Nome e cognome</label>
      <input id="cn" type="text" name="name" required maxlength="100" autocomplete="off">
      <div class="row">
        <div><label for="cp">Telefono</label><input id="cp" type="tel" name="phone" maxlength="30" autocomplete="off" placeholder="Per mandare il link su WhatsApp"></div>
        <div><label for="ce">Email</label><input id="ce" type="email" name="email" maxlength="120" autocomplete="off"></div>
      </div>
      <label class="check"><input type="checkbox" name="first_stamp" value="1" checked>Metti già il primo timbro di oggi</label>
      <button class="btn btn-primary btn-block btn-xl">Crea tessera</button>
    </form>
  </div>
</details>

<details class="fold">
  <summary>Impostazioni della carta<?= icon('plus', 18) ?></summary>
  <div class="fold-body">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="settings">
      <?php if (is_admin($user)): ?>
        <label for="sn">Nome dell'attività</label>
        <input id="sn" type="text" name="name" value="<?= e($card['name']) ?>" maxlength="100">
      <?php endif; ?>
      <div class="row">
        <div>
          <label for="sr">Premio ogni quanti timbri?</label>
          <input id="sr" type="number" name="stamps_required" min="1" max="30" value="<?= $req ?>" inputmode="numeric">
        </div>
        <div><label for="sc">Colore della carta</label><input id="sc" type="color" name="color" value="<?= e($card['color']) ?>"></div>
      </div>
      <label for="srw">Premio</label>
      <input id="srw" type="text" name="reward_text" value="<?= e($card['reward_text']) ?>" maxlength="150">
      <div class="row">
        <div><label for="sus">Un timbro vale un…</label><input id="sus" type="text" name="unit_singular" value="<?= e($card['unit_singular']) ?>" maxlength="30"></div>
        <div><label for="sup">Al plurale</label><input id="sup" type="text" name="unit_plural" value="<?= e($card['unit_plural']) ?>" maxlength="30"></div>
        <div style="flex:0 1 90px"><label for="sic">Icona</label><input id="sic" type="text" name="icon" value="<?= e($card['icon']) ?>" maxlength="4" style="text-align:center;font-size:1.3rem"></div>
      </div>
      <div class="row">
        <div><label for="sad">Indirizzo</label><input id="sad" type="text" name="address" value="<?= e($card['address']) ?>" maxlength="150"></div>
        <div><label for="sph">Telefono</label><input id="sph" type="tel" name="phone" value="<?= e($card['phone']) ?>" maxlength="30"></div>
      </div>
      <p class="hint">Se abbassi il numero di timbri, i clienti che lo superano risulteranno subito con il premio pronto.</p>
      <br>
      <button class="btn btn-primary btn-block">Salva impostazioni</button>
    </form>
  </div>
</details>

<?php if (is_admin($user)): ?>
<details class="fold">
  <summary>Accesso del gestore<?= icon('plus', 18) ?></summary>
  <div class="fold-body">
    <p>Utente <b><?= e($barber['username'] ?? '-') ?></b><br><span class="small muted">Ultimo accesso: <?= e(time_ago($barber['last_login'] ?? null)) ?></span></p>
    <form method="post" data-confirm="Creare una nuova password per il gestore? Quella vecchia smetterà di funzionare.">
      <?= csrf_field() ?><input type="hidden" name="action" value="reset_password">
      <button class="btn btn-ghost btn-block"><?= icon('key', 20) ?>Crea nuova password</button>
    </form>
    <hr>
    <form method="post" data-confirm="Elimini l'attività, l'accesso del gestore e TUTTI i clienti con i loro timbri. Continuare?">
      <?= csrf_field() ?><input type="hidden" name="action" value="delete_card">
      <button class="btn btn-danger btn-block"><?= icon('trash', 20) ?>Elimina attività</button>
    </form>
  </div>
</details>
<?php endif; ?>
<?php require __DIR__ . '/inc/footer.php'; ?>
