/* Recherche avancée Morceaux : QueryBuilder local, critères contrôlés à nouveau en PHP. */
/* Interface QueryBuilder des filtres avancés, configurée par le contrôleur PHP de la page.
 * Dépend de jQuery/QueryBuilder locaux et des attributs de formulaire fournis par le serveur.
 * Sérialise des critères de recherche ; PHP revalide champs, opérateurs, types et droits avant toute requête.
 */
(()=>{
  const config=window.MusicbandAdvancedFilters||window.MusicbandSongFilters;
  if(!config)return;
  const prefix=config.prefix||'songs', byId=suffix=>document.getElementById(prefix+'-'+suffix);
  const form=byId('search');
  if(!form)return;
  const collapsible=form.closest('.Musicband-search-collapsible');
  const panel=byId('advanced');
  const toggle=byId('advanced-toggle'), hidden=form.querySelector('[name="rules"]');
  const error=byId('filter-error'), builder=jQuery('#'+prefix+'-builder');
  const escape=value=>{const node=document.createElement('span');node.textContent=String(value);return node.innerHTML.replaceAll('"','&quot;').replaceAll("'",'&#39;');};
  const filters=config.filters.map((field,index)=>({
    id:field.id,label:escape(field.label),type:field.kind==='int'?'integer':'string',operators:field.operators,input_event:'input',
    input:(rule,name)=>{
      if(field.kind==='bool')return '<select name="'+escape(name)+'" aria-label="Valeur"><option value="1">Oui</option><option value="0">Non</option></select>';
      const list=prefix+'-values-'+index, type=field.kind==='date'?'date':field.kind==='datetime'?'datetime-local':'text';
      const hint=field.kind==='duration'?'M:SS ou secondes':field.kind==='int'?'Nombre':'Choisir ou saisir une valeur';
      return '<input type="'+type+'" name="'+escape(name)+'"'+(field.kind==='datetime'?' step="1"':'')+' list="'+list+'" maxlength="6000" aria-label="Valeur pour '+escape(field.label)+'" placeholder="'+hint+'">';
    }
  }));
  // Datalists construits avec le DOM : les commentaires et libellés restent du texte.
  config.filters.forEach((field,index)=>{
    const list=document.createElement('datalist');list.id=prefix+'-values-'+index;
    field.choices.forEach(choice=>{const option=document.createElement('option');option.value=choice.value;option.label=choice.label;list.append(option);});
    panel.append(list);
  });
  const announce=()=>{
    builder.find('[data-not="group"]').each(function(){this.setAttribute('aria-pressed',String(this.classList.contains('active')));this.title='NON : inverser toutes les conditions de ce groupe';});
    builder.find('.rule-filter-container select').attr('aria-label','Attribut');
    builder.find('.rule-operator-container select').attr('aria-label','Comparaison');
    builder.find('.error-container').each(function(){this.textContent=this.closest('.has-error')?'Vérifier cette condition':'';});
  };
  try{
    builder.on('rulesChanged.queryBuilder afterUpdateGroupNot.queryBuilder validationError.queryBuilder',announce);
    const operators=['equal','not_equal','contains','not_contains','is_null','is_not_null',...['less','less_or_equal','greater','greater_or_equal'].map(type=>({type,nb_inputs:1,multiple:false,apply_to:['string','number','datetime']}))];
    builder.queryBuilder({filters,operators,lang_code:'fr',plugins:['not-group'],allow_groups:3,allow_empty:true,
      select_placeholder:'Choisir un attribut',lang:{add_rule:'+ Condition',add_group:'+ Groupe',NOT:'NON (NOT)',conditions:{AND:'ET (AND)',OR:'OU (OR)'},operators:{is_null:'non renseigné',is_not_null:'renseigné'}},
      rules:config.rules});
    toggle.hidden=false;
    const show=open=>{panel.hidden=!open;toggle.setAttribute('aria-expanded',String(open));toggle.textContent=(open?'−':'+')+' Recherche avancée'+(hidden.value?' · active':'');};
    show(Boolean(hidden.value));toggle.addEventListener('click',()=>show(panel.hidden));

    // Animation fluide et respect de l'accessibilité pour la section repliable.
    if(collapsible){
      const summary=collapsible.querySelector('.Musicband-search-header'), body=collapsible.querySelector('.Musicband-search-body');
      let animating=false;
      if(summary&&body){
        summary.addEventListener('click',event=>{
          if(event.target.closest('a, button')||window.matchMedia('(prefers-reduced-motion: reduce)').matches)return;
          event.preventDefault();
          if(animating)return;
          if(collapsible.open){
            animating=true;
            const start=body.offsetHeight;
            body.style.overflow='hidden';body.style.height=start+'px';body.style.opacity='1';
            body.style.transition='height 0.2s ease, opacity 0.15s ease';
            body.offsetHeight;
            body.style.height='0px';body.style.opacity='0';
            const onEnd=()=>{body.removeEventListener('transitionend',onEnd);collapsible.removeAttribute('open');body.style.removeProperty('height');body.style.removeProperty('opacity');body.style.removeProperty('overflow');body.style.removeProperty('transition');animating=false;};
            body.addEventListener('transitionend',onEnd);
          }else{
            animating=true;
            collapsible.setAttribute('open','');
            const target=body.offsetHeight;
            body.style.overflow='hidden';body.style.height='0px';body.style.opacity='0';
            body.style.transition='height 0.2s ease, opacity 0.2s ease';
            body.offsetHeight;
            body.style.height=target+'px';body.style.opacity='1';
            const onEnd=()=>{body.removeEventListener('transitionend',onEnd);body.style.removeProperty('height');body.style.removeProperty('opacity');body.style.removeProperty('overflow');body.style.removeProperty('transition');animating=false;};
            body.addEventListener('transitionend',onEnd);
          }
        });
      }
    }

    form.addEventListener('submit',event=>{
      error.hidden=true;
      const rules=builder.queryBuilder('getRules');
      if(!rules){event.preventDefault();show(true);if(collapsible)collapsible.setAttribute('open','');error.hidden=false;error.textContent='Compléter ou supprimer les conditions incomplètes.';return;}
      const size=node=>1+(node.rules||[]).reduce((n,child)=>n+size(child),0);
      if(size(rules)>100){event.preventDefault();show(true);if(collapsible)collapsible.setAttribute('open','');error.hidden=false;error.textContent='Limiter la recherche à 100 conditions et groupes.';return;}
      // Une racine vide signifie aucune recherche avancée.
      const compact=node=>node.rules?{condition:node.condition,not:Boolean(node.not),rules:node.rules.map(compact)}:{id:node.id,operator:node.operator,value:node.value};
      hidden.value=rules.rules?.length?JSON.stringify(compact(rules)):'';
      if(hidden.value.length>20000){event.preventDefault();error.hidden=false;error.textContent='Réduire le nombre ou la longueur des critères.';show(true);if(collapsible)collapsible.setAttribute('open','');return;}
    });
    byId('filter-clear').addEventListener('click',()=>{builder.queryBuilder('setRules',{condition:'AND',not:false,rules:[]});hidden.value='';error.hidden=true;form.requestSubmit();});
    const store=byId('filter-store');
    const save=byId('filter-save'), remove=byId('filter-delete');
    // Les boutons portent sur le dernier résultat appliqué, jamais sur une modification en attente.
    builder.on('rulesChanged.queryBuilder afterUpdateGroupNot.queryBuilder',()=>{if(save)save.hidden=true;if(remove)remove.hidden=true;});
    if(store&&save)save.addEventListener('click',()=>{
      const name=window.prompt('Nom du filtre (80 caractères maximum)');
      if(name===null)return;
      const clean=name.trim();
      if(!clean||[...clean].length>80){error.hidden=false;error.textContent='Choisir un nom de 1 à 80 caractères.';return;}
      store.elements.filter_name.value=clean;store.elements.action.value='filter_save';save.disabled=true;store.requestSubmit();
    });
    if(store&&remove)remove.addEventListener('click',()=>{
      if(!window.confirm('Supprimer le filtre « '+remove.dataset.filterName+' » pour tous les utilisateurs ? Les données métier seront conservées.'))return;
      store.elements.action.value='filter_delete';store.elements.confirm_filter.value='yes';remove.disabled=true;store.requestSubmit();
    });
    announce();
  }catch(e){
    // Une panne du constructeur ne doit pas bloquer la recherche simple ou effacer les critères en cours.
    if(collapsible)collapsible.setAttribute('open','');
    panel.hidden=false;error.hidden=false;error.textContent='Recherche avancée indisponible. Recharger la page ou effacer les filtres avec le lien ci-dessous.';
  }
})();
