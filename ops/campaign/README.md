# Bloc M réparti PC / VPS (23 septembre 2026)

## Source reproductible de l'image

ATT-07/ATT-14 ; sources : export authentique, plans JSON et manifeste de campagne,
`ParametricCampaign::load()`, runtime `campaignBatch`, Dockerfile et recette du
pilote. Le point à éviter était une image construite depuis un fichier `reports/`
ignoré par Git. Le Dockerfile copie désormais la référence versionnée
`experiments/campagne-coeur/reference-profile.json` vers le chemin attendu par
les plans, sans modifier ceux-ci. Son SHA-256 est
`4018d6ce3fdab2705dd6d3b5f1b78959ee6e86c653ce5e02b7627cae78557765`,
identique à l'export authentique local. Le contexte Docker propre à la campagne
inclut le CLI et les plans sans élargir celui de la démo. Les tests natifs lisent
la même copie versionnée, afin de fonctionner depuis un clone propre.

Cette correction concerne une future construction ; l'image épinglée et les
workers VPS en cours ne sont pas reconstruits ni remplacés.

Le bloc M actif contient 63 plans et 15 876 000 combats. Après instruction du PO de ne lancer aucun plan supplémentaire sur PC, les résultats sont répartis ainsi :

- PC : 18 plans complets et exportés, 3 156 000 combats retenus. Le superviseur est arrêté. Un 19e plan, `M-unit-archer-capturable-fixed-counts`, compte 1 600 combats Windows partiels conservés localement mais **exclus** du total final ; il repart de zéro sur VPS car le manifeste refuse de mélanger les provenances de binaires.
- VPS A : 26 plans, 7 440 000 combats prévus : chevaliers, lanciers et segments combat aux budgets 12 000 et 120 000. `run-vps.sh a` reprend les lots et exporte chaque plan.
- VPS B : 19 plans, 5 280 000 combats prévus : soldats, six plans archers non terminés sur PC et segments combat au budget 360 000. `run-vps.sh b` reprend les lots et exporte chaque plan.

Les shards A/B n'ont aucun plan commun. Avec les 18 plans PC complets, ils couvrent exactement les 63 plans actifs et les 15 876 000 combats attendus. Le plan soldat de l'essai de charge contient 4 000 combats Linux complets, repris par B ; aucun lot de cette expérience n'est dupliqué.

Les deux conteneurs VPS utilisent la même image `waar-campaign:bd79dff-m`, distincte de la démo : aucun réseau ni port, **0,7 vCPU et 600 Mio chacun**, système de fichiers en lecture seule et seul dossier de résultats monté en écriture. Ils n'utilisent ni le conteneur ni le volume des profils de la démo. La compilation embarque les sources locales de ce worktree ; le profil est une copie exacte de `reports/campaign-manual-sol/profile.json`. L'empreinte du contexte transféré est `70eec4c3c8b5e9e4c93064234a0a8531f141a784965bd8afea0c4de0abb41819`.

Contrôle de capacité au démarrage des deux shards : `docker inspect` a relevé `NanoCpus=700000000` et `Memory=629145600` pour chacun. `docker stats` montrait environ 69–70 % CPU et 8 Mio de RAM par worker. Sur 10 secondes de `vmstat 1`, l'hôte gardait 19–29 % de CPU libre, avec `wa=0`, `st=0`, `si=so=0` ; 2,6 Gio de mémoire restaient disponibles. Ces mesures ponctuelles ne garantissent pas la latence des services sous une autre charge.

Avant répartition, un lot de 100 répétitions par sens du plan `M-combat-capturePercent` a été exécuté sur Linux dans un dossier de validation séparé. Identifiant, indices, requête et réponse batch sont strictement identiques au lot Windows de même clé. Ce contrôle ne garantit pas tous les cas, mais vérifie le raccordement des deux plateformes. Le binaire Linux a son empreinte propre (`6c07ae1a68f7592489f0da739311c6574f420d0f1b54302ee61512fc49047f88`) ; le profil (`4018d6ce3fdab2705dd6d3b5f1b78959ee6e86c653ce5e02b7627cae78557765`) et `rust/src/v2.rs` (`c413c842d6f1296a7fe701a1b00976ecc13e8ba1c813ca6ddfbf9bef465000b0`) ont les mêmes empreintes que sur PC. Le code source provient du worktree au HEAD `bd79dff8e07e85c6d1c01d6adaaee2382576b459` avec modifications locales déjà présentes. Le conteneur ne possède pas `.git` : son `codeHead` vaut `unavailable` et son `trackedDiffSha256` est le hash de ce mot, non une preuve de diff ; les empreintes des fichiers sources et du binaire restent vérifiables.

La reprise doit rester **par plan sur la même plateforme** : le manifeste refuse une provenance de binaire différente. Ne jamais exécuter simultanément deux workers sur le même dossier de plan. Le VPS lance chaque plan avec l'image et les chemins internes constants ; relancer `run-vps.sh a` ou `run-vps.sh b` reprend les lots complets sans changer leur graine. Les exports se reconstruisent depuis les lots, sans nouveaux combats.

