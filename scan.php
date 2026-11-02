<?php
require_once __DIR__ . '/inc/bootstrap.php';
$user = require_login();

$cardId = is_admin($user) ? (int)($_GET['card'] ?? 0) : (int)$user['card_id'];
$card = $cardId ? load_card($cardId) : null;

$pageTitle = 'Scansiona';
$active = 'scan';
require __DIR__ . '/inc/header.php';
?>
<div class="page-head">
  <h1>Scansiona tessera</h1>
  <p><?= $card ? e($card['name']) . '. ' : '' ?>Inquadra il QR o il codice a barre del cliente: vedi subito quanti timbri ha.</p>
</div>

<div class="scanner" id="scanner">
  <div id="reader"></div>
  <div class="scanner-hint" id="scan-hint">Tieni il codice dentro il riquadro</div>
  <div class="scanner-off" id="scan-off"><div>
    <div style="font-size:2rem;margin-bottom:8px">📷</div>
    <b style="color:#fff">Fotocamera non disponibile</b><br>
    Consenti l'accesso alla fotocamera (il sito deve essere in https) oppure scrivi il codice qui sotto.
  </div></div>
</div>

<div class="panel" style="margin-top:16px">
  <h3>Oppure scrivi il codice</h3>
  <form id="manual" class="copy-row">
    <input type="text" id="manual-code" placeholder="FC…" autocapitalize="characters" autocomplete="off" aria-label="Codice tessera">
    <button class="btn btn-ink">Controlla</button>
  </form>
</div>

<!-- Scheda del cliente scansionato -->
<div class="backdrop" id="backdrop"></div>
<section class="sheet" id="sheet" aria-live="polite">
  <div class="sheet-grip"></div>
  <div id="sheet-card"></div>
  <div id="sheet-msg" class="flash" hidden></div>
  <div style="display:grid;gap:10px">
    <button class="btn btn-primary btn-xl" id="btn-stamp"></button>
    <div class="btn-row">
      <button class="btn btn-ghost" id="btn-next"><?= icon('camera', 20) ?>Altro cliente</button>
      <a class="btn btn-ghost" id="btn-detail" href="#">Scheda cliente</a>
    </div>
  </div>
</section>

<!-- Schermata verde a tutto schermo: premio -->
<section class="win" id="win" role="dialog" aria-modal="true" aria-labelledby="win-title">
  <div class="win-body">
    <div class="win-icon" id="win-icon">🎁</div>
    <div class="who-name" id="win-name"></div>
    <h2 id="win-title"></h2>
    <p id="win-text"></p>
  </div>
  <div class="win-actions">
    <p class="small" style="opacity:.85;margin:0 0 4px">Dopo aver dato il premio premi Reset: la carta riparte da zero.</p>
    <button class="btn btn-white btn-xl" id="btn-reset"><?= icon('reset', 22) ?>Reset carta</button>
    <button class="btn btn-ghost" id="btn-win-close">Chiudi</button>
  </div>
</section>

