# Démonstration privée

La démonstration présente le moteur proposé, pas les règles du serveur Waar.
Elle ne contient ni données de joueurs ni moteur privé importé. Les profils
restent dans le navigateur ; les exports JSON servent au partage entre collègues.

## Construire et démarrer

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