Contrôles de progression : `reports/campagne-coeur/execution-m/supervisor-pc.out.log` sur PC et `/home/debian/waar-campaign/supervisor-{a,b}.out.log` sur VPS. Une campagne n'est complète qu'après vérification des 63 manifests (`status=complete`, compteur attendu, `errors=[]`) et des 63 exports. Les résultats du VPS doivent alors être rapatriés sans remplacer les dossiers PC. Le sous-dossier Windows partiel archers reste archivé séparément et ne peut remplacer le résultat Linux complet.

## Amendement d'ordonnancement validé le 24 septembre 2026

Attentes ATT-07/ATT-14 ; sources : `experiments/campagne-coeur/README.md`, `reports/campagne-coeur/preparation/{PREPARATION.md,active-plans.csv}`, le lanceur M ci-dessus et la réponse de contre-validation d'Astra transmise par le PO. Réemploi : plans JSON déjà prévisualisés, CLI `resume`/`export`, image Rust/PHP du bloc M et convention de graines. Échec à éviter : changer un plan ou perdre un témoin en retirant l'étiquette de dépendance `W`. Écart : l'ordre historique `M → W → E → X` retarde les compositions derrière 26,64 M de combats météo alors que les E/X actifs sont en météo neutre. Résultat voulu : **M → E → X → W**, avec E et X sur deux workers distincts, puis W réparti sur deux shards sans modification des entrées expérimentales. Les conclusions E/X restent limitées à la météo neutre ; leur interaction avec W n'est pas acquise.

Contrôle local préalable : les 22 plans actifs E/X (6 E, 16 X après segmentation) utilisent tous `neutral` pour A et B, le profil authentique, `baseSeed=42`, 2 000 répétitions et des lots de 100 ; leurs empreintes correspondent à `active-plans.csv`. La contre-validation d'Astra portait sur les huit plans sources ; ce contrôle couvre les segments réellement à exécuter. Aucun nouveau combat n'est autorisé par cette seule note : le lancement doit attendre la fin et l'audit du bloc M.

### Préparation VPS E/X/W

L'[inventaire d'exécution amendé](active-next-plans.csv) conserve les 184 lignes et les SHA des plans de la préparation, mais ordonne `M → E → X → W` et remplace `M|W` par `M` pour E/X. L'[inventaire initial](../../reports/campagne-coeur/preparation/active-plans.csv) reste inchangé comme preuve de préparation. Aucun JSON de plan, profil, budget, graine ou plafond n'a été modifié.

Le [lanceur](run-next-vps.sh) réutilise **l'ID immuable** `sha256:aa4fad4fe88895d78237baabcf6d563bc4c8fbd522064e0a3c5bdf0bf9d20425`, vérifié identique à l'image du worker M en cours (`waar-campaign:bd79dff-m`), le même CLI `resume`/`export` et le même dossier de résultats. Les 112 JSON actifs E/X/W sont montés en lecture seule depuis `/home/debian/waar-campaign/context/experiments/campagne-coeur` : **pas de reconstruction du moteur ou de l'image**. Le lanceur valide tous les SHA et plafonds du shard avant son premier combat, puis chaque manifeste et export. Il refuse E/X si les 45 plans M du VPS ne sont pas complets/exportés ; le verrou W initial attendait les 22 plans E/X (amendé ci-dessous). Un verrou `flock` évite deux superviseurs du même shard. La reprise reste sur le même VPS et la même image.

| Worker / shard | Plans actifs | Combats prévus | Contenu |
| --- | ---: | ---: | --- |
| A / `e` | 6 | 2 448 000 | Ajout et remplacement d'écran, météo neutre |
| B / `x` | 16 | 2 520 000 | Coupe archers et simplex segmenté, météo neutre |
| A / `w1` | 45 | 13 320 000 | blizzard, cloudy, neutral, rain, storm |
| B / `w2` | 45 | 13 320 000 | canicule, heat, snow, thunderstorm, wind |

Chaque météo W comprend son preset (360 000 combats) et huit cellules facteur (288 000 chacune). La division 45/45 et 13,32 M/13,32 M est **exacte en combats**, pas une garantie de même durée réelle : météo et durée des combats peuvent modifier le débit. Les shards W ne seront pas démarrés automatiquement après E/X ; une lecture intermédiaire reste possible. E/X doivent être lancés ensemble après vérification de M pour utiliser les deux CPU sans ajouter un troisième worker.

Contexte transféré le 24 septembre 2026 : archive de 112 plans SHA-256 `f8785c75b8f0466bca35d9346c944eccce806be62725a283d89b9a186cd7ac74`, inventaire SHA-256 `a2d86730e6505cf4f512d794d1a0a98f0368acbcbb55e23fc1a060a9e1da1c2f`. Le lanceur et l'inventaire distants ont été comparés à leurs copies locales. `bash -n`, les quatre `--check-only` et trois prévisualisations dans l'image (E, X segment, W) passent **sans combat**. Les prévisualisations E/X montrent les avertissements attendus de budgets réels différents ; ne pas présenter ces confrontations comme égales en coût.

