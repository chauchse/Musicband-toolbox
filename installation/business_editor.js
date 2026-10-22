/* Formulaires métier : aucun stockage de coordonnées dans le navigateur. */
(() => {
  const form = document.querySelector('[data-business-editor]');
  if (!form) return;
  for (const checkbox of form.querySelectorAll('[data-boolean-touch]')) {
    if (checkbox.hasAttribute('data-empty-boolean')) checkbox.indeterminate = true;
    checkbox.addEventListener('change', () => {
      const touched = form.elements.namedItem(checkbox.dataset.booleanTouch);
      if (touched) touched.value = '1';
      checkbox.closest('label')?.querySelector('small')?.remove();
    });
  }
  if (form.dataset.photonUrl) {
    for (const field of form.querySelectorAll('textarea[name="adresse"],input[name="adresse"],input[name="new_client_adresse"],[data-photon-address]')) {
      const box = document.createElement('div');
      const note = document.createElement('p');
/*       note.append('Suggestions Photon lors de la saisie · ');
      const credit = document.createElement('a');
      credit.href = 'https://www.openstreetmap.org/copyright';
      credit.textContent = '© contributeurs OpenStreetMap'; credit.target = '_blank'; credit.rel = 'noopener noreferrer';
      note.append(credit); */
      const status = document.createElement('p'); status.setAttribute('role', 'status');
      const results = document.createElement('div'); results.setAttribute('aria-label', 'Adresses proposées');
      box.append(note, status, results);
      const label = field.closest('label');
      const group = document.createElement('div');
      label.before(group); group.append(label, box);
      let selecting = false, timer, controller, generation = 0;
      const clear = () => {
        clearTimeout(timer); controller?.abort(); ++generation;
        results.replaceChildren(); status.textContent = '';
      };
      const search = async () => {
        if (field.disabled || !field.isConnected) return;
        const query = field.value.trim();
        if (query.length < 3 || query.length > 200) return;
        controller = new AbortController(); const pending = controller; const current = generation;
        const timeout = setTimeout(() => pending.abort(), 7000);
        const url = new URL(form.dataset.photonUrl);
        url.searchParams.set('q', query); url.searchParams.set('limit', '5'); url.searchParams.set('lang', 'fr');
        status.textContent = 'Recherche d’adresses…';
        try {
          const response = await fetch(url, {signal: pending.signal, credentials: 'omit', cache: 'no-store', referrerPolicy: 'no-referrer', redirect: 'error'});
          if (!response.ok) throw new Error('Service indisponible');
          const data = await response.json();
          if (current !== generation || field.disabled) return;
          if (!Array.isArray(data.features)) throw new Error('Réponse invalide');
          const labels = new Set();
          for (const feature of data.features.slice(0, 5)) {
            const p = feature?.properties;
            if (!p || typeof p !== 'object') continue;
            const text = key => typeof p[key] === 'string' || typeof p[key] === 'number' ? String(p[key]).trim() : '';
            const street = [text('housenumber'), text('street')].filter(Boolean).join(' ');
            const label = [...new Set([text('name') !== text('street') ? text('name') : '', street,
              [text('postcode'), text('city') || text('town') || text('village')].filter(Boolean).join(' '), text('country')].filter(Boolean))].join(', ');
            if (!label || label.length > 1000 || labels.has(label)) continue;
            labels.add(label);
            const choice = document.createElement('button'); choice.type = 'button'; choice.textContent = label;
            choice.addEventListener('click', () => {
              if (field.disabled) return;
              clear(); selecting = true; field.value = label;
              field.dispatchEvent(new Event('input', {bubbles: true}));
              field.dispatchEvent(new Event('change', {bubbles: true}));
              selecting = false; field.focus(); status.textContent = 'Adresse sélectionnée ; vérifier puis enregistrer la fiche.';
            });
            results.append(choice);
          }
          status.textContent = labels.size ? 'Choisissez une adresse proposée ou conservez votre saisie.' : 'Aucune adresse proposée. Vous pouvez conserver votre saisie.';
        } catch (error) {
          if (current === generation) status.textContent = 'Suggestions indisponibles. La saisie manuelle reste possible.';
        } finally { clearTimeout(timeout); }
      };
      field.addEventListener('input', () => {
        if (selecting) return;
        clear(); timer = setTimeout(search, 600);
      });
      field.addEventListener('keydown', event => { if (event.key === 'Escape') clear(); });
      form.elements.namedItem('client_mode')?.addEventListener('change', clear);
    }
  }
  const type = form.querySelector('select[name="type_document"]');
  const reference = form.querySelector('[data-reference-hint]');
  const updateReference = () => { if (reference && type) reference.textContent = type.value + ' ' + reference.dataset.year + '-… (attribuée au premier enregistrement)'; };
  type?.addEventListener('change',updateReference);
  updateReference();
  const mode = form.elements.namedItem('client_mode');
  const contact = form.elements.namedItem('contact_id');
  const concert = form.elements.namedItem('concert_id');
  if (form.dataset.businessEditor === 'concerts') {
    const syncClient = () => {
      const fresh = mode?.value === 'new';
      const option = contact?.selectedOptions[0];
      const clientField = form.elements.namedItem('client');
      const contactField = form.elements.namedItem('contact');
      if (clientField) clientField.value = fresh ? (form.elements.namedItem('new_client_nom')?.value || '') : (option?.dataset.client || '');
      if (contactField) contactField.value = fresh ? '' : (option?.dataset.contact || '');
    };
    contact?.addEventListener('change', syncClient);
    mode?.addEventListener('change', syncClient);
    form.elements.namedItem('new_client_nom')?.addEventListener('input', syncClient);
  }
  const preview = document.getElementById('document-source-preview');
  const status = document.getElementById('document-source-status');
  let request, generation = 0;
  const updateDraftClient = () => {
    if (mode?.value !== 'new' || !preview) return;
    for (const [key, input] of [['client','new_client_nom'],['adresse','new_client_adresse']]) {
      const field = preview.querySelector('[data-derived="' + key + '"]');
      if (field) field.textContent = form.elements.namedItem(input)?.value || '—';
    }
  };
  const documentContacts = preview && contact ? [...contact.options] : null;
  const documentConcerts = preview && concert ? [...concert.options] : null;
  const filterDocumentSelections = () => {
    if (!documentContacts || !documentConcerts) return false;
    const beforeContact = contact.value, beforeConcert = concert.value;
    const invoice = type?.value === 'Facture';
    const freshOption = mode?.querySelector('option[value="new"]');
    if (freshOption) freshOption.disabled = invoice;
    if (invoice && mode?.value === 'new') { mode.value = 'existing'; contact.value = ''; }
    const original = () => mode?.value !== 'new' && type?.value === preview.dataset.originalType && contact.value === preview.dataset.originalContact && concert.value === preview.dataset.originalConcert;
    const keep = original(), selectedContact = contact.value;
    const contacts = documentContacts.filter(option => !option.value || !invoice || option.dataset.documentInvoiceClient === '1' || (keep && option.value === preview.dataset.originalContact));
    for (const option of contacts) { option.disabled = false; option.hidden = false; }
    contact.replaceChildren(...contacts);
    contact.value = contacts.some(option => option.value === selectedContact) ? selectedContact : '';
    const client = mode?.value === 'new' ? '' : contact.value;
    const selectedConcert = concert.value, preserveConcert = original();
    const concerts = documentConcerts.filter(option => !option.value || (option.dataset.documentDateOk === '1' && (!option.dataset.documentConcertClient || option.dataset.documentConcertClient === client)) || (preserveConcert && option.value === preview.dataset.originalConcert));
    for (const option of concerts) { option.disabled = false; option.hidden = false; }
    concert.replaceChildren(...concerts);
    concert.value = concerts.some(option => option.value === selectedConcert) ? selectedConcert : '';
    return (beforeContact !== '' && beforeContact !== contact.value) || (beforeConcert !== '' && beforeConcert !== concert.value);
  };
  const update = async () => {
    const selectionChanged = filterDocumentSelections();
    const create = mode?.value === 'new';
    // L'identifiant SQL sélectionné détermine le lien ; aucune résolution par nom.
    for (const [key, field, route, label] of [
      ['contact_id', contact, 'contacts.php', 'Ouvrir le client'],
      ['concert_id', concert, 'concerts.php', 'Ouvrir le concert']
    ]) {
      const link = form.querySelector('[data-document-relation="' + key + '"]');
      if (!link) continue;
      const id = key === 'contact_id' && create ? '' : (field?.value || '');
      link.hidden = !id;
      if (id) { link.href = new URL(route + '?id=' + encodeURIComponent(id), form.action).href; link.textContent = label + ' #' + id; }
      else link.removeAttribute('href');
    }
    const existing = form.querySelector('[data-existing-client]');
    const fresh = form.querySelector('[data-new-client]');
    if (existing) { existing.hidden = create; for (const field of existing.querySelectorAll('select,input')) field.disabled = create; }
    if (fresh) { fresh.hidden = !create; for (const field of fresh.querySelectorAll('input')) field.disabled = !create; }
    if (!preview) return;
    request?.abort(); request = new AbortController(); const current = ++generation;
    const url = new URL(form.action); url.search = '';
    url.searchParams.set('sources', '1');
    url.searchParams.set('contact_id', create ? '' : (contact?.value || ''));
    url.searchParams.set('concert_id', concert?.value || '');
    status.textContent = 'Lecture des fiches liées…';
    try {
      const response = await fetch(url, {credentials:'same-origin', signal:request.signal, headers:{Accept:'application/json'}});
      if (response.redirected || !response.headers.get('content-type')?.includes('application/json')) throw new Error('Reconnectez-vous pour actualiser les informations.');
      const data = await response.json();
      if (!response.ok || typeof data.html !== 'string') throw new Error(data.error || 'Lecture impossible.');
      if (current !== generation) return;
      const incoming = document.createElement('template'); incoming.innerHTML = data.html;
      for (const field of preview.querySelectorAll('[data-derived]')) {
        const next = incoming.content.querySelector('[data-derived="' + field.dataset.derived + '"]');
        field.textContent = next?.textContent || 'Non renseigné';
      }
      updateDraftClient();
      status.textContent = (selectionChanged ? 'Le choix précédent était hors critères : sélectionner un client ou un concert compatible. ' : '') + 'Informations reprises des fiches ; l’enregistrement les vérifie à nouveau.';
    } catch (error) {
      if (current !== generation || error.name === 'AbortError') return;
      for (const field of preview.querySelectorAll('[data-derived]')) field.textContent = 'Non renseigné';
      status.textContent = 'Aperçu non actualisé. ' + error.message;
    }
  };
  type?.addEventListener('change',update);
  mode?.addEventListener('change',update);
  contact?.addEventListener('change',update);
  concert?.addEventListener('change',update);
  for (const name of ['new_client_nom','new_client_adresse']) form.elements.namedItem(name)?.addEventListener('input',updateDraftClient);
  // Relire les fiches au retour de leur onglet, sans toucher à la saisie libre.
  if (preview) {
    window.addEventListener('focus', update);
    window.addEventListener('pageshow', event => { if (event.persisted) update(); });
  }
  update();
})();
