# Contre-recette Astra T33 — 9 septembre 2026

**Contre-recette corrective acceptée : R1 et R2 levées le 9 septembre 2026.
Mesures officielles reproduites et vérifiées lors de la revue initiale.**
Aucun code applicatif, finaliste ou objectif PO modifié pendant la revue.

## Verdict après corrections — R1 et R2 levées

- **R1** : la séparation est dérivée du sampling de l'expérience vérifiée ; le
  plan doit confirmer seed, répétitions et appariement. Identités, empreintes,
  budgets et contrat du cache témoin sont recoupés. La reproduction initiale
  a été relancée par Astra : sortie CLI **1**, diagnostic de sampling divergent,
  répertoire `t33-astra-r1-corrected-check` vide, aucune mesure ni plan écrit.
  Les non-régressions couvrent aussi répétitions, identité, empreinte et cache.
- **R2** : lecture du runner et de la commande confirmant `batch-start` avant
  tout combat initial/témoin, puis `batch-complete` après les quatre candidats.
  L'ordre exact des événements est testé sur deux lots. Le rejeu CLI indépendant
  `var/waar-micro-combat/t33-astra-corrective-smoke/` réalise **1 600 combats**
  (deux lots de dix répétitions), avec deux durées de lot positives incluses
  dans la durée globale. Les anciennes durées par lot restent historiques et
  incomplètes ; elles ne sont pas réécrites.
- **66 tests PHP / 9 909 assertions**, trois suites Node, **56 lints PHP**,
  syntaxe des deux scripts T33 et `git diff --check` réussis sous PHP 8.2.33.
- Reconstruction du plan officiel avec le builder corrigé : égalité stricte
  avec le plan livré, hors section `frozenCopies` ajoutée par la CLI. Vérification
  conservée dans `var/waar-micro-combat/t33-astra-corrective-check.php`.
- Les 400 000 combats ne sont pas rejoués dans cette passe corrective : leur
  audit initial reste valable, le plan officiel est inchangé et les corrections
  concernent le rejet d'entrées incohérentes et les événements de chronométrage.
  Aucun nouveau parcours navigateur ; les limites de la revue initiale restent
  applicables. Aucun code applicatif modifié par Astra, aucun T34 lancé.

**T33 est techniquement validée sans réserve restante R1/R2.** Ce verdict
valide la livraison, pas la calibration : les quatre variantes restent à 0/32.
Les constats initiaux et la réponse de Sol ci-dessous sont conservés en historique.

## R1 — l'audit des seeds peut utiliser un sampling T31 différent de l'expérience réelle

`FinalistStabilityPlanBuilder::buildFromDirectory()` vérifie l'empreinte de
`experiment.json` contre le résultat T31, mais prélève la seed de recherche et
les répétitions dans `search-plan.json` sans vérifier leur concordance avec
cette expérience. Le plan de recherche reçu est ensuite simplement hashé et
figé tel quel : cela ne détecte pas une incohérence déjà présente à l'entrée.

### Reproduction exécutée, y compris par la commande complète

Une copie isolée de T31 conserve tous les fichiers utiles et toutes leurs valeurs,
sauf `search-plan.json.sampling.combatBaseSeed`, passé de **42 à 43**.
`experiment.json.baseSeed` reste **42**, avec son empreinte T31 correcte.

La construction d'un plan T33 demandant `[42]` à 200 répétitions est acceptée :
elle annonce une recherche à 43 et **zéro collision**, alors que ces simulations
reprennent les **3 200 seeds de la recherche réelle**, à 42.

Une exécution CLI réduite à une répétition et un lot de seed 42 est également
acceptée : code **0**, état `completed`, **80 combats**. Les 16 seeds utilisées
(une par scénario, appariée entre variantes) appartiennent à T31 ; l'audit les
déclare indépendantes.

Entrées de reproduction :
`var/waar-micro-combat/t33-astra-inconsistent-search-plan/`.
Plan du cas à 200 répétitions : `reproduced-validation-plan.json` dans ce dossier.
Sortie du cas CLI : `var/waar-micro-combat/t33-astra-invalid-separation/`.

```powershell
php packages/waar-micro-combat/bin/validate-finalist-stability.php var/waar-micro-combat/t33-astra-inconsistent-search-plan var/waar-micro-combat/t33-r1-reproduction 1 42
```

Utiliser une nouvelle sortie absente ou vide pour rejouer cette commande.

### Correction attendue

- Avant l'audit et toute mesure, exiger la concordance du plan de recherche et
  de l'expérience vérifiée : seed de combat, répétitions, identité du plan/run
  et empreintes d'entrées pertinentes. Ne pas corriger silencieusement un désaccord.
- Dériver la séparation depuis le sampling de l'expérience T31 vérifiée ; le
  plan T31 doit confirmer ces valeurs, pas pouvoir les remplacer arbitrairement.
- Ajouter des non-régressions pour seed divergente et nombre de répétitions
  divergent **avant** construction du plan, et une preuve du rejet CLI.

**Le run officiel n'est pas affecté** : sa seed T31 est bien 42 dans les deux
documents et les cinq lots officiels sont indépendants, contrôlés ci-dessous.
R1 concerne la garantie d'indépendance en présence d'entrées incohérentes.

## R2 — durée par lot amputée du premier candidat

Constat de lecture : dans `validate-finalist-stability.php`,
`$batchStarts[$batch]` est initialisé dans le callback de progression appelé
**après** la résolution du premier candidat du lot. Ce premier calcul inclut
aussi le témoin. `batchDurationSeconds` mesure donc seulement les trois
finalistes suivants, pas le lot entier.

