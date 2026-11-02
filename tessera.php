<?php
/* Pagina PUBBLICA della tessera del cliente (link personale, nessuna password) */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/wallet_google.php';
require_once __DIR__ . '/inc/wallet_apple.php';

$code = preg_replace('/[^A-Za-z0-9]/', '', (string)($_GET['c'] ?? ''));
$customer = $code !== '' ? load_customer_by_code($code) : null;
$card = $customer ? load_card((int)$customer['card_id']) : null;

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
if (!$customer) http_response_code(404);

$req   = $card ? (int)$card['stamps_required'] : 0;
$s     = $customer ? (int)$customer['stamps'] : 0;
$ready = $customer && $s >= $req;
$left  = $req - $s;
?><!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="<?= $ready ? '#0e9f63' : '#eef1f6' ?>">
<title><?= $card ? e($card['name'] . ' · la tua carta') : 'Tessera non trovata' ?></title>
<link rel="icon" href="<?= e(url('assets/logo.png')) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600;12..96,700;12..96,800&family=Figtree:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="<?= e(url('assets/style.css')) ?>?v=2">
</head>
<body class="tess <?= $ready ? 'is-ready' : '' ?>">
<main class="tess-wrap">
<?php if (!$customer): ?>
  <div class="panel center" style="margin-top:20vh">
    <div style="font-size:2.4rem">🔍</div>
    <h1>Tessera non trovata</h1>
    <p class="muted">Il link non è corretto o la tessera è stata eliminata. Chiedi al negozio di mandartelo di nuovo.</p>
  </div>
<?php else: ?>
  <div class="tess-top">
    <span class="shop-icon"><?= e($card['icon']) ?></span>
    <div><b><?= e($card['name']) ?></b><small>Carta fedeltà di <?= e($customer['name']) ?></small></div>
  </div>

  <?php if ($ready): ?>
  <div class="tess-ready">
    <div class="win-icon">🎁</div>
    <h1><?= e($card['reward_text']) ?>!</h1>
    <p>Hai completato la carta. La prossima volta il premio è tuo: mostra questa schermata in negozio.</p>
  </div>
  <?php endif; ?>

  <?= stamp_card_html($card, $customer, $ready ? 'Carta completa' : ($s === 0 ? 'Benvenuto!' : 'I tuoi timbri'),
        $ready ? '' : ($left === 1 ? 'Ancora 1 e hai il premio' : '')) ?>

  <div class="code-panel">
    <p class="small muted" style="margin:0 0 12px">Fai scansionare questo codice a ogni <?= e($card['unit_singular']) ?></p>
    <div id="qr" data-qr="<?= e($customer['code']) ?>"></div>
    <svg id="barcode" hidden></svg>
    <div class="code-text"><?= e($customer['code']) ?></div>
    <div class="seg" role="tablist">
      <button type="button" class="on" data-show="qr" role="tab">QR code</button>
      <button type="button" data-show="barcode" role="tab">Codice a barre</button>
    </div>
  </div>

  <div class="wallet">
    <?php if (google_wallet_ready()): ?>
      <a class="btn btn-wallet" href="<?= e(url('wallet/google.php?c=' . urlencode($customer['code']))) ?>">Aggiungi a Google Wallet</a>
    <?php endif; ?>
    <?php if (apple_wallet_ready()): ?>
      <a class="btn btn-wallet" href="<?= e(url('wallet/apple.php?c=' . urlencode($customer['code']))) ?>">Aggiungi ad Apple Wallet</a>
    <?php endif; ?>
  </div>

  <div class="live"><i></i>Si aggiorna da sola quando ricevi un timbro</div>

  <?php if (!google_wallet_ready() && !apple_wallet_ready()): ?>
    <p class="shop-info">Consiglio: aggiungi questa pagina alla schermata Home del telefono per ritrovarla subito.</p>
  <?php endif; ?>
  <?php if ($card['address'] || $card['phone']): ?>
    <p class="shop-info"><?= e($card['name']) ?><?= $card['address'] ? ', ' . e($card['address']) : '' ?>
      <?php if ($card['phone']): ?><br><a href="tel:<?= e(preg_replace('/[^\d+]/', '', $card['phone'])) ?>"><?= e($card['phone']) ?></a><?php endif; ?></p>
  <?php endif; ?>

  <script src="<?= e(url('assets/vendor/qrcode.min.js')) ?>"></script>
  <script src="<?= e(url('assets/vendor/JsBarcode.all.min.js')) ?>"></script>
  <script>
  document.addEventListener('DOMContentLoaded', () => {
    // QR / codice a barre
    let drawn = false;
    const qr = document.getElementById('qr'), bc = document.getElementById('barcode');
    document.querySelectorAll('.seg button').forEach(b => b.addEventListener('click', () => {
      document.querySelectorAll('.seg button').forEach(x => x.classList.toggle('on', x === b));
      const bar = b.dataset.show === 'barcode';
      if (bar && !drawn && window.JsBarcode) {
        JsBarcode(bc, <?= json_encode($customer['code']) ?>, { format: 'CODE128', displayValue: false, height: 90, width: 2.2, margin: 6 });
        drawn = true;
      }
      bar ? bc.removeAttribute('hidden') : bc.setAttribute('hidden', ''); qr.style.display = bar ? 'none' : 'inline-block';
    }));

    // Timbro appena arrivato: piccola animazione
    if (new URLSearchParams(location.search).get('n')) {
      const s = document.querySelectorAll('.stamp.on');
      if (s.length) s[s.length - 1].classList.add('fresh');
      if (navigator.vibrate) navigator.vibrate(80);
    }

    // Aggiornamento automatico: controlla ogni 8 secondi se i timbri sono cambiati
    const state = { stamps: <?= $s ?>, redeemed: <?= (int)$customer['rewards_redeemed'] ?> };
    const api = <?= json_encode(url('api/public.php?c=' . $customer['code'])) ?>;
    const page = <?= json_encode(tessera_url($customer['code'])) ?>;
    async function check() {
      if (document.hidden) return;
      try {
        const d = await (await fetch(api, { cache: 'no-store' })).json();
        if (d.ok && (d.stamps !== state.stamps || d.redeemed !== state.redeemed)) {
          location.replace(page + (d.stamps > state.stamps ? '&n=1' : ''));
        }
      } catch (e) {}
    }
    setInterval(check, 8000);
    document.addEventListener('visibilitychange', check);
  });
  </script>
<?php endif; ?>
</main>
<script src="<?= e(url('assets/app.js')) ?>?v=2"></script>
</body>
</html>