<script src="<?= e(url('assets/vendor/html5-qrcode.min.js')) ?>"></script>
<script>
(() => {
  const API  = <?= json_encode(url('api/stamp.php')) ?>;
  const CSRF = <?= json_encode(csrf_token()) ?>;
  const $ = id => document.getElementById(id);
  let scanner = null, busy = false, current = null;

  const esc = s => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const buzz = p => { if (navigator.vibrate) navigator.vibrate(p); };

  async function call(code, action) {
    const r = await fetch(API, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF': CSRF },
      body: JSON.stringify({ code, action })
    });
    return r.json();
  }

  function pauseCam()  { if (scanner) { try { scanner.pause(true); } catch (e) {} } }
  function resumeCam() { if (scanner) { try { scanner.resume(); } catch (e) {} } }

  function msg(html, type) {
    const m = $('sheet-msg');
    if (!html) { m.hidden = true; return; }
    m.hidden = false; m.className = 'flash flash-' + type; m.innerHTML = html;
  }

  function openSheet(d, animateLast) {
    current = d;
    $('sheet-card').innerHTML = d.card_html;
    if (animateLast && d.stamps > 0) {
      const st = $('sheet-card').querySelectorAll('.stamp.on');
      if (st.length) st[st.length - 1].classList.add('fresh');
    }
    $('btn-detail').href = d.detail_url;
    const b = $('btn-stamp');
    b.disabled = false;
    if (d.ready) {
      b.className = 'btn btn-win btn-xl';
      b.innerHTML = '🎁 Premio pronto: apri';
      b.dataset.mode = 'win';
    } else {
      b.className = 'btn btn-primary btn-xl';
      b.innerHTML = esc(d.icon) + ' Aggiungi timbro';
      b.dataset.mode = 'stamp';
    }
    $('sheet').classList.add('show'); $('backdrop').classList.add('show');
  }

  function closeSheet() {
    $('sheet').classList.remove('show'); $('backdrop').classList.remove('show');
    current = null; resumeCam();
  }

  function openWin(d, justCompleted) {
    $('win-icon').textContent = d.icon;
    $('win-name').textContent = d.name;
    $('win-title').textContent = d.reward + '!';
    $('win-text').textContent = justCompleted
      ? 'Ha appena completato la carta: il premio vale dal prossimo passaggio.'
      : 'Ha completato la carta con ' + d.required + ' timbri: questa volta il premio è suo.';
    $('btn-reset').disabled = false;
    $('win').classList.add('show');
    buzz([120, 80, 120, 80, 220]);
  }
  function closeWin() { $('win').classList.remove('show'); }

  async function lookup(code) {
    if (busy) return; busy = true;
    pauseCam(); buzz(50);
    try {
      const d = await call(code, 'lookup');
      if (!d.ok) { toast(d.error); resumeCam(); return; }
      openSheet(d, false);
      if (d.ready) { msg('', ''); openWin(d, false); }
      else if (d.last_stamp !== null && d.last_stamp < 300)
        msg('Timbro già messo ' + Math.max(1, Math.round(d.last_stamp / 60)) + ' min fa. Controlla prima di aggiungerne un altro.', 'warn');
      else msg('Ha <b>' + d.stamps + '</b> timbri su ' + d.required + '. Ne mancano ' + d.left + ' per: ' + esc(d.reward.toLowerCase()) + '.', 'ok');
    } catch (e) { toast('Connessione assente, riprova.'); resumeCam(); }
    finally { busy = false; }
  }

  $('btn-stamp').addEventListener('click', async () => {
    if (!current || busy) return;
    if ($('btn-stamp').dataset.mode === 'win') { openWin(current, false); return; }
    busy = true; $('btn-stamp').disabled = true;
    try {
      const d = await call(current.code, 'stamp');
      if (!d.ok) { msg(esc(d.error), 'err'); $('btn-stamp').disabled = false; return; }
      openSheet(d, true);
      buzz([60, 40, 60]);
      if (d.event === 'completed') { msg('', ''); openWin(d, true); }
      else {
        msg('Timbro aggiunto. Ne mancano <b>' + d.left + '</b> per: ' + esc(d.reward.toLowerCase()) + '.', 'ok');
        const b = $('btn-stamp'); b.disabled = true; b.className = 'btn btn-xl'; b.innerHTML = '✓ Timbro aggiunto';
      }
    } catch (e) { msg('Connessione assente, riprova.', 'err'); $('btn-stamp').disabled = false; }
    finally { busy = false; }
  });

  $('btn-reset').addEventListener('click', async () => {
    if (!current || busy) return;
    busy = true; $('btn-reset').disabled = true;
    try {
      const d = await call(current.code, 'reset');
      if (!d.ok) { toast(d.error); $('btn-reset').disabled = false; return; }
      closeWin(); openSheet(d, false);
      msg('Premio consegnato. La carta di ' + esc(d.name) + ' riparte da zero.', 'ok');
      const b = $('btn-stamp'); b.className = 'btn btn-primary btn-xl'; b.innerHTML = esc(d.icon) + ' Aggiungi timbro'; b.dataset.mode = 'stamp';
      toast('Carta azzerata');
    } catch (e) { toast('Connessione assente, riprova.'); $('btn-reset').disabled = false; }
    finally { busy = false; }
  });

  $('btn-win-close').addEventListener('click', closeWin);
  $('btn-next').addEventListener('click', closeSheet);
  $('backdrop').addEventListener('click', closeSheet);

  $('manual').addEventListener('submit', ev => {
    ev.preventDefault();
    const c = $('manual-code').value.trim();
    if (c) { $('manual-code').blur(); lookup(c); }
  });

  function camOff() { $('scanner').classList.add('off'); $('scan-hint').hidden = true; }

  if (!window.Html5Qrcode) { camOff(); return; }
  const F = Html5QrcodeSupportedFormats;
  scanner = new Html5Qrcode('reader', { verbose: false,
    formatsToSupport: [F.QR_CODE, F.CODE_128, F.CODE_39, F.EAN_13, F.PDF_417, F.AZTEC] });
  scanner.start(
    { facingMode: 'environment' },
    { fps: 12, qrbox: (w, h) => { const s = Math.floor(Math.min(w, h) * 0.68); return { width: s, height: s }; } },
    text => lookup(text),
    () => {}
  ).catch(camOff);
})();
</script>
<?php require __DIR__ . '/inc/footer.php'; ?>
