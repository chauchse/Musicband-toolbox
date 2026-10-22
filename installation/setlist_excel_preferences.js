/* Préférences Excel : persistance automatique après SortableJS / commandes clavier.
 * Réutilise le formulaire et les positions de field_layout_editor.js ; POST CSRF sérialisés.
 * Aucune préférence dans le stockage du navigateur ; Enregistrer/réinitialiser utilisent le POST natif.
 */
(() => {
  const form = document.querySelector('[data-excel-preferences]');
  if (!form) return;
  const status = form.querySelector('[data-navigation-status]');
  let timer, nativeSubmission = false, queue = Promise.resolve();
  const save = () => {
    clearTimeout(timer);
    const data = new FormData(form);
    data.set('excel_async', '1');
    // Chaque état est envoyé dans l’ordre, afin qu’une réponse lente ne restaure pas l’ancien.
    queue = queue.catch(() => {}).then(async () => {
      if (nativeSubmission) return;
      status.textContent = 'Enregistrement…';
      // Le champ caché name=action masque form.action : lire l’attribut, jamais la propriété.
      const target = form.getAttribute('action') || window.location.href;
      const response = await fetch(target, {method: 'POST', credentials: 'same-origin', body: new URLSearchParams(data)});
      if (!response.headers.get('Content-Type')?.includes('application/json')) throw new Error(`Enregistrement refusé (HTTP ${response.status}) : rechargez la page et vérifiez la session.`);
      const result = await response.json();
      if (!response.ok || !result.ok) throw new Error(result.error || 'Enregistrement impossible.');
      status.textContent = 'Préférences Excel enregistrées.';
    }).catch(error => {
      const reason = error instanceof TypeError ? 'requête bloquée ou connexion interrompue.' : (error.message || 'réponse du serveur indisponible.');
      status.textContent = 'Enregistrement automatique non confirmé : ' + reason + ' Utilisez Enregistrer pour envoyer le formulaire directement.';
    });
  };
  const schedule = () => { if (nativeSubmission) return; clearTimeout(timer); timer = setTimeout(() => save(), 400); };
  form.addEventListener('change', schedule);
  new MutationObserver(schedule).observe(form.querySelector('[data-navigation-items]'), {childList: true});
  // Une panne de fetch ne doit pas bloquer aussi le bouton de secours.
  // Attendre l’écriture en cours et ignorer les suivantes pour que le dernier état gagne.
  form.addEventListener('submit', event => {
    event.preventDefault();
    if (nativeSubmission) return;
    nativeSubmission = true;
    clearTimeout(timer);
    if (event.submitter?.name === 'excel_reset') {
      const reset = document.createElement('input');
      reset.type = 'hidden'; reset.name = 'excel_reset'; reset.value = '1'; form.append(reset);
    }
    status.textContent = 'Envoi du formulaire…';
    queue.finally(() => HTMLFormElement.prototype.submit.call(form));
  });
})();
