# Moteur Waar par cohortes — tranche A

Ce dossier est l’adaptation isolée du moteur par cohortes importé de `waar-v3`.
Son contrat actif est `waar-cohort-v2`. La soufflerie actuelle n’est pas raccordée :
ce sera la tranche B. Les copies de référence sous `engines/waar-v3` et les fichiers
figés du dépôt ne sont pas modifiés.

## Règles actives

- Quatre types fixes : soldat, lancier, archer et chevalier.
- Résolution agrégée par cohortes, avec actions simultanées depuis l’état de début
  de round. Aucun état individuel n’est créé.
- Ciblage proportionnel aux populations vivantes. `attackFactor` est un contre
  dirigé qui multiplie les dégâts, jamais une préférence de cible.
- Une unité produit `strikesPerAttack` tentatives ; son attaque est divisée par ce
  nombre. Ce fractionnement remplace entièrement l’extra-ball.
- Les blessés frappent comme les autres. Il n’existe plus de multiplicateur
  d’attaque propre aux blessés.
- La précision effective vaut `baseAccuracy ± accuracySpread`. Le tirage uniforme
  est isolé par seed, round, camp et type (`waar-accuracy-uniform-v1`).
- Les modificateurs sont préparés séparément pour chaque armée. L’ordre présenté
  est acquis/formation puis météo. Les facteurs d’un paramètre sont multipliés
  exactement et arrondis une seule fois à six décimales.
- La météo peut seulement multiplier l’attaque et la précision de base. Elle ne
  peut pas toucher l’amplitude aléatoire. Un autre type d’effet peut modifier les
  paramètres prévus par le contrat typé.
- `defendingEfficiency` multiplie les dégâts produits par le camp défenseur.
- La reddition est désactivée par défaut. Son seuil inclusif porte sur les morts
  cumulés. Le départage est économique par défaut, ou structurel ; une égalité
  donne le défenseur ou un match nul selon le ruleset.
- La réallocation héritée des tentatives non consommées reste présente et tracée.
  Elle est volontairement laissée à arbitrer plus tard.

## Entrées, sorties et rejeu

Les schémas JSON sont dans [`contracts`](contracts). Le point d’entrée PHP est
`CombatEngine::resolveRequest()`. La frontière native prend exactement la même
requête avec `RustCombatResolver::resolveRequest()`.

Un résultat détaillé contient les règles et valeurs préparées, la provenance des
effets, les sous-flux de précision, les deux matrices 4 × 4 de chaque round, les
tentatives allouées/consommées/réallouées, les touches, dégâts émis/absorbés et
l’excédent. `traceLevel: none` retire les matrices mais ne change pas la physique.
Le `replayHash` identifie les règles, préparations, armées, seed et niveau de trace.
Le résultat embarque le ruleset complet dans `ruleset` et les effectifs initiaux
dans `initialArmies`. `CombatReplay::request($report)` reconstruit une requête à
partir du rapport seul, vérifie son empreinte et reprend la politique de conséquences
enregistrée. Le CLI vérifie aussi l'égalité complète du rapport rejoué :

```powershell
php engines/waar-cohort/bin/replay.php rapport.json
```

Il accepte également l'enveloppe JSON de `bin/demo.php`. Les rapports v2 antérieurs
à cette correction, dépourvus des entrées complètes, sont rejetés explicitement ;
leur rejeu exige la requête originale. Le format physique et le calcul de l'empreinte
restent inchangés.

La projection `wounded-capture-then-compress/1` est un service séparé. Pour le camp
vaincu et les types capturables, elle sélectionne d’abord les prisonniers parmi les
blessés, puis compresse morts, blessés libres et prisonniers avec des arrondis
inférieurs. Elle fournit toujours les quatre catégories et le coût économique perdu.
Un match nul ne produit aucun prisonnier.

Limite explicite : l’entrée publique de tranche A accepte des effectifs initialement
indemnes. La projection d’armées déjà blessées n’est pas annoncée tant que leur
traitement métier n’est pas arbitré.

## Batch natif

Le batch `waar-combat-batch-request/2` reçoit une liste de scénarios et une plage
d’itérations. La préparation est faite une fois par scénario, les combats restent
dans Rust, et seules les sommes entières ressortent. Si les conséquences sont
demandées, elles sont calculées combat par combat avant agrégation. Le résultat
indique explicitement sa plage et si elle est complète. Deux plages se recomposent
par addition de leurs totaux, jamais par moyenne de moyennes.
Les tableaux natifs annoncent leur ordre : `soldier, spearman, archer, knight`,
puis `healthy, wounded, dead, prisoners` pour les catégories projetées.

`DemoRequestFactory::batch()` construit les 16 confrontations monotypes ordonnées
au budget de 400 400, avec les observations des deux rôles et 100 répétitions.

## Vérifier

Depuis la racine du dépôt, avec PHP 8.2 64 bits, FFI et Rust :

```powershell
powershell -ExecutionPolicy Bypass -File engines/waar-cohort/bin/verify.ps1
php -d ffi.enable=1 engines/waar-cohort/bin/demo.php
php -d ffi.enable=1 engines/waar-cohort/bin/benchmark.php
```

Le script construit `waar_cohort.dll` en release (`libwaar_cohort.so` ou `.dylib`
ailleurs), exécute Rust puis la parité PHP/Rust et la démonstration. La variable
`WAAR_COHORT_DLL` permet de sélectionner une bibliothèque explicitement.

Le CLI `rust/target/release/waar-cohort-cli` accepte une ligne JSON contenant
`{"operation":"resolve","request":...}` ou `{"operation":"batch","request":...}`.
Une étude complète traverse donc la frontière native une seule fois.

## Mesures

Les chiffres sont reproductibles avec `bin/benchmark.php`; ils dépendent de la
machine et du build release. Ils distinguent le coût d’un appel FFI de duel, le
batch brut, le batch avec projection et le nombre final de cohortes d’un combat
mixte. Ils ne doivent pas être présentés comme les anciens 70 000 combats/s sans
rejouer exactement la même charge.

La première mesure de tranche est conservée dans
[`reports/slice-a-benchmark-2026-09-13.json`](reports/slice-a-benchmark-2026-09-13.json).