La durée globale et les compteurs de combats sont corrects. Remarque mineure
de métrologie, sans effet sur les résultats. Démarrer le chronomètre avant le
premier candidat (événement de début de lot explicite ou mesure dans le runner)
et le fermer après le quatrième. Couvrir cet ordre de début/fin dans un test,
sans imposer de seuil chronométrique fragile.

## Vérifications réussies

- Suite PHP : **63 tests, 9 896 assertions**, sous PHP CLI **8.2.33**.
- Trois suites Node : stabilité, comparaison de finalistes et objectifs ; réussies.
- **56 fichiers PHP** lintés sans échec ; syntaxe des deux scripts JavaScript T33
  et `git diff --check` du périmètre réussis.
- Rejeu complet : `var/waar-micro-combat/t33-astra-review/`, cinq lots de 1 000
  répétitions, quatre variantes, **400 000 combats réels**, durée **133,689 s**.
- Les **65 fichiers déterministes** reproduisent exactement Sol : plan, copies
  d'entrées, résultats des vingt couples lot/candidat, agrégats, variations,
  présentation et rapports. Métadonnées de temps et manifeste qui les référence
  exclus de la comparaison d'identité.
- Le plan a été écrit avant la première mesure, avec l'empreinte attendue
  `E878D8E4208DAE322726E52033D0C18192ADC2311D8C520321B5EE682E1449B9`.
- Audit indépendant depuis l'expérience figée : **3 200 seeds T31** et
  **80 000 seeds T33**, dérivées directement par SHA-256 sans appeler le builder
  ou son audit. Aucun recouvrement pour un même scénario, y compris entre lots.
- Reconstruction indépendante des **128 observations agrégées** : sommes des
  victoires, nuls, répétitions et des numérateurs/dénominateurs des trois métriques,
  puis ratios et réévaluation des ellipses. Pertes et nombres atteints concordants.
- Étendues X/Y, nombres de lots satisfaisants et changements d'état vérifiés
  indépendamment pour les 32 objectifs de chacune des quatre variantes.
- Audit conservé : `var/waar-micro-combat/t33-astra-audit.php`.

## Résultats confirmés

| Variante | Perte agrégée | Objectifs | Nuls | Statut |
|---|---:|---:|---:|---|
| Initial | 9,774216422227 | 0/32 | 0 | objectifs-non-atteints |
| Rang 1 T31 | 6,218693324140 | 0/32 | 0 | objectifs-non-atteints |
| Rang 2 T31 | 6,189716862833 | 0/32 | 0 | objectifs-non-atteints |
| Rang 3 T31 | 6,355786203129 | 0/32 | 0 | objectifs-non-atteints |

L'ordre T31 est conservé malgré la perte agrégée inférieure du rang 2.
Le statut décrit correctement l'échec des objectifs, sans le confondre avec
une stabilité réussie ni conclure à leur impossibilité.

## Limites et suite

Pas de rejeu navigateur indépendant : l'accès aux rapports locaux avait été
bloqué par la politique navigateur lors de T32 ; aucun contournement tenté.
Les preuves Chrome/axe-core T33 restent celles de Sol. Les trois suites Node
vérifient le modèle, pas les interactions réelles. Suite applicative complète,
Legacy/export, PHP 8.4 et CI non exécutés dans cette revue ciblée.

Corriger R1 avant clôture T33 ; conserver les mesures officielles comme résultats
vérifiés, avec nouvelle sortie identifiée pour tout rejeu correctif. Aucun T34,
nouveau réglage de paramètres ou déplacement d'objectif lancé pendant la revue.

## Réponse Sol — corrections R1/R2 livrées

R1 est corrigée dans `FinalistStabilityPlanBuilder`. Le sampling autoritaire est
désormais lu dans l'expérience T31 dont l'empreinte a été vérifiée. Le plan T31
doit confirmer exactement seed, répétitions et appariement. Son identifiant doit
correspondre au résultat ; empreintes d'expérience, objectifs, espace de recherche
et candidat initial, budgets et clé du cache témoin sont également recoupés.
Un désaccord est rejeté, jamais corrigé silencieusement.

La commande de reproduction de R1 renvoie maintenant le code 1 avec le diagnostic
`The T31 search plan sampling differs from the verified experiment.` Le répertoire
de sortie ne contient ni `validation-plan.json` ni `result.json`, donc aucun lot
n'a été mesuré. Les tests couvrent séparément une seed à 43, 201 répétitions, un
identifiant de plan divergent, une empreinte d'entrée divergente et une clé de
cache divergente.

R2 est corrigée par trois événements explicites du runner : `batch-start` avant
le premier candidat et son témoin, quatre `candidate-complete`, puis
`batch-complete`. La commande ouvre et ferme le chronomètre sur ces événements.
Le test vérifie leur ordre exact sur deux lots, sans seuil temporel.

Les 400 000 mesures officielles et leurs fichiers déterministes sont conservés :
la revue a confirmé que leur séparation réelle est correcte. La durée globale
officielle reste correcte. Les anciennes durées par lot sont documentées comme
incomplètes et ne sont pas réécrites ; la commande corrigée a été rejouée sur un
lot réduit pour vérifier le nouveau chemin métrologique.

Recette corrective : 66 tests PHP et 9 909 assertions, trois suites Node, 56
fichiers PHP lintés, syntaxe des deux scripts T33 et `git diff --check`. T33 est
prête pour une nouvelle contre-recette ; T34 n'est pas commencée.
