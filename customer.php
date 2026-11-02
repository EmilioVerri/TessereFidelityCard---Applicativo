<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/stamps.php';
$user = require_login();

$customer = load_customer((int)($_GET['id'] ?? 0));
if (!$customer || !can_access_card($user, (int)$customer['card_id'])) {
    http_response_code(404);
    exit('Cliente non trovato.');
}
$card = load_card((int)$customer['card_id']);
$id = (int)$customer['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    $after = '';
    try {
        if (in_array($action, ['stamp', 'unstamp', 'reset'], true)) {
            $c = card_action($customer, $card, $action, (int)$user['id']);
            $left = (int)$card['stamps_required'] - (int)$c['stamps'];
            switch ($c['event']) {
                case 'stamped':   flash('Timbro aggiunto. Ne mancano ' . $left . ' per: ' . e(mb_strtolower($card['reward_text'])) . '.'); $after = '&fresh=1'; break;
                case 'completed': $after = '&fresh=1&win=new'; break;
                case 'unstamped': flash('Timbro tolto.', 'warn'); break;
                case 'redeemed':  flash('Premio consegnato. La carta riparte da zero.'); break;
                case 'reset':     flash('Carta azzerata.', 'warn'); break;
            }
        } elseif ($action === 'edit') {
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') throw new RuntimeException('Il nome non può essere vuoto.');
            db()->prepare('UPDATE customers SET name = ?, phone = ?, email = ? WHERE id = ?')
                ->execute([mb_substr($name, 0, 100), trim((string)($_POST['phone'] ?? '')) ?: null, trim((string)($_POST['email'] ?? '')) ?: null, $id]);
            flash('Dati salvati.');
        } elseif ($action === 'delete') {
            db()->prepare('DELETE FROM customers WHERE id = ?')->execute([$id]);
            flash('Tessera di ' . e($customer['name']) . ' eliminata.', 'warn');
            redirect('card.php?id=' . (int)$card['id']);
        }
    } catch (RuntimeException $ex) {
        flash(e($ex->getMessage()), 'err');
    }
    redirect('customer.php?id=' . $id . $after);
}

