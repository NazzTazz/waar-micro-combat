# Démonstration privée

La démonstration présente le moteur proposé, pas les règles du serveur Waar.
Elle ne contient ni données de joueurs ni moteur privé importé. Les brouillons
restent dans le navigateur ; les sauvegardes partagées sont dans le volume
`waar-engine-demo_profile-saves`. Les exports JSON permettent aussi le partage.

## Déploiement versionné par release candidate

Le geste de promotion est un tag annoté sur un commit intégré à `main` :

```sh
git tag -a engine-v0.1.0-rc.1 <sha-complet-revu> -m "Engine 0.1.0 RC1"
git push origin engine-v0.1.0-rc.1
```

Le workflow [deploy-engine-candidate.yml](../../.github/workflows/deploy-engine-candidate.yml)
vérifie le format et l'ascendance du commit, rejoue Composer/PHP/JS/smoke,
les scénarios de déploiement et une stack Docker jetable avec rollback réel.
Le job de publication ne reçoit les secrets qu'après ces contrôles. Il archive
le commit exact, vérifie son SHA-256, puis transfère archive et bootstrap par
SSH avec clé d'hôte épinglée. Aucun déploiement sur simple merge, aucun registry.
Un tag existant ne doit jamais être déplacé : utiliser un nouveau numéro RC.

### Préparation unique et adoption de la production existante

L'activation requiert Linux, Bash, GNU coreutils (`timeout`, `sha256sum`, `stat`),
tar, diff, `flock`, Docker Engine et Compose v2. Le compte SSH doit pouvoir
exécuter `sudo -n timeout ... /bin/bash <bootstrap>` ; ce droit revient à lui
confier l'administration Docker/root. Utiliser un compte de livraison dédié,
pas une règle sudo prétendant isoler un script que ce compte peut remplacer.

Avant le premier tag, vérifier sur le VPS :

- un unique service `engine` du projet `waar-engine-demo`, déjà healthy ;
- le réseau externe `wai-edge` et la passerelle Caddy existante ;
- le volume **existant** `waar-engine-demo_profile-saves`, réellement monté
  en lecture/écriture dans `/var/lib/waar-profiles` ;
- le Compose de cette installation, dont le chemin absolu est enregistré dans
  le label Docker `com.docker.compose.project.config_files`. Il doit exister,
  être à jour avec l'installation et ne désigner qu'un fichier. L'adoption refuse
  un chemin disparu ou plusieurs fichiers ; rétablir le Compose original avant
  de continuer. Les éventuelles variables historiques nécessaires doivent être
  résolues dans ce fichier ; `DEMO_IMAGE` est fourni automatiquement ;
- les chemins `/opt/waar-micro-combat` et `/var/log/waar-micro-combat`, sans
  conflit avec un checkout existant. `current`, s'il existe, doit être un lien
  vers une release de cette racine ;
- une sauvegarde externe du volume, selon la politique de sauvegarde du VPS.

L'automatisation **refuse** de créer implicitement un volume vide ou d'installer
une première production absente. Elle adopte le conteneur existant en conservant
son image par identifiant Docker exact et son Compose résolu pour le rollback.
Le build initial manuel ci-dessous reste réservé au provisionnement explicite.

Configurer l'environnement GitHub `waar-engine-production` :

| Secret | Contenu |
| --- | --- |
| `ENGINE_VPS_HOST` | Nom DNS ou IPv4 du VPS |
| `ENGINE_VPS_USER` | Compte SSH dédié |
| `ENGINE_VPS_SSH_PRIVATE_KEY` | Clé OpenSSH de ce compte |
| `ENGINE_VPS_KNOWN_HOSTS` | Clé d'hôte vérifiée hors bande, format OpenSSH |

Variable facultative : `ENGINE_VPS_PORT`, défaut `22`. Pour un autre port,
`known_hosts` doit employer `[hôte]:port`. Les secrets WAI ne sont pas hérités
automatiquement. Restreindre l'environnement et la création des tags RC aux
personnes autorisées ; l'ascendance du SHA n'est pas une politique d'accès.

### Fichiers, contrôles et conservation

