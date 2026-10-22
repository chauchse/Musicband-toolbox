/* Recherche progressive et préférences de colonnes ; aucune donnée métier stockée localement. */
/* Complément des listes rendues par business_list.php : recherche HTTP et choix de colonnes.
 * Seuls les identifiants des colonnes sont conservés en localStorage ; les résultats restent côté serveur.
 * AbortController et compteur de génération empêchent une réponse ancienne de remplacer la recherche courante.
 */
(() => {
  const root = document.getElementById('business-list');
  if (!root) return;
  const input = document.getElementById('business-query');
  const form = document.getElementById('business-search');
  const results = document.getElementById('business-results');
  const status = document.getElementById('business-search-status');
  const toggles = [...root.querySelectorAll('[data-toggle-column]')];
  const key = 'Musicband-columns-' + root.dataset.module;
  let timer, request, generation = 0;
  const applyColumns = () => {
    for (const toggle of toggles) {
      for (const cell of results.querySelectorAll('[data-column="' + toggle.dataset.toggleColumn + '"]')) cell.hidden = !toggle.checked;
    }
  };
  try {
    const saved = JSON.parse(localStorage.getItem(key));
    if (Array.isArray(saved)) for (const toggle of toggles) if (!toggle.disabled) toggle.checked = saved.includes(toggle.dataset.toggleColumn);
  } catch (_) { /* Le tableau reste utilisable si le stockage est indisponible. */ }
  for (const toggle of toggles) toggle.addEventListener('change', () => {
    applyColumns();
    try { localStorage.setItem(key, JSON.stringify(toggles.filter(t => t.checked).map(t => t.dataset.toggleColumn))); } catch (_) {}
  });
  applyColumns();

  // Section de recherche repliable : indicateur d'état, animation et résumé dynamique.
  const collapsible = document.getElementById('business-search-panel');
  const summary = collapsible?.querySelector('.Musicband-search-header');
  const body = collapsible?.querySelector('.Musicband-search-body');
  const badge = document.getElementById('business-search-badge');
  const resetBtn = collapsible?.querySelector('.Musicband-search-quick-reset');
  let animating = false;

  // Invalider aussi le numéro de génération : une réponse reçue malgré abort reste périmée.
  const invalidate = () => { clearTimeout(timer); request?.abort(); generation++; };
  const load = async (url, updateHistory = true) => {
    invalidate(); const current = generation; request = new AbortController();
    results.setAttribute('aria-busy', 'true'); status.textContent = 'Recherche en cours…';
    const clean = new URL(url); clean.searchParams.delete('ajax');
    const endpoint = new URL(clean); endpoint.searchParams.set('ajax', '1');
    try {
      const response = await fetch(endpoint, {credentials: 'same-origin', signal: request.signal, headers: {'Accept': 'application/json'}});
      if (response.redirected || !response.headers.get('content-type')?.includes('application/json')) throw new Error('Session ou réponse invalide : rechargez la page pour vous reconnecter.');
      const payload = await response.json();
      if (!response.ok || typeof payload.html !== 'string') throw new Error(payload.error || 'Recherche indisponible.');
      if (current !== generation) return;
      results.innerHTML = payload.html; applyColumns();
      status.textContent = 'Résultats actualisés.';
      if (updateHistory) history.replaceState(null, '', clean);
    } catch (error) {
      if (error.name !== 'AbortError' && current === generation) {
        status.textContent = 'Résultats non actualisés ; les précédents restent affichés. ' + error.message;
        if (collapsible) collapsible.setAttribute('open', '');
      }
    } finally { if (current === generation) results.removeAttribute('aria-busy'); }
  };
  const searchURL = () => {
    const url = new URL(location.href); url.searchParams.set('q', input.value);
    for (const key of ['page','upcoming_page','past_page','unknown_page','ajax']) url.searchParams.delete(key);
    return url;
  };

  const updateSummary = () => {
    if (!badge) return;
    const q = input.value.trim();
    if (q !== '') {
      badge.textContent = 'Filtre actif : Recherche « ' + q + ' »';
      badge.classList.add('is-active');
      if (resetBtn) resetBtn.hidden = false;
    } else {
      badge.textContent = 'Aucun filtre actif';
      badge.classList.remove('is-active');
      if (resetBtn) resetBtn.hidden = true;
    }
  };

  const collapsePanel = () => {
    if (!collapsible || !collapsible.open || !body) return;
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      collapsible.removeAttribute('open');
      return;
    }
    if (animating) return;
    animating = true;
    const start = body.offsetHeight;
    body.style.overflow = 'hidden';
    body.style.height = start + 'px';
    body.style.opacity = '1';
    body.style.transition = 'height 0.2s ease, opacity 0.15s ease';
    body.offsetHeight; // reflow
    body.style.height = '0px';
    body.style.opacity = '0';
    const onEnd = () => {
      body.removeEventListener('transitionend', onEnd);
      collapsible.removeAttribute('open');
      body.style.removeProperty('height');
      body.style.removeProperty('opacity');
      body.style.removeProperty('overflow');
      body.style.removeProperty('transition');
      animating = false;
    };
    body.addEventListener('transitionend', onEnd);
  };

  const expandPanel = () => {
    if (!collapsible || collapsible.open || !body) return;
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      collapsible.setAttribute('open', '');
      return;
    }
    if (animating) return;
    animating = true;
    collapsible.setAttribute('open', '');
    const target = body.offsetHeight;
    body.style.overflow = 'hidden';
    body.style.height = '0px';
    body.style.opacity = '0';
    body.style.transition = 'height 0.2s ease, opacity 0.2s ease';
    body.offsetHeight; // reflow
    body.style.height = target + 'px';
    body.style.opacity = '1';
    const onEnd = () => {
      body.removeEventListener('transitionend', onEnd);
      body.style.removeProperty('height');
      body.style.removeProperty('opacity');
      body.style.removeProperty('overflow');
      body.style.removeProperty('transition');
      animating = false;
    };
    body.addEventListener('transitionend', onEnd);
  };

  if (summary) {
    summary.addEventListener('click', event => {
      if (event.target.closest('a, button') || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
      event.preventDefault();
      if (collapsible.open) {
        collapsePanel();
      } else {
        expandPanel();
      }
    });
  }

  if (resetBtn) {
    resetBtn.addEventListener('click', event => {
      event.stopPropagation();
      event.preventDefault();
      input.value = '';
      updateSummary();
      load(searchURL());
    });
  }

  input.addEventListener('input', event => {
    invalidate();
    updateSummary();
    results.setAttribute('aria-busy', 'true');
    status.textContent = 'Recherche en cours…';
    if (!event.isComposing) timer = setTimeout(() => load(searchURL()), 250);
  });
  input.addEventListener('compositionend', () => {
    invalidate();
    updateSummary();
    timer = setTimeout(() => load(searchURL()), 250);
  });
  form.addEventListener('submit', event => {
    event.preventDefault();
    updateSummary();
    load(searchURL());
    // Repli automatique dès la soumission de la recherche (bouton ou touche Entrée).
    collapsePanel();
  });
  results.addEventListener('click', event => {
    const link = event.target.closest('a[data-list-page]');
    if (!link || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    event.preventDefault(); load(new URL(link.href));
  });
  window.addEventListener('popstate', () => {
    input.value = new URL(location.href).searchParams.get('q') || '';
    updateSummary();
    load(new URL(location.href), false);
  });
})();