$st = db()->prepare('SELECT l.*, u.username FROM stamp_log l LEFT JOIN users u ON u.id = l.user_id
                     WHERE l.customer_id = ? ORDER BY l.id DESC LIMIT 30');
$st->execute([$id]);
$log = $st->fetchAll();

$req   = (int)$card['stamps_required'];
$s     = (int)$customer['stamps'];
$ready = $s >= $req;
$link  = tessera_url($customer['code']);
$waText  = 'Ciao ' . $customer['name'] . '! Questa è la tua carta fedeltà di ' . $card['name'] . ': ' . $link;
$waPhone = preg_replace('/\D/', '', (string)$customer['phone']);
if ($waPhone !== '' && strlen($waPhone) <= 10) $waPhone = '39' . $waPhone;   // numero italiano senza prefisso
$showWin = $ready && (($_GET['win'] ?? '') !== '' || empty($_GET['fresh']));
$justCompleted = ($_GET['win'] ?? '') === 'new';
$isNew = !empty($_GET['new']);

$pageTitle = $customer['name'];
$active = 'home';
require __DIR__ . '/inc/header.php';
?>
<a class="back" href="<?= e(url('card.php?id=' . (int)$card['id'])) ?>"><?= icon('back', 18) ?>Clienti</a>

<div id="the-card"><?= stamp_card_html($card, $customer, $customer['name'], $card['name']) ?></div>

<?php if ($ready): ?>
  <div class="ready-banner">
    <h2><?= e($card['reward_text']) ?>!</h2>
    <p>La carta è completa: al prossimo passaggio il premio è suo. Dopo averlo dato premi Reset e la carta riparte da zero.</p>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="reset">
      <button class="btn btn-white btn-xl btn-block"><?= icon('reset', 22) ?>Reset carta</button>
    </form>
  </div>
<?php else: ?>
  <form method="post" style="margin:16px 0"><?= csrf_field() ?><input type="hidden" name="action" value="stamp">
    <button class="btn btn-primary btn-xl btn-block"><?= e($card['icon']) ?> Aggiungi timbro</button>
  </form>
<?php endif; ?>

<div class="btn-row3" style="margin-bottom:24px">
  <form method="post" data-confirm="Togliere un timbro a <?= e($customer['name']) ?>?"><?= csrf_field() ?><input type="hidden" name="action" value="unstamp">
    <button class="btn btn-ghost btn-sm btn-block" <?= $s <= 0 ? 'disabled' : '' ?>><?= icon('minus', 18) ?>Togli</button></form>
  <form method="post" data-confirm="Azzerare i timbri di <?= e($customer['name']) ?>?"><?= csrf_field() ?><input type="hidden" name="action" value="reset">
    <button class="btn btn-ghost btn-sm btn-block" <?= $s <= 0 ? 'disabled' : '' ?>><?= icon('reset', 18) ?>Reset</button></form>
  <a class="btn btn-ghost btn-sm" href="<?= e($link) ?>" target="_blank" rel="noopener"><?= icon('eye', 18) ?>Tessera</a>
</div>

<div class="panel" <?= $isNew ? 'style="border-color:var(--brand);box-shadow:0 0 0 3px var(--brand-soft)"' : '' ?>>
  <h2>Manda la tessera al cliente</h2>
  <p class="muted small">Il cliente apre il link e vede i suoi timbri. Da lì può salvarla in Google Wallet o Apple Wallet.</p>
  <div class="btn-row" style="margin-bottom:10px">
    <a class="btn btn-win" target="_blank" rel="noopener" href="https://wa.me/<?= e($waPhone) ?>?text=<?= e(rawurlencode($waText)) ?>"><?= icon('chat', 20) ?>WhatsApp</a>
    <button class="btn btn-ink" type="button" data-share-url="<?= e($link) ?>" data-share-text="<?= e('La tua carta fedeltà di ' . $card['name']) ?>"><?= icon('share', 20) ?>Condividi</button>
  </div>
  <div class="copy-row">
    <input type="text" id="link" value="<?= e($link) ?>" readonly aria-label="Link della tessera">
    <button class="btn btn-ghost btn-sm" type="button" data-copy="#link"><?= icon('copy', 18) ?>Copia</button>
  </div>
  <details style="margin-top:12px">
    <summary class="small" style="cursor:pointer;color:var(--brand);font-weight:600">Mostra QR da far inquadrare</summary>
    <div class="qr-mini" style="margin-top:10px"><div id="qr" data-qr="<?= e($link) ?>"></div></div>
  </details>
</div>

<div class="grid2">
  <div class="panel">
    <h2>Riepilogo</h2>
    <table class="log">
      <tr><td>Timbri attuali</td><td style="text-align:right"><b><?= $s ?>/<?= $req ?></b></td></tr>
      <tr><td><?= e(ucfirst($card['unit_plural'])) ?> in totale</td><td style="text-align:right"><b><?= (int)$customer['total_stamps'] ?></b></td></tr>
      <tr><td>Premi ricevuti</td><td style="text-align:right"><b><?= (int)$customer['rewards_redeemed'] ?></b></td></tr>
      <tr><td>Cliente dal</td><td style="text-align:right"><?= e(date('d/m/Y', strtotime($customer['created_at']))) ?></td></tr>
      <tr><td>Codice tessera</td><td style="text-align:right"><?= e($customer['code']) ?></td></tr>
    </table>
  </div>
  <div class="panel">
    <h2>Storico</h2>
    <?php if (!$log): ?><p class="muted">Ancora nessun movimento.</p><?php else: ?>
    <table class="log">
      <?php foreach ($log as $l): ?>
      <tr>
        <td><?= ['stamp' => e($card['icon']) . ' Timbro', 'unstamp' => '− Tolto', 'redeem' => '🎁 Premio', 'reset' => '↺ Reset'][$l['action']] ?? e($l['action']) ?></td>
        <td class="muted small"><?= e(date('d/m H:i', strtotime($l['created_at']))) ?></td>
        <td style="text-align:right"><b><?= (int)$l['stamps_after'] ?></b>/<?= $req ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>
</div>

<details class="fold">
  <summary>Modifica dati cliente<?= icon('plus', 18) ?></summary>
  <div class="fold-body">
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="edit">
      <label for="en">Nome</label><input id="en" type="text" name="name" value="<?= e($customer['name']) ?>" required maxlength="100">
      <div class="row">
        <div><label for="ep">Telefono</label><input id="ep" type="tel" name="phone" value="<?= e($customer['phone']) ?>" maxlength="30"></div>
        <div><label for="ee">Email</label><input id="ee" type="email" name="email" value="<?= e($customer['email']) ?>" maxlength="120"></div>
      </div>
      <br><button class="btn btn-primary btn-block">Salva dati</button>
    </form>
    <hr>
    <form method="post" data-confirm="Eliminare per sempre la tessera di <?= e($customer['name']) ?>?"><?= csrf_field() ?><input type="hidden" name="action" value="delete">
      <button class="btn btn-danger btn-block"><?= icon('trash', 20) ?>Elimina tessera</button></form>
  </div>
</details>

<?php if ($ready): ?>
<section class="win <?= $showWin ? 'show' : '' ?>" id="win" role="dialog" aria-modal="true" aria-labelledby="win-title">
  <div class="win-body">
    <div class="win-icon"><?= e($card['icon']) ?></div>
    <div class="who-name"><?= e($customer['name']) ?></div>
    <h2 id="win-title"><?= e($card['reward_text']) ?>!</h2>
    <p><?= $justCompleted
        ? 'Ha appena completato la carta: il premio vale dal prossimo passaggio.'
        : 'Ha completato la carta con ' . $req . ' timbri: questa volta il premio è suo.' ?></p>
  </div>
  <div class="win-actions">
    <p class="small" style="opacity:.85;margin:0 0 4px">Dopo aver dato il premio premi Reset: la carta riparte da zero.</p>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="reset">
      <button class="btn btn-white btn-xl btn-block"><?= icon('reset', 22) ?>Reset carta</button></form>
    <button class="btn btn-ghost" type="button" onclick="document.getElementById('win').classList.remove('show')">Chiudi</button>
  </div>
</section>
<?php endif; ?>

<?php if (!empty($_GET['fresh'])): ?>
<script>document.addEventListener('DOMContentLoaded',()=>{const s=document.querySelectorAll('#the-card .stamp.on');if(s.length)s[s.length-1].classList.add('fresh');if(navigator.vibrate)navigator.vibrate(60);});</script>
<?php endif; ?>
<script src="<?= e(url('assets/vendor/qrcode.min.js')) ?>"></script>
<?php require __DIR__ . '/inc/footer.php'; ?>
