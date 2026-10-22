# Musicband Toolbox — Community

Paquet installable sur un hébergement PHP/MySQL pour gérer les morceaux, setlists, contacts, concerts, tâches et notifications d’un groupe.

Ce dépôt contient la distribution **2026.10.22.25-community** en ZIP et en version décompressée, son empreinte SHA-256 et ce guide. Les sources de préparation, outils locaux, sauvegardes, fichiers privés et secrets restent exclus de Git. La version .25 corrige le faux succès possible d’un enregistrement Agenda vide et affiche l’état enregistré, en conservant les correctifs précédents. Les ZIP précédents restent disponibles pour retour arrière.

## Télécharger

Dans le dossier [`package/`](package/), télécharger [`musicband-toolbox-community-2026.10.22.25.zip`](package/musicband-toolbox-community-2026.10.22.25.zip) avec **Download raw file**. L’empreinte figure dans [`SHA256SUMS`](package/SHA256SUMS). Ne pas transférer le dépôt complet sur l’hébergement : extraire le ZIP applicatif ou utiliser le dossier `installation/` décrit ci-dessous.

## Version décompressée

Le dossier [`installation/`](installation/) contient les fichiers exacts du ZIP, dont le code PHP/HTML/JavaScript distribué, les styles, dépendances et règles `.htaccess`. Il permet de consulter le code ou de transférer directement les fichiers sans extraire le paquet. Il ne contient pas les sources ni outils de préparation locaux.

Pour installer depuis cette version, cloner ou télécharger le dépôt, puis transférer **le contenu de `installation/`**, fichiers cachés compris, dans le dossier web choisi. Ne pas transférer le dossier `.git`, `package/` ni le README racine. Suivre ensuite le même parcours `install.php` ou Upgrade ci-dessous. Les fichiers sont copiés sans modification du code ; ils restent soumis à la même validation manuelle que le ZIP.

## Agenda : état enregistré et absence de faux succès — version 2026.10.22.25-community

Le formulaire refusait mal une adresse vide : sans agenda déjà configuré, il pouvait annoncer un enregistrement tout en conservant une adresse absente. Ce cas est maintenant refusé explicitement. La page Configuration → Agenda affiche l’état enregistré sans révéler le secret. Après écriture, le réglage est relu avant de confirmer ; les messages distinguent adresse enregistrée, adresse existante conservée et agenda déconnecté. Le succès confirme la persistance du réglage, pas l’accès Google : la lecture distante reste déclenchée uniquement à l’ouverture d’Agenda.

Le message « Configurer l’adresse secrète iCal » signifie que la page Agenda lit une adresse absente ; la cause exacte sur une instance n’est pas démontrée par ce seul message. Après mise à jour, recharger Configuration → Agenda, saisir l’adresse si l’état est non configuré, enregistrer puis recharger avant d’ouvrir Agenda. Ne jamais transmettre l’adresse dans un ticket, une capture ou un journal. Les configurations existantes restent conservées ; aucune migration ni réinstallation nécessaire.

**Non exécuté — validation à réaliser par l’utilisateur** : sur une copie HTTPS sauvegardée, soumettre un champ vide sans réglage préalable (erreur explicite), enregistrer une adresse secrète autorisée (confirmation de relecture), puis recharger Configuration (état configuré, adresse masquée). Enregistrer vide avec adresse existante (conservation), puis ouvrir Agenda (lecture réelle ou erreur explicite de lecture Google). Vérifier déconnexion explicite, URL invalide et formulaire concurrent (refus sans remplacer l’adresse existante), droits et CSRF, et l’absence de secret dans HTML/messages/journaux. Confirmer aussi la conservation après un autre réglage de Configuration. Si l’état est configuré après rechargement mais Agenda réclame toujours une adresse, arrêter : relever version et chemin des deux pages, sans secret, pour poursuivre le diagnostic. Aucun test, lecture du secret, accès Google ou déploiement effectué par Codex. Retour arrière : restaurer les fichiers publics .24 en conservant base, adresse privée et autres réglages.

## Favicon personnalisable — version 2026.10.22.24-community

Déposer votre image **`favicon.png` à la racine de l’application, à côté d’`index.php`**, y compris si l’application est installée en sous-répertoire. Utiliser un vrai PNG carré de 16 à 1024 pixels de côté, de 1 Mio maximum (par exemple 256 × 256). Aucun chemin ni réglage à saisir. Le fichier n’est pas fourni dans le paquet : une mise à jour ne remplace donc pas votre image. Le favicon des onglets, de la connexion, de l’accueil, des modules et de l’installateur utilise ce fichier ; les icônes PWA et Apple Touch existantes sont conservées.

Un fichier absent, invalide, trop volumineux, non carré ou symbolique laisse l’icône embarquée en repli. La réponse du favicon demande une revalidation du cache ; après remplacement de l’image, recharger la page (le navigateur peut conserver son propre cache d’icônes). Le serveur ne redimensionne ni ne modifie l’image et n’accepte aucun chemin fourni par une requête.