Depuis le VPS, vérification sans combat :

```bash
bash /home/debian/waar-campaign/run-next-vps.sh e --check-only
bash /home/debian/waar-campaign/run-next-vps.sh x --check-only
bash /home/debian/waar-campaign/run-next-vps.sh w1 --check-only
bash /home/debian/waar-campaign/run-next-vps.sh w2 --check-only
```

Le relais ponctuel [start-e-x-after-m.sh](start-e-x-after-m.sh) a été détaché le 24 septembre 2026 à 00:33 UTC. Il vérifie chaque minute les 45 manifests et exports M distants ; il démarrera **ensemble** les superviseurs E et X quand les 12 720 000 combats M du VPS seront complets. Au lancement du relais, M comptait 44/45 plans complets, sans erreur ; le dernier plan `M-combat-woundDamageThreshold-b360000` était en cours. Le relais ne lance pas W. Sa sortie est `/home/debian/waar-campaign/handoff-e-x.out.log`, et son marqueur anti-double-démarrage est `/home/debian/waar-campaign/handoff-e-x-started`. Sa persistance après fermeture du client SSH a été vérifiée. Pour une relance manuelle de E/X après défaillance, inspecter d'abord les logs et manifests ; le CLI reprend les lots complets sans doublon.

Après audit des 45 manifests/exports M distants, les superviseurs E/X peuvent aussi être détachés séparément (ne **pas** le faire pendant que le relais automatique ci-dessus est actif) :

```bash
cd /home/debian/waar-campaign
nohup bash ./run-next-vps.sh e > supervisor-e.out.log 2>&1 < /dev/null &
nohup bash ./run-next-vps.sh x > supervisor-x.out.log 2>&1 < /dev/null &
```

Lire `supervisor-e.out.log` et `supervisor-x.out.log`, puis les manifests sous `results/E-*` et `results/segments/X-*` (la coupe archers est sous `results/X-archer-cut`). Une sortie `shard complete` ne remplace pas le contrôle des 22 manifests complets, `errors=[]` et exports. Une fois E/X achevés et analysés, les mêmes commandes avec `w1`/`w2` exécuteront W sur deux workers ; le lancement W n'est pas implicite.

### Quota CPU relevé le 24 septembre

Sur instruction du PO, la limite des workers de campagne est relevée de 0,7 à **1,0 vCPU**, sans changement de RAM (600 Mio), de plans, de graines, de moteur ni de profils. E était déjà terminé et exporté (6/6) ; le conteneur X actif a reçu `docker update --cpus 1.0 waar-campaign-x` sans interruption. `docker inspect` a ensuite indiqué `NanoCpus=1000000000` et `docker stats` environ 99 % CPU pour X. Le lanceur ci-dessus crée les futurs conteneurs X/W1/W2 et leurs exports à 1,0 vCPU.

Le superviseur X avait déjà chargé l'ancienne boucle à 0,7 vCPU : [le surveillant ponctuel](keep-x-at-one-vcpu.sh), démarré séparément avec le PID de ce superviseur, applique 1,0 vCPU aux nouveaux conteneurs X après leur création et s'arrête quand ce superviseur sort. Son journal distant est `/home/debian/waar-campaign/cpu-watch-x.out.log`. Vérifier ce journal et `docker inspect --format={{.HostConfig.NanoCpus}} waar-campaign-x` au prochain changement de plan ; un conteneur absent signifie simplement qu'aucun plan X n'est alors en cours. W1/W2 restent préparés, **non démarrés**.

### Chevauchement W1 / X autorisé le 24 septembre

Le PO demande d'utiliser le second vCPU libéré par E pendant que X termine. Les plans W ont pour dépendances expérimentales D1-D5, pas les résultats E/X ; le verrou « E et X achevés avant tout W » était un ordre de passage conservateur. Le lanceur amendé exige les **6 manifests et exports E complets** avant W1, et conserve les **22 E/X complets** avant W2. Il ne démarre aucun troisième worker ; W1 et X occupent les deux créneaux. Le protocole, les 90 JSON W, le profil, les graines, l'image et les dossiers de résultats restent inchangés. Les conclusions E/X demeurent bornées à la météo neutre.

L'ancien lanceur distant SHA-256 `08a09f16e31a46592548b9e4cf5e9370b7259ed0b3062031b78e5dab6ac665b2` est sauvegardé sous `/home/debian/waar-campaign/run-next-vps.sh.before-w1-overlap`. Le lanceur amendé, vérifié par `bash -n` avant et après transfert, a pour SHA-256 `726b291dbab2edcc2bc869664e156359a0c6172a9118da7061d29b6f19c5ce0b`. À 15:50 UTC, `supervisor-w1.out.log` confirme 45 plans et 13 320 000 combats validés, puis le départ de `M-weather-preset-blizzard.json` ; `docker stats` montre simultanément `waar-campaign-w1` et `waar-campaign-x`, chacun sous son plafond de 1 vCPU / 600 Mio. W2 n'est pas lancé. Vérifier ensuite le manifeste, les erreurs et l'export de chaque plan comme pour E/X.