- `publish-release.sh` : validation, checksum, SSH/SCP, transfert du bootstrap
  comme fichier (correction historique WAI #81), nettoyage des fichiers de transit.
- `activate-release.sh` : verrou exclusif VPS, extraction contrôlée, journal,
  conservation de la source sous `/opt/waar-micro-combat/releases/<sha>`.
- `deploy.sh` : image `waar-engine-demo:<sha>`, verrou des profils, activation,
  vérification et rollback. Une image enregistrée est réutilisée ; elle n'est
  jamais reconstruite sous le même SHA. Un tag déplacé ou une image enregistrée
  disparue provoque un refus. Pour reconstruire, préparer un nouveau commit/RC.
- `verify-release.sh` : identifiant d'image réel, label OCI du SHA, volume réel,
  santé Docker, HTTP interne, deux combats minuscules seed 42 avec
  `runtime.kind=rust`, liste et chargement de tous les profils.
- `release-common.sh` : validations et contrôles partagés, sans action au chargement.

Les marqueurs `.waar-release-sha`, `.waar-release-archive-sha256` et `.image-id`
relient la source, son archive et l'image effectivement construite. Le Compose
résolu est conservé dans `.deployed-compose.yaml`. Les sources sont comparées
à l'archive lors d'une relance. Les images de base sont épinglées, mais cette
procédure ne prétend pas garantir une reconstruction bit-à-bit du compilateur.

Le build précède le verrou des profils. L'activation prend ensuite le même
`profiles.lock` que PHP, sans changer son inode, avec les droits du propriétaire
du volume. Les sauvegardes concurrentes répondent 503 et peuvent être retentées ;
les lectures continuent. Une copie préserve contenu, propriétaire et permissions
de `profiles.json`, et le SHA-256 avant/après doit rester identique. L'absence
initiale du fichier est un état explicite. Aucun profil de test n'est créé en prod.

`current` avance atomiquement **après** tous les contrôles. Sur échec après une
activation même partielle, le candidat est arrêté, le fichier restauré si besoin,
puis l'image précédente est réactivée avec son ancien Compose et son identifiant
exact. Le verrou est libéré après vérification de la restauration pour permettre
aux anciens runtimes à verrou bloquant de répondre aux healthchecks. Un rollback
échoué conserve le dossier `transaction-*` et annonce `rollback=FAILED`.
Une transaction conservée bloque explicitement la promotion suivante, jusqu'à
sa récupération et son archivage manuel hors de cette racine.

Les journaux sont sous `/var/log/waar-micro-combat/deploy-<sha>-<date>-<pid>.log`,
avec `current.log` vers la dernière tentative. Le résumé Actions donne tag, SHA,
checksum, image, contrôles et résultat du rollback. Les configurations de retour
arrière restent sous `/opt/waar-micro-combat/configurations/`. Aucune purge
automatique de releases, images, configurations ou logs en V1.

Le verrou VPS sérialise les activations de la soufflerie, y compris manuelles.
Il ne bloque pas un build WAI concurrent ; la V1 conserve cette limite et
n'intervient pas sur les scripts WAI. Les quotas Compose protègent le runtime,
pas le build : éviter de lancer plusieurs builds lourds simultanément.

### Retour manuel et incidents

Pour revenir à une release déjà présente, reprendre son **archive exacte** et
son checksum, puis lancer `publish-release.sh` depuis le checkout outillage revu,
avec les mêmes entrées `ENGINE_RELEASE_*` et SSH que le workflow. Le bootstrap
emploie les scripts contenus dans la release cible : vérifier leurs garanties
avant tout
retour vers une version plus ancienne de l'outillage. Une release antérieure à
cette automatisation n'est accessible que via son Compose/image conservés.

L'archive peut être reproduite depuis le commit conservé :

```sh
git archive --format=tar --output=release.tar <sha-complet>
sha256sum release.tar
```

Comparer à `.waar-release-archive-sha256` avant publication. Le tag d'origine
sert à l'identification ; ne pas déplacer ni repousser le tag existant.

Un SSH interrompu n'atteste ni succès ni rollback. Attendre la fin du processus
VPS (verrou), lire `current.log`, vérifier `current`, l'identifiant du conteneur et
la santé avant de relancer. Le délai distant est 20 minutes, avec TERM puis
180 secondes pour terminer/restaurer. Une panne hôte ou SIGKILL ne permet pas de
garantir un rollback automatique : conserver les `transaction-*`, leur copie de
profils et les configurations de retour. Si `rollback=FAILED`, intervenir avec
ces éléments avant toute autre promotion ; ne pas écraser une sauvegarde ayant
pu recevoir de nouvelles écritures depuis la libération du verrou.

Les contrôles HTTP sont internes au conteneur : ils n'attestent pas DNS/TLS/auth
Caddy ni l'accessibilité publique. Caddy n'est pas modifié. La recette métier
navigateur et les validations d'équilibrage restent séparées.

### Vérification de l'outillage

```sh
sudo bash ops/demo/test-release-scripts.sh
# Uniquement sur un daemon Docker jetable sans installation soufflerie :
sudo env ENGINE_TEST_DISPOSABLE_DOCKER=1 bash ops/demo/test-release-stack.sh
```

Le premier teste les transitions réelles des scripts avec un Docker de substitution
(échecs de build, activation partielle, santé/runtime, profils, rollback, concurrence,
relance et altération de source/image). Le second construit et exerce la vraie
stack PHP/Apache/Rust, la conservation des profils et un rollback provoqué.
La CI exécute les deux. Aucun de ces tests n'utilise le VPS de production.

## Provisionnement initial manuel

Depuis la racine d'un checkout revu, sur le VPS avec Docker :

```sh
docker build -f ops/demo/Dockerfile -t waar-engine-demo:<commit> .
DEMO_IMAGE=waar-engine-demo:<commit> docker compose -f ops/demo/compose.yaml up -d
DEMO_IMAGE=waar-engine-demo:<commit> docker compose -f ops/demo/compose.yaml ps
```

Les images de compilation et d'exécution sont épinglées par digest. Le contexte
Docker utilise une liste d'inclusion ; `engines/waar-v3`, les expériences, les
rapports, `.git`, les dépendances locales et les secrets ne sont pas envoyés.
Le binaire Rust est compilé avec le lockfile. Aucun fallback PHP n'est embarqué.

Le conteneur rejoint le réseau externe `wai-edge`, sans port publié sur l'hôte.
Son système de fichiers est en lecture seule, hormis les répertoires temporaires
Apache/PHP. Limites : 1 CPU, 768 Mio, 64 processus, trois workers HTTP et un seul
calcul à la fois. Un calcul concurrent reçoit HTTP 429 et `Retry-After: 10`.
Le verrou système est libéré lorsque le processus PHP se termine.

## Passerelle Caddy existante

Ajouter le bloc ci-dessous **hors des marqueurs gérés par WAI**, après sauvegarde
du Caddyfile. Remplacer le placeholder par le résultat de `caddy hash-password`
(saisir le mot de passe sur stdin ; ne pas mettre de secret dans Git).
La protection porte sur l'ensemble du site, y compris `/api/*` et `/editor/*`.

```caddyfile
engine.waar-tools.fr {
    basic_auth {
        waar-demo <BCRYPT_HASH>
    }
    encode zstd gzip
    request_body {
        max_size 2MB
    }
    reverse_proxy waar-engine-demo:8080 {
        header_up -Authorization
    }
    header {
        Strict-Transport-Security "max-age=31536000"
        X-Content-Type-Options nosniff
        Referrer-Policy no-referrer
        X-Robots-Tag "noindex, nofollow"
        X-Frame-Options SAMEORIGIN
        Cache-Control "private, no-store"
        -Server
    }
    log {
        output stdout
        format json
    }
}
```

Écrire en place si le fichier est monté par bind-mount, puis utiliser
`caddy validate --config /etc/caddy/Caddyfile` et `caddy reload` dans le conteneur
de la passerelle. Son logger persistant global conserve aussi les requêtes de
ce domaine pendant 31 jours ; les en-têtes d'authentification restent masqués.
Apache reçoit les requêtes sans l'en-tête Authorization. Ses logs techniques
Docker sont bornés à trois fichiers de 10 Mio.

Vérifier HTTPS : 401 sans identifiants sur `/`, `/api/default-profile` et
`/editor/app.js` ; 200 avec identifiants ; mesure indiquant `runtime.kind=rust`.
Vérifier aussi les domaines WAI existants après rechargement.

Pour revenir en arrière, redémarrer la version précédente avec son tag immuable.
Pour retirer la démo, retirer seulement son bloc Caddy, valider/recharger puis
arrêter son compose. Ne pas arrêter la passerelle partagée.

## Limites de cette première mise à disposition

- Identifiants communs : pas d'attribution individuelle des actions.
- Un calcul long occupe le créneau jusqu'à sa fin ; pas de file d'attente,
  d'annulation ni de reprise. Les limites PHP ne constituent pas un watchdog
  indépendant du processus natif. Les quotas Docker protègent les autres services.
- Les 100 répétitions donnent des estimations. Les ellipses sont des objectifs
  métier, pas des intervalles de confiance.
- La recherche évolutive existante reste exploratoire. Elle n'est pas
  l'optimiseur inverse décrit dans `docs/optimiseur-inverse-cadrage.md`.
