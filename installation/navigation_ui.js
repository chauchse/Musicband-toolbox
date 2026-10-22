/**
 * Musicband NAVIGATION UI: Commandes de navigation partagées.
 * - Remontée en haut (bouton flottant déplaçable, accessible au clavier).
 * - Navigation contextuelle entre fiches détaillées (précédent/suivant < et >, swipe mobile, conservation filtres/tri).
 * - Fermeture des menus au clavier (Escape).
 * Sans dépendance externe ni appel réseau ; persistance de contexte via sessionStorage.
 */
(()=>{
  /* =========================================================================
   * 1. Bouton flottant de remontée en haut
   * ========================================================================= */
  function initScrollTop(){
    if(document.getElementById('Musicband-scroll-top'))return;
    const button=document.createElement('button');button.id='Musicband-scroll-top';button.className='Musicband-scroll-top';button.type='button';
    button.setAttribute('aria-label','Remonter en haut. Appui long pour changer de côté.');
    button.title='Remonter en haut · Appui long : changer de côté';
    button.innerHTML='<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m5 12 7-7 7 7M12 5v15"/></svg>';
    let left=false,timer=null,longPress=false,start=null;
    try{left=localStorage.getItem('Musicband-scroll-top-side')==='left';}catch(e){}
    const place=()=>button.classList.toggle('is-left',left);place();
    const update=()=>{button.hidden=window.scrollY<320;};update();
    document.body.append(button);window.addEventListener('scroll',update,{passive:true});
    const cancel=()=>{clearTimeout(timer);timer=null;};
    button.addEventListener('pointerdown',event=>{
      if(event.button!==0)return;
      cancel();longPress=false;start={x:event.clientX,y:event.clientY};
      button.setPointerCapture(event.pointerId);
      timer=setTimeout(()=>{longPress=true;left=!left;place();try{localStorage.setItem('Musicband-scroll-top-side',left?'left':'right');}catch(e){}},700);
    });
    button.addEventListener('pointermove',event=>{if(start&&Math.hypot(event.clientX-start.x,event.clientY-start.y)>12)cancel();});
    button.addEventListener('pointerup',()=>{cancel();start=null;});
    button.addEventListener('pointercancel',()=>{cancel();start=null;longPress=false;});
    button.addEventListener('lostpointercapture',cancel);
    button.addEventListener('contextmenu',event=>event.preventDefault());
    button.addEventListener('click',event=>{
      if(longPress){event.preventDefault();longPress=false;return;}
      longPress=false;window.scrollTo({top:0,behavior:window.matchMedia('(prefers-reduced-motion: reduce)').matches?'instant':'smooth'});
    });
    button.addEventListener('keydown',event=>{if(event.key==='ArrowLeft'||event.key==='ArrowRight'){event.preventDefault();left=event.key==='ArrowLeft';place();try{localStorage.setItem('Musicband-scroll-top-side',left?'left':'right');}catch(e){}}});
  }

  /* =========================================================================
   * 2. Navigation contextuelle entre fiches détaillées (< et >, swipe, contexte)
   * ========================================================================= */
  const SUPPORTED_MODULES=['songs','concerts','contacts','devis_factures','tasks'];
  const STORAGE_PREFIX='Musicband_detail_nav_';

  function detectModule(){
    const path=window.location.pathname;
    for(const m of SUPPORTED_MODULES){
      if(path.endsWith('/'+m+'.php')||path.endsWith(m+'.php'))return m;
    }
    return null;
  }

  function getRecordId(){
    const params=new URLSearchParams(window.location.search);
    const id=params.get('id');
    if(id&&/^\d+$/.test(id))return parseInt(id,10);
    return null;
  }

  function isEditMode(){
    const params=new URLSearchParams(window.location.search);
    return params.has('edit')||params.get('action')==='edit'||params.get('action')==='new';
  }

  function getStoredContext(module){
    try{
      const raw=sessionStorage.getItem(STORAGE_PREFIX+module);
      if(!raw)return null;
      return JSON.parse(raw);
    }catch(e){return null;}
  }

  function setStoredContext(module,data){
    try{
      sessionStorage.setItem(STORAGE_PREFIX+module,JSON.stringify(data));
    }catch(e){}
  }

  function escapeHtml(str){
    if(!str)return '';
    return String(str)
      .replace(/&/g,'&amp;')
      .replace(/</g,'&lt;')
      .replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;')
      .replace(/'/g,'&#39;');
  }

  function extractListItems(module){
    const rows=document.querySelectorAll('table tbody tr');
    const items=[];
    const seenIds=new Set();
    const idRegex=/[?&]id=(\d+)/;

    rows.forEach(tr=>{
      let href=tr.getAttribute('data-song-href')||'';
      let title='';
      if(href){
        const titleLink=tr.querySelector('.song-title-choice a, td:first-child a');
        title=titleLink?titleLink.textContent.trim():'';
      }else{
        const anchor=tr.querySelector(`a[href*="${module}.php?id="], a[href*="?id="], a[href*="&id="]`);
        if(anchor){
          href=anchor.getAttribute('href');
          title=anchor.textContent.trim();
        }
      }

      if(href){
        const match=href.match(idRegex);
        if(match){
          const id=parseInt(match[1],10);
          if(!seenIds.has(id)){
            seenIds.add(id);
            if(!title){
              const ariaLabel=tr.getAttribute('aria-label');
              if(ariaLabel&&ariaLabel.startsWith('Ouvrir ')){
                title=ariaLabel.slice(7);
              }else{
                title=tr.querySelector('.Musicband-cell-value, td')?.textContent?.trim()||'';
              }
            }
            items.push({id,url:href,title});
          }
        }
      }
    });

    return items;
  }

  function handleListView(module){
    function saveContext(){
      const items=extractListItems(module);
      if(!items||items.length===0)return;
      setStoredContext(module,{
        module,
        listUrl:window.location.href,
        scrollY:window.scrollY||window.pageYOffset||0,
        items,
        updatedAt:Date.now()
      });
    }

    const existing=getStoredContext(module);
    if(existing&&existing.listUrl===window.location.href&&typeof existing.scrollY==='number'&&existing.scrollY>0){
      requestAnimationFrame(()=>{
        window.scrollTo({top:existing.scrollY,behavior:'instant'});
      });
    }

    saveContext();

    document.addEventListener('click',event=>{
      const targetLink=event.target.closest(`a[href*="${module}.php?id="], a[href*="?id="], tr[data-song-href]`);
      if(targetLink){
        saveContext();
      }
    },{capture:true});

    window.addEventListener('beforeunload',()=>{
      const cur=getStoredContext(module);
      if(cur&&cur.listUrl===window.location.href){
        cur.scrollY=window.scrollY||window.pageYOffset||0;
        setStoredContext(module,cur);
      }
    });
  }

  function setupHeaderNav(prevItem,nextItem,currentIndex,totalCount){
    const header=document.querySelector('.Musicband-header');
    if(!header)return;

    const pageHeader=document.querySelector('.Musicband-page-header')||header;
    pageHeader.classList.add('has-detail-nav');
    header.classList.add('has-detail-nav');

    let nav=header.querySelector('.Musicband-detail-nav');
    if(!nav){
      nav=document.createElement('nav');
      nav.className='Musicband-detail-nav';
      nav.setAttribute('aria-label','Navigation entre fiches');
      const helpMenu=header.querySelector('.Musicband-help-menu');
      if(helpMenu){
        header.insertBefore(nav,helpMenu);
      }else{
        header.appendChild(nav);
      }
    }

    const prevTitle=prevItem
      ?`Fiche précédente (${currentIndex} / ${totalCount})`+(prevItem.title?` : ${prevItem.title}`:'')
      :'Début de sélection · Aucune fiche précédente';
    const nextTitle=nextItem
      ?`Fiche suivante (${currentIndex+2} / ${totalCount})`+(nextItem.title?` : ${nextItem.title}`:'')
      :'Fin de sélection · Aucune fiche suivante';

    nav.innerHTML=`
      <a class="Musicband-detail-nav-btn Musicband-detail-nav-prev${!prevItem?' is-disabled':''}"
         href="${prevItem?escapeHtml(prevItem.url):'#'}"
         role="button"
         aria-label="${escapeHtml(prevTitle)}"
         title="${escapeHtml(prevTitle)}"
         ${!prevItem?'aria-disabled="true" tabindex="-1"':''}>
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m15 18-6-6 6-6"/></svg>
      </a>
      <a class="Musicband-detail-nav-btn Musicband-detail-nav-next${!nextItem?' is-disabled':''}"
         href="${nextItem?escapeHtml(nextItem.url):'#'}"
         role="button"
         aria-label="${escapeHtml(nextTitle)}"
         title="${escapeHtml(nextTitle)}"
         ${!nextItem?'aria-disabled="true" tabindex="-1"':''}>
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m9 6 6 6-6 6"/></svg>
      </a>
    `;

    nav.querySelectorAll('.Musicband-detail-nav-btn').forEach(btn=>{
      btn.addEventListener('click',event=>{
        if(btn.classList.contains('is-disabled')||btn.getAttribute('aria-disabled')==='true'){
          event.preventDefault();
        }
      });
      btn.addEventListener('keydown',event=>{
        if(event.key===' '||event.key==='Spacebar'){
          event.preventDefault();
          if(!btn.classList.contains('is-disabled')){
            btn.click();
          }
        }
      });
    });

    nav.addEventListener('keydown',event=>{
      if(event.key==='ArrowLeft'){
        const prevBtn=nav.querySelector('.Musicband-detail-nav-prev');
        if(prevBtn&&!prevBtn.classList.contains('is-disabled')){
          event.preventDefault();
          prevBtn.focus();
        }
      }else if(event.key==='ArrowRight'){
        const nextBtn=nav.querySelector('.Musicband-detail-nav-next');
        if(nextBtn&&!nextBtn.classList.contains('is-disabled')){
          event.preventDefault();
          nextBtn.focus();
        }
      }
    });
  }

  function setupSwipeNav(prevItem,nextItem){
    let touchStartX=0;
    let touchStartY=0;
    let touchStartTime=0;
    let touchValid=false;

    function isInteractiveOrScrollable(target){
      if(!target)return false;
      if(target.closest('input, textarea, select, button, a, label, audio, video, iframe, pre, code, [contenteditable="true"], [role="button"], [role="link"], .Musicband-menu, .Musicband-help-menu, .Musicband-scroll-top')){
        return true;
      }
      let cur=target;
      while(cur&&cur!==document.body&&cur!==document.documentElement){
        if(cur.classList.contains('business-table-scroll')||cur.classList.contains('Musicband-related-scroll')){
          return true;
        }
        const style=window.getComputedStyle(cur);
        const overflowX=style.overflowX;
        if((overflowX==='auto'||overflowX==='scroll')&&cur.scrollWidth>cur.clientWidth+2){
          return true;
        }
        cur=cur.parentElement;
      }
      return false;
    }

    document.addEventListener('touchstart',event=>{
      if(event.touches.length!==1){
        touchValid=false;
        return;
      }

      const touch=event.touches[0];
      const x=touch.clientX;
      const y=touch.clientY;

      const edgeMargin=25;
      if(x<edgeMargin||x>window.innerWidth-edgeMargin){
        touchValid=false;
        return;
      }

      if(isInteractiveOrScrollable(event.target)){
        touchValid=false;
        return;
      }

      touchStartX=x;
      touchStartY=y;
      touchStartTime=Date.now();
      touchValid=true;
    },{passive:true});

    document.addEventListener('touchend',event=>{
      if(!touchValid)return;
      touchValid=false;

      if(!event.changedTouches||event.changedTouches.length===0)return;
      const touch=event.changedTouches[0];
      const deltaX=touch.clientX-touchStartX;
      const deltaY=touch.clientY-touchStartY;
      const duration=Date.now()-touchStartTime;

      if(duration>600||duration<40)return;
      const absX=Math.abs(deltaX);
      const absY=Math.abs(deltaY);

      if(absX<60)return;
      if(absX<absY*1.8)return;

      if(deltaX>0){
        if(prevItem&&prevItem.url){
          window.location.href=prevItem.url;
        }
      }else{
        if(nextItem&&nextItem.url){
          window.location.href=nextItem.url;
        }
      }
    },{passive:true});

    document.addEventListener('touchcancel',()=>{
      touchValid=false;
    },{passive:true});
  }

  function handleDetailView(module,recordId){
    const context=getStoredContext(module);
    if(!context||!Array.isArray(context.items)||context.items.length===0){
      return;
    }

    const currentIndex=context.items.findIndex(item=>item.id===recordId);
    if(currentIndex===-1){
      return;
    }

    const totalCount=context.items.length;
    const prevItem=currentIndex>0?context.items[currentIndex-1]:null;
    const nextItem=currentIndex<totalCount-1?context.items[currentIndex+1]:null;

    if(context.listUrl){
      const links=document.querySelectorAll('main a, .tasks-container a, details a, p a');
      links.forEach(a=>{
        const href=a.getAttribute('href')||'';
        if(href.includes(module+'.php')&&!href.includes('id=')&&!href.includes('edit=')&&!href.includes('action=')){
          const text=a.textContent.toLowerCase();
          if(text.includes('liste')||text.includes('morceaux')||text.includes('retour')||text.includes('←')||text.includes('tous')){
            a.setAttribute('href',context.listUrl);
          }
        }
      });
    }

    setupHeaderNav(prevItem,nextItem,currentIndex,totalCount);
    setupSwipeNav(prevItem,nextItem);
  }

  function initDetailNavigation(){
    const module=detectModule();
    if(!module)return;

    const recordId=getRecordId();
    if(recordId!==null&&!isEditMode()){
      handleDetailView(module,recordId);
    }else if(recordId===null&&!isEditMode()){
      handleListView(module);
    }
  }

  /* =========================================================================
   * 3. Aide Musicband : ouverture systématique avec rubriques repliées
   * ========================================================================= */
  function initHelpCollapse(){
    document.addEventListener('click',event=>{
      const summary=event.target.closest('.Musicband-help-menu>summary');
      if(summary){
        const menu=summary.parentElement;
        if(menu&&!menu.open){
          menu.querySelectorAll('.Musicband-help-panel details[open]').forEach(d=>{d.open=false;});
        }
      }
    });
    document.querySelectorAll('.Musicband-help-menu').forEach(menu=>{
      menu.addEventListener('toggle',()=>{
        if(menu.open){
          menu.querySelectorAll('.Musicband-help-panel details[open]').forEach(d=>{d.open=false;});
        }
      });
    });
  }

  /* =========================================================================
   * 4. Initialisation et écouteurs globaux
   * ========================================================================= */
  function init(){
    initScrollTop();
    initDetailNavigation();
    initHelpCollapse();
  }

  if(document.readyState==='loading'){
    document.addEventListener('DOMContentLoaded',init,{once:true});
  }else{
    init();
  }

  window.addEventListener('pageshow',()=>{
    initDetailNavigation();
  });

  document.addEventListener('keydown',event=>{
    if(event.key==='Escape'){
      document.querySelectorAll('.Musicband-menu[open],.Musicband-help-menu[open]').forEach(menu=>{
        menu.open=false;
        menu.querySelector('summary')?.focus();
      });
    }
  });
})();