**Non exécuté — validation à réaliser par l’utilisateur** : sur une copie HTTPS sauvegardée, transférer le paquet .24 puis placer un PNG valide à côté d’index.php. Vérifier l’icône dans les onglets de connexion, accueil, catalogue et lecteur Setlist, en racine et en sous-répertoire. Remplacer le PNG et recharger ; vérifier les repli sans fichier et avec fichier invalide, rectangulaire ou supérieur à 1 Mio. Vérifier que `pwa_icon.php?size=192`, `size=512` et `size=maskable` conservent leurs icônes, et qu’une clé inconnue reste refusée. Sur une installation de test neuve, vérifier aussi l’icône de l’installateur. Échec : chemin externe accepté, réponse autre qu’une image PNG, image existante écrasée, icônes PWA changées ou parcours cassé. Aucun test, serveur ou déploiement exécuté. Aucune migration ni réinstallation nécessaire. Retour arrière : restaurer ensemble les fichiers publics .23 en conservant votre favicon, la base et le dossier privé ; le fichier personnalisé sera simplement ignoré par l’ancienne version.

## Champ Morceau à travailler — version 2026.10.22.23-community

Le champ existant « À travailler pour la prochaine répète » (`next_rehearsal_work`) est désormais proposé dans les fiches, formulaires, filtres et choix de colonnes du catalogue sur le schéma Community simplifié. Il apparaît dans la liste par défaut, sans modifier un choix explicite de colonnes ni réactiver un champ masqué. Les valeurs Oui, Non et Non renseigné restent stockées dans le document JSON SQL existant ; aucune nouvelle colonne, migration ou réinitialisation.

Pour mettre à jour, sauvegarder les fichiers publics puis transférer le paquet complet .23, sans réinstaller ni lancer de migration. **Non exécuté — validation à réaliser par l’utilisateur** : avec le schéma Community simplifié et le schéma étendu, vérifier affichage, création et modification avec Oui/Non/Non renseigné, rechargement de la fiche, colonnes et filtres, édition groupée et droits lecture/édition. Sur un ancien morceau, vérifier qu’une saisie Oui/Non/Non renseigné prévaut sur la cellule source conservée et reste enregistrée après rechargement. Vérifier qu’un champ masqué et un choix explicite de colonnes restent respectés, que les anciennes valeurs et les autres champs sont conservés. Une valeur perdue, une modification refusée ou un accès en écriture sans droit constitue un échec. Aucun test, build, serveur ou déploiement exécuté ; archive recomposée à partir des fichiers distribués, sans exécution PHP/JS. Retour arrière : restaurer les fichiers publics .22, en conservant base, données et configuration privées ; la valeur enregistrée reste conservée mais le champ peut redevenir masqué.

## Prérequis

- Hébergement HTTPS avec certificat valide pour le domaine utilisé.
- PHP 8.2+ avec PDO MySQL, cURL, mbstring, OpenSSL, fileinfo, session, JSON et zlib.
- MySQL 8.0+ ou MariaDB 10.6+, moteur InnoDB ; utilisateur autorisé à créer et mettre à niveau les tables de cette base.
- Apache 2.4 autorisant les règles `.htaccess` du paquet. Pour un autre serveur, reproduire ces restrictions avant exposition.
- PHP autorisé à créer un dossier privé en dehors de la racine web, avec permissions 0700 ; fichiers privés en 0600.
- Sorties HTTPS autorisées pour Web Push et l’Agenda iCal ; transport e-mail configuré pour les invitations.

## Installer sur un nouvel hébergement

1. Créer une base vide et un utilisateur MySQL dédié. Configurer le domaine et son certificat HTTPS.
2. Extraire le contenu du ZIP, ou transférer le contenu de `installation/`, dans le dossier web choisi, éventuellement en sous-répertoire. Conserver les fichiers cachés `.htaccess`, puis retirer l’archive du dossier public.
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

La validation exhaustive de la PWA et d’une installation neuve sur chaque hébergement reste à effectuer. La version `.25` reprend les fichiers distribués `.24` avec le correctif de confirmation Agenda ; aucune reconstruction applicative ni migration exécutée. Aucun test fonctionnel, build, déploiement ou envoi n’a été effectué pour cette publication GitHub.

**Non exécuté — validation à réaliser par l’utilisateur** : sur une instance HTTPS isolée et sauvegardée, vérifier installation/reprise et verrouillage, connexion, conservation des données, catalogue/setlists/exports, droits, enregistrement du service worker dans le bon périmètre, activation d’un appareil, notification de test volontaire et compte rendu CRON. Vérifier les invitations uniquement avec des destinataires autorisés. Arrêter en cas d’erreur PHP/JavaScript, accès non autorisé, secret exposé, envoi inattendu ou donnée perdue.

## Retour arrière

Restaurer ensemble les fichiers publics de la version précédente. Conserver la base, le dossier privé, les clés VAPID et les abonnements ; ne pas réinstaller, purger les données ou régénérer les clés. Après une migration destructive, le retour des tables supprimées nécessite la sauvegarde SQL correspondante.

Le guide embarqué `LIRE-MOI.md` conserve les notes techniques et historiques de préparation de cette version ; utiliser le présent README pour le parcours de téléchargement et d’installation depuis GitHub. Les licences des dépendances tierces sont conservées dans le paquet.
