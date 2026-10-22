/* Organisation visuelle ; le déplacement est assuré par SortableJS. */
/* Éditeur des ordres de navigation et des groupes/champs produits par les formulaires PHP.
 * Dépend de SortableJS déjà chargé ; les boutons clavier synchronisent les mêmes champs de formulaire.
 * Déplacer modifie seulement le DOM ; la persistance exige l’enregistrement explicite contrôlé en PHP.
 */
(() => {
  if (typeof Sortable === 'undefined') return;
  // Même SortableJS que les groupes de champs ; contrat POST et stockage conservés.
  document.querySelectorAll('[data-navigation-order]').forEach(form => {
    const list = form.querySelector('[data-navigation-items]');
    const status = form.querySelector('[data-navigation-status]');
    if (!list) return;
    const controls = new Map();
    // Les sélecteurs cachés portent le contrat POST utilisé aussi sans glisser-déposer.
    const sync = () => {
      const rows = [...list.children];
      rows.forEach((row, index) => {
        const sel = row.querySelector('select[name^="positions["]');
        if (sel) sel.value = String(index + 1);
        const ctrl = controls.get(row);
        if (ctrl) {
          ctrl.up.disabled = index === 0;
          ctrl.down.disabled = index === rows.length - 1;
        }
      });
    };
    const changed = () => { sync(); if (status) status.textContent = 'Ordre modifié ; enregistrez pour le conserver.'; };
    for (const row of list.children) {
      const labelEl = row.querySelector('[data-drag-label]') || row.querySelector('legend');
      const name = labelEl ? labelEl.textContent.trim() : (row.dataset.module || 'Entrée');
      const posSelect = row.querySelector('select[name^="positions["]');
      if (posSelect) {
        const posLabel = posSelect.closest('label');
        if (posLabel) posLabel.hidden = true;
      }
      const actions = document.createElement('div'); actions.className = 'navigation-order-controls';
      const button = (text, label) => {
        const item = document.createElement('button'); item.type = 'button'; item.textContent = text;
        item.setAttribute('aria-label', label + ' : ' + name); actions.append(item); return item;
      };
      const handle = button('↕', 'Déplacer'); handle.className = 'navigation-order-handle';
      handle.title = 'Glisser pour déplacer ; utiliser Monter ou Descendre au clavier';
      const up = button('↑', 'Monter'), down = button('↓', 'Descendre');
      up.addEventListener('click', () => { if (row.previousElementSibling) { list.insertBefore(row, row.previousElementSibling); changed(); up.focus(); } });
      down.addEventListener('click', () => { if (row.nextElementSibling) { list.insertBefore(row.nextElementSibling, row); changed(); down.focus(); } });
      controls.set(row, {up, down});
      const orderCell = row.querySelector('[data-order-cell]');
      if (orderCell) {
        orderCell.append(actions);
      } else if (labelEl) {
        labelEl.after(actions);
      } else {
        row.prepend(actions);
      }
    }
    new Sortable(list, {animation: 150, draggable: '[data-navigation-item]', handle: '.navigation-order-handle',
      delay: 180, delayOnTouchOnly: true, touchStartThreshold: 4, onEnd: changed});
    form.addEventListener('submit', sync); sync();

    // Gestion du sélecteur d'icônes avec retour visuel immédiat
    const iconsScript = document.getElementById('Musicband-icons-catalog-data');
    let iconsCatalog = null;
    if (iconsScript) {
      try { iconsCatalog = JSON.parse(iconsScript.textContent); } catch (e) {}
    }
    form.querySelectorAll('[data-icon-select]').forEach(select => {
      select.addEventListener('change', () => {
        const iconKey = select.value;
        const row = select.closest('tr') || select.closest('fieldset');
        const pathEl = row?.querySelector('.module-icon-preview svg path');
        if (pathEl && iconsCatalog && iconsCatalog.icons && iconsCatalog.icons[iconKey]) {
          pathEl.setAttribute('d', iconsCatalog.icons[iconKey].path);
        }
      });
    });

    // Synchronisation du libellé d'accessibilité lors de la saisie inline du nom d'affichage
    form.querySelectorAll('.module-display-name-input').forEach(input => {
      input.addEventListener('input', () => {
        const row = input.closest('tr') || input.closest('fieldset');
        const dragLabel = row?.querySelector('[data-drag-label]');
        if (dragLabel) dragLabel.textContent = input.value;
      });
    });
  });
  const reveal = input => {
    for (let parent = input.parentElement; parent; parent = parent.parentElement) if (parent.tagName === 'DETAILS') parent.open = true;
  };
  const modules = new Map();
  document.querySelectorAll('[data-field-editor]').forEach(form => {
    const options = new Map();
    form.querySelectorAll('[data-field-option]').forEach(row => {
      const entry = {row, label: row.querySelector('[data-option-label]'), visible: row.querySelector('[data-option-visible]'), copies: []};
      const update = () => {
        for (const copy of entry.copies) {
          copy.label.value = entry.label.value; copy.visible.checked = entry.visible.checked;
          copy.label.setCustomValidity('');
          copy.card.classList.toggle('field-is-hidden', !entry.visible.checked);
        }
      };
      entry.update = update;
      entry.label.addEventListener('input', update); entry.visible.addEventListener('change', update);
      options.set(row.dataset.fieldOption, entry);
    });
    modules.set(form, options);
  });
  document.querySelectorAll('[data-field-layout]').forEach((panel, formIndex) => {
    const form = panel.closest('form');
    const cards = [...panel.querySelectorAll('.field-layout-setting')];
    if (!cards.length) return;
    const board = document.createElement('div'); board.className = 'layout-board';
    const status = document.createElement('p'); status.setAttribute('role', 'status');
    const note = document.createElement('p');
    note.textContent = 'Glissez les poignées pour déplacer les groupes et les champs. Les flèches et le choix du groupe permettent aussi de les déplacer. Enregistrez le module pour appliquer les changements.';
    panel.insertBefore(note, cards[0]); panel.insertBefore(board, cards[0]);
    const groupNodes = () => [...board.children];
    const items = group => group.querySelector('[data-layout-items]');
    const records = new Map();
    const button = (text, label, action) => {
      const node = document.createElement('button'); node.type = 'button'; node.textContent = text;
      node.setAttribute('aria-label', label); node.title = label;
      if (action) node.addEventListener('click', action);
      return node;
    };
    const sync = () => {
      const groups = groupNodes();
      groups.forEach((group, index) => {
        const title = group.querySelector('[data-layout-title]').value;
        group.querySelector('[data-layout-number]').textContent = 'Groupe ' + (index + 1);
        group.querySelector('[data-group-up]').disabled = index === 0;
        group.querySelector('[data-group-down]').disabled = index === groups.length - 1;
        group.querySelector('[data-group-remove]').disabled = items(group).children.length > 0;
        [...items(group).children].forEach((card, rank) => {
          const record = records.get(card);
          record.group.value = index + 1; record.rank.value = rank + 1; record.title.value = title;
          record.position.textContent = ' · ' + (rank + 1);
          record.up.disabled = rank === 0;
          record.down.disabled = rank === items(group).children.length - 1;
          record.target.replaceChildren();
          groups.forEach((destination, targetIndex) => {
            const option = document.createElement('option'); option.value = targetIndex;
            option.textContent = 'Groupe ' + (targetIndex + 1) + (destination.querySelector('[data-layout-title]').value ? ' · ' + destination.querySelector('[data-layout-title]').value : ' · sans titre');
            option.selected = destination === group; record.target.append(option);
          });
        });
      });
    };
    const changed = () => { sync(); status.textContent = 'Disposition modifiée ; enregistrez le module pour la conserver.'; };
    const move = (node, direction) => {
      const sibling = direction < 0 ? node.previousElementSibling : node.nextElementSibling;
      if (!sibling) return;
      const focused = document.activeElement;
      if (direction < 0) sibling.before(node); else sibling.after(node);
      changed();
      if (node.contains(focused)) {
        if (!focused.disabled) focused.focus(); else node.querySelector('button:not([disabled])')?.focus();
      }
    };
    const sortableOptions = {
      animation: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 150,
      delay: 180, delayOnTouchOnly: true, touchStartThreshold: 4,
      fallbackOnBody: true, ghostClass: 'layout-ghost', chosenClass: 'layout-chosen', onEnd: changed
    };
    const createGroup = title => {
      const group = document.createElement('section'); group.className = 'layout-editor-group';
      const heading = document.createElement('div'); heading.className = 'layout-tools';
      const grip = button('⠿', 'Glisser pour déplacer ce groupe'); grip.className = 'layout-group-handle';
      const number = document.createElement('strong'); number.setAttribute('data-layout-number', '');
      const up = button('↑', 'Monter ce groupe', () => move(group, -1)); up.setAttribute('data-group-up', '');
      const down = button('↓', 'Descendre ce groupe', () => move(group, 1)); down.setAttribute('data-group-down', '');
      const remove = button('Supprimer le groupe vide', 'Supprimer ce groupe vide', () => {
        if (items(group).children.length) return;
        Sortable.get(items(group))?.destroy(); group.remove(); changed(); add.focus();
      }); remove.setAttribute('data-group-remove', '');
      heading.append(grip, number, up, down, remove);
      const label = document.createElement('label'); label.textContent = 'Titre du groupe (facultatif)';
      const input = document.createElement('input'); input.maxLength = 160; input.value = title;
      input.setAttribute('data-layout-title', ''); input.addEventListener('input', changed); label.append(input);
      const list = document.createElement('div'); list.setAttribute('data-layout-items', '');
      group.append(heading, label, list); board.append(group);
      new Sortable(list, {...sortableOptions, group: 'Musicband-fields-' + formIndex, draggable: '.layout-card', handle: '.layout-field-handle'});
      return group;
    };
    const initialGroups = new Map();
    cards.forEach(card => {
      const groupInput = card.querySelector('input[name$="[group]"]');
      const rankInput = card.querySelector('input[name$="[rank]"]');
      const titleInput = card.querySelector('input[name$="[title]"]');
      if (!initialGroups.has(groupInput.value)) initialGroups.set(groupInput.value, createGroup(titleInput.value));
      const group = initialGroups.get(groupInput.value);
      card.classList.add('layout-card');
      for (const label of card.querySelectorAll('label')) label.hidden = true;
      const legend = card.querySelector('legend');
      const fieldName = legend.textContent; const position = document.createElement('small'); legend.append(position);
      const option = modules.get(form)?.get(card.dataset.fieldKey);
      if (option) {
        const fields = document.createElement('div'); fields.className = 'field-card-options';
        const visibleLabel = document.createElement('label'); visibleLabel.className = 'field-option-visible';
        const visible = document.createElement('input'); visible.type = 'checkbox'; visible.disabled = option.visible.disabled;
        visibleLabel.append(visible, visible.disabled ? ' Affiché · indispensable' : ' Affiché dans toutes les vues');
        const nameLabel = document.createElement('label'); nameLabel.textContent = 'Nom affiché';
        const label = document.createElement('input'); label.maxLength = 160; label.placeholder = option.label.placeholder;
        const edited = () => { option.update(); status.textContent = 'Champs modifiés ; enregistrez le module pour les conserver.'; };
        label.addEventListener('input', () => { option.label.value = label.value; edited(); });
        visible.addEventListener('change', () => { option.visible.checked = visible.checked; edited(); });
        nameLabel.append(label); fields.append(visibleLabel, nameLabel); card.append(fields);
        option.copies.push({card, label, visible}); option.update();
      }
      const controls = document.createElement('div'); controls.className = 'layout-tools';
      const grip = button('⠿', 'Glisser pour déplacer : ' + fieldName); grip.className = 'layout-field-handle';
      const up = button('↑', 'Monter : ' + fieldName, () => move(card, -1));
      const down = button('↓', 'Descendre : ' + fieldName, () => move(card, 1));
      const targetLabel = document.createElement('label'); targetLabel.textContent = 'Déplacer vers';
      const target = document.createElement('select'); target.setAttribute('aria-label', 'Groupe de : ' + fieldName);
      target.addEventListener('change', () => {
        const destination = groupNodes()[Number(target.value)];
        if (destination) { items(destination).append(card); changed(); target.focus(); }
      });
      targetLabel.append(target); controls.append(grip, up, down, targetLabel); card.append(controls);
      records.set(card, {group: groupInput, rank: rankInput, title: titleInput, position, up, down, target});
      items(group).append(card);
    });
    const add = button('Ajouter un groupe', 'Ajouter un groupe sans titre', () => {
      if (groupNodes().length >= 999) return;
      const group = createGroup(''); changed(); group.querySelector('[data-layout-title]').focus();
    });
    board.after(add, status);
    new Sortable(board, {...sortableOptions, draggable: '.layout-editor-group', handle: '.layout-group-handle'});
    form.addEventListener('submit', event => {
      if (event.submitter?.name === 'layout_reset') return;
      sync();
      for (const input of board.querySelectorAll('[data-layout-title]')) {
        input.setCustomValidity(new TextEncoder().encode(input.value).length > 160 ? 'Titre limité à 160 octets (les accents peuvent compter pour plusieurs octets).' : '');
        if (!input.checkValidity()) { reveal(input); input.reportValidity(); event.preventDefault(); return; }
      }
    });
    sync();
  });
  for (const [form, options] of modules) {
    for (const entry of options.values()) if (entry.copies.length) entry.row.hidden = true;
    const other = form.querySelector('[data-field-options]');
    other.open = false;
    other.hidden = [...options.values()].every(entry => entry.copies.length);
    other.querySelector('summary').textContent = 'Autres champs · hors des vues configurables';
    other.querySelector('p').textContent = 'Réglages des champs qui ne figurent pas dans les vues ci-dessous, par exemple les identifiants des sources importées.';
    form.addEventListener('submit', event => {
      if (event.submitter?.name === 'layout_reset') return;
      for (const entry of options.values()) {
        const input = entry.copies[0]?.label || entry.label;
        input.setCustomValidity(new TextEncoder().encode(entry.label.value).length > 160 || /[\x00-\x1f\x7f]/.test(entry.label.value) ? 'Nom limité à une ligne et 160 octets.' : '');
        if (!input.checkValidity()) { reveal(input); input.reportValidity(); event.preventDefault(); return; }
      }
    });
  }
})();
