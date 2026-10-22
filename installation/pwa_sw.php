<?php
/* Musicband PWA WORKER: Push uniquement, aucun cache ni interception des fiches. */
/* Sert le JavaScript du service worker avec le périmètre défini dans auth_config.php.
 * Gère réception Push et clic sur notification ; aucun cache applicatif ni interception des fiches.
 */
require_once __DIR__.'/auth_config.php';
header('Content-Type: application/javascript; charset=utf-8');header('Cache-Control: private, no-cache, no-store, must-revalidate, max-age=0');header('Pragma: no-cache');header('Expires: 0');header('Service-Worker-Allowed: '.Musicband_BASE_PATH);
echo 'const MusicbandBase='.json_encode(Musicband_BASE_PATH,JSON_HEX_TAG|JSON_HEX_AMP).';';
?>
self.addEventListener('install',event=>event.waitUntil(self.skipWaiting()));
self.addEventListener('activate',event=>event.waitUntil(Promise.all([self.clients.claim(),typeof caches!=='undefined'?caches.keys().then(keys=>Promise.all(keys.map(k=>caches.delete(k)))):Promise.resolve()])));
self.addEventListener('push',event=>{
  let data={};try{data=event.data?event.data.json():{};}catch(_e){}
  if(!data||typeof data!=='object'||Array.isArray(data))data={};
  const labels={'concert.incomplete':'Un concert à venir reste incomplet.','document.generated':'Un document a été généré.','document.sent':'Un document a été transmis au service mail.','setlist.validated':'Une setlist a été validée.','test.notification':'Vos notifications PWA fonctionnent correctement !'};
  const title=(typeof data.title==='string'&&data.title.trim()!=='')?data.title.trim():'Musicband';
  const body=(typeof data.body==='string'&&data.body.trim()!=='')?data.body.trim():((labels[data.event]||'Une mise à jour vous concerne.')+' Connectez-vous pour consulter la fiche.');
  const notifTag=(typeof data.tag==='string'&&data.tag.trim()!=='')?data.tag.trim():('Musicband-'+String(data.id||Date.now()));
  event.waitUntil(self.registration.showNotification(title,{body:body,tag:notifTag,data:{path:typeof data.path==='string'?data.path:'index.php'},icon:MusicbandBase+'pwa_icon.php?size=192'}).catch(err=>console.error('PWA notification error:',err)));
});
self.addEventListener('notificationclick',event=>{
  event.notification.close();let target=new URL(MusicbandBase+'index.php',self.location.origin);
  try{const candidate=new URL(event.notification.data.path,new URL(MusicbandBase,self.location.origin));if(candidate.origin===self.location.origin&&candidate.pathname.startsWith(MusicbandBase))target=candidate;}catch(_e){}
  event.waitUntil(self.clients.openWindow(target.href));
});
