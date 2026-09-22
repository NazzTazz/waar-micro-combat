# Moteur Waar par cohortes — tranche A

Ce dossier est l’adaptation isolée du moteur par cohortes importé de `waar-v3`.
Son contrat actif est `waar-cohort-v2`. La soufflerie est raccordée depuis la tranche B.
Les copies de référence sous `engines/waar-v3` et les fichiers
figés du dépôt ne sont pas modifiés.

## Protocole aléatoire courant — #16, 22 septembre 2026

La soufflerie demande désormais `stochasticEngineVersion: sha256-binomial-tree/1`,
avec `armyIdentities` A/B conservées lorsque les rôles s'inversent, et la politique
de conséquences `wounded-capture-then-compress/4`. Tous les usages sont adressés
séparément. Le tirage binomial agrégé conserve des réussites emboîtées quand p
augmente à adresse et nombre de tentatives fixes ; aucune approximation normale
n'est employée par ce protocole. Les compartiments de dégâts sont triés avant
allocation, sans identité individuelle inventée.

Requêtes sans ces champs : protocole historique LCG et anciennes politiques
conservés. Les versions et identités figurent dans le snapshot, le batch, le rejeu
et les contextes de mesure. Une politique /4 sans nouveau protocole, ou un nouveau
protocole sans identités distinctes, est rejeté. Les passages sur /3 ci-dessous
décrivent la livraison antérieure, toujours disponible pour le rejeu.

Contrat, preuves et limites : [spécification §8.7](../../docs/spec-moteur-cohortes-soufflerie-2026-09-13.md#87-amendement-16--hasard-adressé-et-binomiale-couplée-22-septembre-2026),
[PR #17](https://github.com/NazzTazz/waar-micro-combat/pull/17).
Recette ciblée sans navigateur :

```powershell
cargo build --locked --release --manifest-path engines/waar-cohort/rust/Cargo.toml
php -d ffi.enable=1 vendor/bin/phpunit --bootstrap engines/waar-cohort/autoload.php engines/waar-cohort/tests
php vendor/bin/phpunit tests/WorkshopAddressedRandomTest.php
cargo test --locked --manifest-path engines/waar-cohort/rust/Cargo.toml
```

La suite moteur est distincte de `composer test` : l'exécuter explicitement pour
la contre-recette RNG. Les nouvelles sorties ne constituent pas un équilibrage
accepté, et les contrôles bornés ne certifient pas tous les combats possibles.

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

La politique historique `wounded-capture-then-compress/2` reste le défaut des
requêtes sans `consequences.policyVersion` et de l'API PHP `project()`.
Ses troncatures sont inchangées. La soufflerie demande explicitement
`wounded-capture-then-compress/3` : capture binomiale des blessés capturables du
vaincu, puis conservation binomiale séparée des morts, blessés libres et prisonniers.
Un match nul ne produit aucun prisonnier. Le coût affiché valorise les morts et
blessés au prix d'achat, hors prisonniers ; ce n'est pas une facture de soins.

La version inconnue est rejetée. Le protocole `sha256-counter52-binomial-btrs/1`
apparaît dans `consequences.samplingProtocol` et dans la provenance du batch
`consequenceProvenance` (`floor/1` pour un batch historique). L'enveloppe simple
contient un `result` physique et un objet décrit par
[le schéma des conséquences](contracts/consequences.schema.json).
Les schémas physiques restent inchangés, les profils sauvegardés aussi.
Le rejeu conserve le sélecteur de la politique exécutée.

Le correctif est basé sur la production `3cbc8ab` ; il n'inclut pas le panneau
de comparaison proposé dans la PR #11. Dans cette interface, les mesures ne sont
pas persistées et sont recalculées au rechargement. Le contexte des nouvelles
mesures et objectifs contient la politique et le protocole ; les anciens contextes
sont refusés et leur géométrie ne peut être réassociée qu'explicitement.

Dette connue R1 de la [contre-recette #13](https://github.com/NazzTazz/waar-micro-combat/pull/13#issuecomment-5759781661) :
la version du ruleset construite depuis un profil inclut encore les taux de
compression/capture. Modifier ces seuls taux dans la soufflerie change donc le
`replayHash`, même lorsque les rounds et états physiques restent identiques.
Cette lacune préexistante n'est pas corrigée ici. Sa résolution devra séparer
l'identité physique du contexte de projection, conserver les replays historiques
et couvrir la construction depuis le profil, sans enlever les taux du contexte
des conséquences. Elle reste suivie dans #12 ; aucun constat de conformité totale
ou d'acceptation PO n'est déduit de cette livraison.

Le [§9.5 de la spécification](../../docs/spec-moteur-cohortes-soufflerie-2026-09-13.md#95-amendement-du-21-septembre-2026--politique-probabiliste-3)
fixe les octets du flux, le sampler, ses limites numériques et les vérifications.

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

### Recette locale RC-1 de la politique /3

Depuis un checkout de cette PR, utiliser un répertoire **neuf et isolé**. Le menu
Profil n'a pas d'import de profil : le script ci-dessous prépare une sauvegarde
RC-1 à partir de la fixture de #12, puis le testeur la charge par le menu existant.
Il refuse un répertoire déjà présent, ne résout aucun combat et ne modifie ni
profil partagé ni profil par défaut. Rust doit être construit pour ce checkout
avant de démarrer le serveur (`cargo build --locked --release --manifest-path
engines/waar-cohort/rust/Cargo.toml`).

```powershell
$rc1Directory = Join-Path $env:TEMP ('waar-rc1-' + [guid]::NewGuid().ToString('N'))
php bin/prepare-rc1-review.php $rc1Directory
if ($LASTEXITCODE -ne 0) { throw 'Préparation RC-1 échouée.' }
$env:WAAR_PROFILE_DIRECTORY = $rc1Directory
$env:WAAR_COHORT_RUNTIME = 'rust'
php -S 127.0.0.1:8096 -t public/workshop bin/workshop-router.php
```

Ouvrir `http://127.0.0.1:8096` dans un onglet de recette dédié. Dans Profil,
charger « RC-1 — recette isolée ». Choisir Beau temps, retirer les effets acquis,
puis saisir A=(3 soldats, 1 lancier, 0 archer, 18 chevaliers), B=(1 000 soldats,
0 lancier, 0 archer, 0 chevalier). Attendre la cartouche à jour et cliquer
« Simuler les deux sens ». Lire brut, projeté et provenance dans les analyses.
Chaque actualisation automatique coûte 100 combats ; chaque clic de détail en
résout deux. Éviter les rechargements et répétitions inutiles.

Suivre ensuite les cas et limites de [#12](https://github.com/NazzTazz/waar-micro-combat/issues/12) :
défaite K18L/L, R/C 10k, cycle 200k/400k, rejeu à seed égale et compression seule.
La dette R1 ci-dessus reste visible lors de ce dernier geste. Cette procédure
permet la recette ; elle ne constitue pas son acceptation.

### Outils moteur généraux

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
