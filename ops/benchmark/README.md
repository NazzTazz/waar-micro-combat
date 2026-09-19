# Comparer PHP et Rust, en local et sur le VPS

## Débit sur 20 secondes par moteur

```powershell
php bin/benchmark-cohort.php --duration=20 --output=reports/benchmark-20s.json
```

Ce mode mesure chaque moteur pendant environ 20 secondes réelles, sans cible
de nombre de combats ni extrapolation. Chaque lot contient exactement les
16 monotypes et les 16 confrontations mixtes : le mélange reste 50/50 quel que
soit le nombre de lots terminés. Par défaut une répétition, soit 32 combats par
lot, limite le dépassement final. Le dernier lot est terminé, pas interrompu ;
le débit divise le nombre réellement terminé par la durée réelle, y compris le
pilote et la vérification des empreintes. Échauffement exclu. Environ 40 secondes
pour PHP puis Rust, hors préparation. Ne pas combiner `--duration` avec
`--combats` ou `--seconds`. Sur le VPS, passer également `--duration=20` au
conteneur décrit plus bas.

## Volume cible avec budget de temps

Depuis la racine du dépôt, PHP 8.2+ 64 bits et moteur Rust release compilé :

```powershell
cargo build --locked --release --manifest-path engines/waar-cohort/rust/Cargo.toml
php bin/benchmark-cohort.php --combats=100000 --seconds=30 --batch=5 --output=reports/benchmark-local.json
```

`--combats` est le total demandé **par moteur**, moitié monotypes, moitié
compositions mixtes. Il doit être divisible par 32. Les 16 monotypes et les
16 confrontations mixtes utilisent le profil Test 2, la météo neutre, un budget
de 400 400 par camp (arrondi des effectifs), sans effets acquis. La projection
des conséquences est comprise. `--profile=chemin.json` permet un autre profil.

`--seconds` borne chaque couple moteur/charge : 30 donne environ deux minutes
maximum pour l'ensemble, plus échauffement et fin du dernier lot. La limite est
vérifiée entre lots, **ce n'est pas un watchdog**. Chaque lot contient 16 fois
`--batch` combats, maximum 100 répétitions par scénario. Les graines progressent
sans répéter les mêmes combats entre lots. Même plan sur les deux machines.

Un lot d'échauffement par moteur et charge est exclu. Le temps mesuré inclut le
démarrage du processus, JSON, résolution et projection : c'est le débit du
transport réellement utilisé par la soufflerie, pas celui d'une boucle Rust
isolée. Aucun HTTP, aucune recherche de candidat, aucune écriture de règles.
Le test ne doit pas tourner en même temps qu'une compilation ou d'autres tests.

Le JSON distingue : nombre **réellement exécuté**, objectif atteint ou arrêt
temporel, débit, temps estimé pour la cible, durée min/max des lots et accélération
sur le **préfixe de combats commun aux deux moteurs**. Chaque lot commun est
comparé par empreinte des résultats entiers canoniques ; divergence = échec.
Une fin anticipée en PHP ne certifie pas la parité des combats supplémentaires
effectués seulement en Rust. La mémoire affichée est celle du pilote PHP,
**pas le RSS des sous-processus**. Le fichier de sortie existant n'est pas écrasé.

Pour réellement terminer 100 000 combats en PHP, augmenter `--seconds` selon
l'estimation du premier passage (maximum 3600 par charge). Pour vérifier la
variabilité, relancer la même commande avec de nouveaux noms de sortie. Un seul
passage ne fournit pas un intervalle de confiance.

## VPS : conteneur distinct, sans toucher à la démonstration

L'image de benchmark ajoute le moteur PHP isolé à l'image de démo existante.
Le Dockerfile possède sa propre liste d'inclusion : aucun moteur privé importé,
secret, rapport ou serveur web n'est ajouté. Aucun port ni réseau nécessaire.

```sh
docker build -f ops/benchmark/Dockerfile \
  --build-arg DEMO_IMAGE=waar-engine-demo:a4b0cc3 -t waar-engine-benchmark:local .
docker run --rm --network none --cpus 1 --memory 768m --pids-limit 64 \
  --read-only --tmpfs /tmp:size=32m waar-engine-benchmark:local \
  --combats=100000 --seconds=30 --batch=5 > benchmark-vps.json
```

Le build de référence de la démo est décrit dans `ops/demo/README.md`. Pour une
comparaison stricte, employer le même profil, la même version des sources et les
mêmes options. Le VPS reste partagé et plafonné à un CPU ; Windows et Linux,
versions PHP et coûts de lancement différents influencent les résultats.
L'accélération PHP/Rust **sur chaque machine** est donc plus interprétable que
la comparaison directe de leurs seuls débits.

Vérification courte : `node tests/cohort-benchmark.test.js` (64 combats par
moteur, contrôles du comptage, des empreintes, des arguments et de non-écrasement).
L'ancien benchmark FFI `engines/waar-cohort/bin/benchmark.php` est conservé.
