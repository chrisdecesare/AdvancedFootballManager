/*
 * Il cuoricino degli obiettivi (Negozio e Personaggio): aggiunge o toglie l'oggetto dalla lista desideri senza ricaricare la pagina.
 * Se la richiesta non va, il modulo parte da solo come un modulo normale.
 */
document.addEventListener('click', async (e) => {
  const btn = e.target.closest('[data-wish]');
  if (!btn) return;
  const form = btn.closest('form');
  if (!form) return;
  e.preventDefault();
  e.stopPropagation();
  btn.disabled = true;
  try {
    const res = await fetch(form.getAttribute('action') || location.href, {
      method: 'POST', body: new FormData(form), headers: { Accept: 'application/json' }, credentials: 'same-origin',
    });
    if (!res.ok) throw new Error(res.status);
    const { on, n } = await res.json();
    btn.classList.toggle('is-on', on);
    btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    btn.title = on ? 'Togli dagli obiettivi' : 'Aggiungi agli obiettivi';
    const icon = btn.querySelector('i');
    if (icon) icon.className = 'ti ti-heart' + (on ? '-filled' : '');
    const count = btn.querySelector('[data-wish-n]');
    if (count) count.textContent = n;
  } catch (err) {
    form.submit();
  } finally {
    btn.disabled = false;
  }
}, true);
