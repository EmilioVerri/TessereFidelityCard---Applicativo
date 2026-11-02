// Funzioni comuni dell'interfaccia
function toast(text) {
  const t = document.getElementById('toast');
  if (!t) return;
  t.textContent = text; t.classList.add('show');
  clearTimeout(t._h); t._h = setTimeout(() => t.classList.remove('show'), 2200);
}

document.addEventListener('DOMContentLoaded', () => {
  // Conferma prima di azioni delicate: <form data-confirm="Sicuro?">
  document.querySelectorAll('form[data-confirm]').forEach(f => {
    f.addEventListener('submit', ev => { if (!confirm(f.dataset.confirm)) ev.preventDefault(); });
  });

  // Ricerca istantanea: <input data-filter="#lista">
  document.querySelectorAll('input[data-filter]').forEach(inp => {
    const list = document.querySelector(inp.dataset.filter);
    const empty = document.querySelector(inp.dataset.empty || '#no-results');
    inp.addEventListener('input', () => {
      const q = inp.value.trim().toLowerCase();
      let shown = 0;
      list.querySelectorAll('li').forEach(li => {
        const ok = li.textContent.toLowerCase().includes(q);
        li.style.display = ok ? '' : 'none';
        if (ok) shown++;
      });
      if (empty) empty.hidden = shown > 0;
    });
  });

  // Copia negli appunti: <button data-copy="#idInput">
  document.querySelectorAll('[data-copy]').forEach(btn => {
    btn.addEventListener('click', async () => {
      const el = document.querySelector(btn.dataset.copy);
      try { await navigator.clipboard.writeText(el.value); }
      catch { el.select(); document.execCommand('copy'); }
      toast('Link copiato');
    });
  });

  // Condivisione nativa del telefono: <button data-share-url="..." data-share-text="...">
  document.querySelectorAll('[data-share-url]').forEach(btn => {
    if (!navigator.share) { btn.hidden = true; return; }
    btn.addEventListener('click', () =>
      navigator.share({ title: btn.dataset.shareTitle || document.title, text: btn.dataset.shareText, url: btn.dataset.shareUrl }).catch(() => {}));
  });

  // QR code: <div data-qr="TESTO">
  if (window.QRCode) {
    document.querySelectorAll('[data-qr]').forEach(el => {
      new QRCode(el, { text: el.dataset.qr, width: 440, height: 440, correctLevel: QRCode.CorrectLevel.M });
    });
  }

  // Apre un pannello richiamato con #id (es. dashboard.php#nuovo)
  const openFromHash = () => {
    if (!location.hash) return;
    const d = document.querySelector(location.hash);
    if (d && d.tagName === 'DETAILS') {
      d.open = true;
      d.scrollIntoView({ behavior: 'smooth', block: 'start' });
      const f = d.querySelector('input:not([type=hidden])');
      if (f) setTimeout(() => f.focus(), 300);
    }
  };
  openFromHash();
  window.addEventListener('hashchange', openFromHash);

  // Scelta rapida tipo di attività: <button class="preset" data-preset='{...}'>
  document.querySelectorAll('[data-preset]').forEach(btn => {
    btn.addEventListener('click', () => {
      const p = JSON.parse(btn.dataset.preset), form = btn.closest('form');
      form.querySelectorAll('.preset').forEach(b => b.classList.toggle('on', b === btn));
      for (const [k, v] of Object.entries(p)) {
        const f = form.querySelector('[name="' + k + '"]');
        if (f) f.value = v;
      }
    });
  });
});
