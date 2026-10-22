# Musicband Toolbox — Community

Paquet installable sur un hébergement PHP/MySQL pour gérer les morceaux, setlists, contacts, concerts, tâches et notifications d’un groupe.

Ce dépôt contient uniquement la distribution **2026.10.22.22-community**, son empreinte SHA-256 et ce guide. Les sources de préparation, outils locaux, sauvegardes, fichiers privés et secrets restent exclus de Git. Le ZIP est une copie de la distribution préparée, sans changement du code applicatif.

## Télécharger

Dans le dossier [`package/`](package/), télécharger [`musicband-toolbox-community-2026.10.22.22.zip`](package/musicband-toolbox-community-2026.10.22.22.zip) avec **Download raw file**. L’empreinte figure dans [`SHA256SUMS`](package/SHA256SUMS). Ne pas transférer le dépôt complet sur l’hébergement : extraire le ZIP applicatif.

## Prérequis

- Hébergement HTTPS avec certificat valide pour le domaine utilisé.
- PHP 8.2+ avec PDO MySQL, cURL, mbstring, OpenSSL, fileinfo, session, JSON et zlib.
- MySQL 8.0+ ou MariaDB 10.6+, moteur InnoDB ; utilisateur autorisé à créer et mettre à niveau les tables de cette base.
- Apache 2.4 autorisant les règles `.htaccess` du paquet. Pour un autre serveur, reproduire ces restrictions avant exposition.
- PHP autorisé à créer un dossier privé en dehors de la racine web, avec permissions 0700 ; fichiers privés en 0600.
- Sorties HTTPS autorisées pour Web Push et l’Agenda iCal ; transport e-mail configuré pour les invitations.

## Installer sur un nouvel hébergement

1. Créer une base vide et un utilisateur MySQL dédié. Configurer le domaine et son certificat HTTPS.
2. Extraire le contenu du ZIP dans le dossier web choisi, éventuellement en sous-répertoire. Conserver les fichiers cachés `.htaccess`, puis retirer l’archive du dossier public.
3. Ouvrir `https://votre-domaine/chemin/install.php` et renseigner la connexion MySQL, l’URL HTTPS avec barre finale, le nom de l’application et le premier compte administrateur.
4. Terminer l’installation immédiatement après transfert : l’assistant est accessible avant sa finalisation, puis verrouillé. Conserver les informations de reprise en cas d’interruption ; ne pas effacer la base ni le dossier privé.
5. Se connecter et adapter ou retirer les exemples fictifs : cinq morceaux, une setlist, un concert et un contact. Leurs adresses ne doivent pas servir à des envois réels.

Le schéma est initialisé automatiquement, sans import SQL manuel. Les comptes, identifiants MySQL et secrets PWA sont stockés hors racine publique. Aucun modèle, Composer ou serveur applicatif permanent n’est à installer sur l’hébergement.

## Configurer les fonctions

**E-mails et invitations.** Dans Configuration → E-mails, choisir l’expéditeur et le transport, puis effectuer volontairement un test vers une adresse réelle autorisée. Pour SMTP : serveur, port, STARTTLS ou TLS implicite et identifiants du fournisseur. Dans Configuration → Concerts, définir les destinataires calendrier. L’action « Inviter au calendrier… » apparaît sur une fiche enregistrée en consultation si le compte possède le droit d’envoi et si le concert, aujourd’hui ou à venir, comporte adresse, heure d’arrivée, heure de prestation et durée valides. Une seule tentative d’invitation est permise, même en cas d’échec.

**Agenda.** Configuration → Agenda accepte un lien iCal Google privé, sans OAuth. Garder ce lien confidentiel.

**PWA et notifications.** L’installation neuve extrait automatiquement les dépendances Web Push et leurs licences dans le stockage privé, génère les clés VAPID propres à l’instance et prépare la configuration et le lanceur CRON. Aucun secret n’est fourni dans le ZIP. Vérifier le diagnostic de Configuration → Général et les autorisations des comptes et règles souhaités. Chaque utilisateur active volontairement son appareil dans Préférences → Notifications et rappels. Sur iPhone/iPad, ouvrir l’application ajoutée à l’écran d’accueil. Le navigateur exige un certificat HTTPS reconnu ; contourner une alerte de certificat ne valide pas la PWA.

Pour les rappels sans visite du site, créer dans le panneau de l’hébergeur une tâche PHP 8.2+ horaire vers le lanceur privé indiqué dans Upgrade. Ne placer aucun secret dans la commande. Le paquet ne peut pas configurer le panneau de l’hébergeur ni accepter les notifications à la place de l’utilisateur. Vérifier ensuite la dernière exécution dans Configuration → Général.

## Mettre à jour un site existant

Sauvegarder la base, les fichiers publics et le dossier privé avant transfert. Transférer le paquet complet, sans relancer `install.php`. Pour une PWA non configurée, utiliser **Upgrade → Configurer la PWA** : cette action est distincte des migrations SQL et conserve toute configuration et clé existante.

Les migrations de base sont une opération séparée : les migrations 2 et 3 retirent définitivement les journaux d’import et neuf tables financières. Lire les avertissements d’Upgrade et vérifier une sauvegarde restaurable avant de les appliquer. La configuration PWA n’exige pas de déclencher ces suppressions.

## Périmètre et validation

Le paquet inclut catalogue, composition manuelle de setlists, variantes, exports Tempo/SACEM/Excel/impression, contacts, concerts, tâches, agenda iCal, comptes, rôles et notifications. Sound/XR18, authentification Google, publication WordPress, modules financiers et imports externes sont retirés ou désactivés dans Community. La génération automatique de setlists n’est pas portée dans cette distribution PHP.

La validation exhaustive de la PWA et d’une installation neuve sur chaque hébergement reste à effectuer. La distribution locale `.22` et le ZIP concordent avec leur manifeste lors de l’inspection statique. Aucun test fonctionnel, build, déploiement ou envoi n’a été effectué pour cette publication GitHub.

**Non exécuté — validation à réaliser par l’utilisateur** : sur une instance HTTPS isolée et sauvegardée, vérifier installation/reprise et verrouillage, connexion, conservation des données, catalogue/setlists/exports, droits, enregistrement du service worker dans le bon périmètre, activation d’un appareil, notification de test volontaire et compte rendu CRON. Vérifier les invitations uniquement avec des destinataires autorisés. Arrêter en cas d’erreur PHP/JavaScript, accès non autorisé, secret exposé, envoi inattendu ou donnée perdue.

## Retour arrière

Restaurer ensemble les fichiers publics de la version précédente. Conserver la base, le dossier privé, les clés VAPID et les abonnements ; ne pas réinstaller, purger les données ou régénérer les clés. Après une migration destructive, le retour des tables supprimées nécessite la sauvegarde SQL correspondante.

Le guide embarqué `LIRE-MOI.md` conserve les notes techniques et historiques de préparation de cette version ; utiliser le présent README pour le parcours de téléchargement et d’installation depuis GitHub. Les licences des dépendances tierces sont conservées dans le paquet.
