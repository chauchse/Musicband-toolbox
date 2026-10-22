/* Abonnement Web Push du navigateur : dialogue avec notifications_device.php et pwa_sw.php.
 * Consentement sur geste utilisateur, contrôle VAPID et état serveur séparé de l’abonnement local.
 * Les écritures utilisent le jeton CSRF lu sur l’API ; aucune mise en cache de données métier.
 */
(() => {
  const status=document.getElementById('push-status'),enable=document.getElementById('push-enable'),disable=document.getElementById('push-disable'),testBtn=document.getElementById('push-test');
  if(!status||!enable||!disable)return;
  let busy=false,stateVersion=0;
  const api=new URL('notifications_device.php?api=1',location.href),base=new URL('./',api);
  async function request(body,subscriptionHash=''){const url=new URL(api);if(subscriptionHash)url.searchParams.set('subscription_hash',subscriptionHash);const r=await fetch(url,{credentials:'same-origin',cache:'no-store',method:body?'POST':'GET',body});const v=await r.json();if(!r.ok)throw new Error(v.error||'Action refusée.');return v;}
  async function registration(){if(!isSecureContext||!('serviceWorker'in navigator)||!('PushManager'in window)||!('Notification'in window))throw new Error('Notifications non prises en charge ici. Sur iPhone/iPad, ouvrir la version ajoutée à l’écran d’accueil.');await navigator.serviceWorker.register(new URL('pwa_sw.php',base),{scope:base.pathname});let timer;try{return await Promise.race([navigator.serviceWorker.ready,new Promise((_,reject)=>{timer=setTimeout(()=>reject(new Error('Le service worker ne devient pas actif. Vérifier la publication de pwa_sw.php et le chemin du site.')),15000);})]);}finally{clearTimeout(timer);}}
  function keyBytes(value){const raw=atob(value.replace(/-/g,'+').replace(/_/g,'/'));return Uint8Array.from(raw,c=>c.charCodeAt(0));}
  // Invalider les lectures en cours avant toute modification de l’abonnement.
  async function action(subscribe){if(busy)return;stateVersion++;busy=true;enable.disabled=disable.disabled=true;if(testBtn)testBtn.disabled=true;try{
    // La demande d’autorisation reste directement liée au geste de l’utilisateur.
    if(subscribe){if(!('Notification'in window))throw new Error('Notifications indisponibles.');const consent=await Notification.requestPermission();if(consent!=='granted')throw new Error('Notifications non autorisées. Vous pouvez changer ce choix dans les réglages du navigateur.');}
    const reg=await registration();let sub=await reg.pushManager.getSubscription();
    if(!subscribe){if(sub){const endpoint=sub.endpoint;await sub.unsubscribe();try{const session=await request();await request(new URLSearchParams({csrf:session.csrf,action:'disable',endpoint}));}catch(_e){status.textContent='Appareil désabonné. Synchronisation serveur non confirmée ; un abonnement expiré sera désactivé au prochain traitement.';return;}}status.textContent='Notifications désactivées sur cet appareil.';if(testBtn)testBtn.style.display='none';return;}
    const session=await request();
    if(subscribe){if(!session.publicKey)throw new Error('Clé publique Web Push non configurée sur le serveur.');const serverKey=keyBytes(session.publicKey);
      // Un abonnement créé avec une ancienne paire VAPID ne peut pas recevoir les nouveaux envois.
      const previousKey=sub?.options?.applicationServerKey;
      if(sub&&previousKey){const bytes=new Uint8Array(previousKey);if(bytes.length!==serverKey.length||bytes.some((value,index)=>value!==serverKey[index])){
        const endpoint=sub.endpoint;
        if(!await sub.unsubscribe())throw new Error('Ancien abonnement non révoqué. Désactiver puis réactiver les notifications sur cet appareil.');
        sub=null;
        await request(new URLSearchParams({csrf:session.csrf,action:'disable',endpoint}));
      }}
      if(!sub)sub=await reg.pushManager.subscribe({userVisibleOnly:true,applicationServerKey:serverKey});await request(new URLSearchParams({csrf:session.csrf,action:'subscribe',endpoint:sub.endpoint,subscription:JSON.stringify(sub.toJSON())}));status.textContent='Notifications activées sur cet appareil pour ce compte. Vous pouvez tester la réception dans cette section.';if(testBtn)testBtn.style.display='inline-block';}

  }catch(e){status.textContent=e.message;}finally{busy=false;enable.disabled=disable.disabled=false;if(testBtn)testBtn.disabled=false;}}
  async function testNotification(){
    if(busy)return;busy=true;if(testBtn)testBtn.disabled=true;enable.disabled=disable.disabled=true;status.textContent='Envoi de la notification de test en cours…';
    try{
      const session=await request();
      const res=await request(new URLSearchParams({csrf:session.csrf,action:'test_notification'}));
      status.textContent=res.message||(res.ok?'Notification de test transmise avec succès.':'Échec du test.');
    }catch(e){
      status.textContent='Erreur lors du test : '+e.message;
    }finally{
      busy=false;if(testBtn)testBtn.disabled=false;enable.disabled=disable.disabled=false;
    }
  }
  // Un abonnement local ne suffit pas : vérifier aussi son association au compte serveur.
  async function refreshStatus(){
    const version=++stateVersion;status.textContent='Vérification de l’activation sur cet appareil…';
    try{
      if(!isSecureContext||!('serviceWorker'in navigator)||!('PushManager'in window)||!('Notification'in window))throw new Error('Notifications indisponibles ici. Sur iPhone, ouvrir Musicband depuis l’icône ajoutée à l’écran d’accueil.');
      const reg=await navigator.serviceWorker.getRegistration(base.href);
      const sub=reg?await reg.pushManager.getSubscription():null;
      if(version!==stateVersion||busy)return;
      if(!sub||Notification.permission!=='granted'){status.textContent=Notification.permission==='denied'?'Notifications refusées dans les réglages de cet appareil.':'Notifications non activées sur cet appareil.';if(testBtn)testBtn.style.display='none';return;}
      // L’URL Push reste sur l’appareil : seule son empreinte est envoyée pour la lecture d’état.
      const digest=await crypto.subtle.digest('SHA-256',new TextEncoder().encode(sub.endpoint));
      const hash=[...new Uint8Array(digest)].map(value=>value.toString(16).padStart(2,'0')).join('');
      const session=await request(null,hash);
      if(version!==stateVersion||busy)return;
      if(!session.publicKey)throw new Error('Clé publique Web Push non configurée sur le serveur.');
      const previous=sub.options?.applicationServerKey,expected=keyBytes(session.publicKey);
      const bytes=previous?new Uint8Array(previous):null;
      const changed=bytes&&(bytes.length!==expected.length||bytes.some((value,index)=>value!==expected[index]));
      if(testBtn)testBtn.style.display=session.active?'inline-block':'none';
      status.textContent=changed?'La configuration Push a changé : réactiver les notifications sur cet appareil.':session.active?'Notifications activées sur cet appareil pour ce compte. Utilisez le bouton de test pour vérifier la réception.':'Abonnement présent sur l’appareil, mais inactif pour ce compte côté serveur : cliquer sur Activer.';
    }catch(error){if(version===stateVersion&&!busy){status.textContent='Activation non vérifiée : '+error.message;if(testBtn)testBtn.style.display='none';}}
  }
  enable.addEventListener('click',()=>action(true));disable.addEventListener('click',()=>action(false));
  if(testBtn)testBtn.addEventListener('click',testNotification);
  refreshStatus();
})();
