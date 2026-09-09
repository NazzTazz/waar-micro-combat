# Contre-recette Astra T30 — 9 septembre 2026

**Statut final : T30 techniquement validée sans réserve après T30d ; R1 et R2 levées.**
Les bornes conservent leur statut de proposition. Aucun code applicatif modifié.
Les constats initiaux ci-dessous sont conservés comme historique.

## Clôture après T30d

- Suite PHP du paquet rejouée sous PHP 8.2.33 : **45 tests, 8 994 assertions**,
  réussis. Suite Node et lints des deux fichiers PHP corrigés réussis.
- Reproduction indépendante de R2 : référence avec contres réordonnés acceptée
  par `fromArray()` puis `validateExperiment()`, manifeste officiel inchangé.
- Identités manquantes, dupliquées et remplacées testées indépendamment : rejetées.
- Régression R1 contrôlée après réordonnancement : attaque du Soldat témoin
  modifiée à 70 toujours rejetée. **Les deux réserves sont levées.**
- Les six artefacts n'ont pas été régénérés dans cette dernière vérification
  ciblée ; leur identité lors de T30d est rapportée par Sol. La reproduction
  indépendante T30c reste documentée ci-dessous.
- Aucun changement de bornes, aucune recherche T31, aucun code applicatif modifié.

## Contre-recette du correctif T30c

- Le manifeste `t30.1-proposed` porte une empreinte canonique attendue du
  document de référence complet. Elle est vérifiée avant les paramètres et
  avant toute simulation, sans être remplacée depuis l'entrée reçue.
- La reproduction R1 conservée (attaque du Soldat témoin à 70) est désormais
  rejetée par la commande complète : **code 1**, erreur d'empreinte et aucun
  répertoire de sortie créé. **R1 levée.**
- Suite du paquet rejouée sous PHP 8.2.33 : **45 tests, 8 993 assertions**,
  réussis. Suite Node et lints PHP du service, de la commande et des tests réussis.
- Régénération isolée : `var/waar-micro-combat/t30c-astra-review/`.
  Les **six empreintes** correspondent exactement au relais corrigé de Sol.
  Empreinte du manifeste :
  `97421DEA05DB731FC1B7D1388FBB0714978E67CB77A1766F79D9B3225B94EA9E`.
  Empreinte de la validation :
  `5C7D1D6427225529A764BDD4343E9ED05AA66001B40009A1FF0B0A7B010F80AC`.
- Les 15 paramètres et leurs plages sont inchangés. Aucun calcul de recherche
  T31 n'a été lancé. Les suites Legacy/export n'ont pas été rejouées pour ce
  correctif local ; les autres limites de runtime/CI de la revue initiale restent.

### R2 — remarque mineure sur le réordonnancement des contres

La canonicalisation trie les contres et conserve bien l'empreinte si leur ordre
change. Cependant, avec le manifeste officiel inchangé et seulement la liste
`candidate.counters` inversée dans la référence, `fromArray()` rejette ensuite
l'entrée : `Frozen-field declaration does not match the T28 experiment.`

Cause : `validateFrozenDeclaration()` compare encore `counterIdentities` dans
l'ordre de la référence. Le test ajouté démontre l'invariance de l'empreinte,
pas l'acceptation du document réordonné par toute la chaîne.

Cela ne bloque pas l'usage de la référence officielle et ne réouvre pas R1.
Pour aligner l'acceptation sur la canonicalisation annoncée, comparer ces
identités comme un ensemble ordonné canoniquement et ajouter un test qui appelle
`fromArray()` sur la référence réordonnée avec le manifeste inchangé.

## R1 — le manifeste ne verrouille pas le contenu du témoin de référence

La commande construit `MonotypeSearchSpace` depuis l'expérience fournie, puis
appelle `validateExperiment()` sur cette même expérience. La comparaison du
témoin à la référence devient donc une comparaison de l'entrée à elle-même.

`validateSource()` vérifie les identifiants de l'expérience et du candidat,
mais aucune empreinte du contenu de référence. La déclaration
`baseline: entire-variant` exprime une intention sans identifier la variante
exacte à conserver. Les contrôles unitaires actuels couvrent une mutation
effectuée après construction de l'espace ; ils ne couvrent pas une référence
déjà modifiée lors de cette construction.

### Reproduction exécutée

1. Copier le manifeste d'expérience T28, sans modifier l'original.
2. Changer uniquement `baseline.units.soldier.attack` de `"7"` à `"70"`.
3. Conserver le manifeste T30, les objectifs et tous les identifiants inchangés.
4. Exécuter la commande de validation avec cette copie.

Résultat observé : code de sortie **0**, message **« T30 valide »** et
`checks.baselineFrozen = true`. Le témoin utilisé pour les comparaisons a
pourtant changé. Le candidat initial et son score monotype ne sont pas modifiés
par cette reproduction ; c'est la garantie de conservation du témoin qui échoue.

Entrée de reproduction conservée :
`var/waar-micro-combat/t30-astra-review/modified-baseline-input.json`.
Sorties : `var/waar-micro-combat/t30-astra-review-modified-baseline/`.

```powershell
php packages/waar-micro-combat/bin/validate-monotype-search-space.php var/waar-micro-combat/t30-astra-review/modified-baseline-input.json var/waar-micro-combat/objectives/20260909-po-design-01-canonical/acceptance-zones.json packages/waar-micro-combat/experiments/t30-proposed-search-space.json var/waar-micro-combat/t30-r1-reproduction
```

Utiliser un nouveau répertoire de sortie pour chaque reproduction.

### Correction attendue

Lier le manifeste T30 à une référence immuable par une empreinte attendue,
contrôlée avant simulation et construction de l'espace. Une empreinte du
document source complet convient, ou une empreinte d'une représentation
canonique explicitement définie. Le calcul de l'empreinte reçue dans le rapport
ne remplace pas sa comparaison à une empreinte attendue dans le manifeste.

Documenter la politique de canonicalisation : un changement de mise en forme
peut être accepté ou refusé selon le choix, mais un changement de valeur du
témoin doit être rejeté. Ne pas recalculer automatiquement l'empreinte attendue
depuis l'entrée modifiée. Versionner le manifeste corrigé et ses artefacts.

Ajouter une non-régression couvrant la référence modifiée **avant** l'appel
`fromArray()`/`fromFile()` et une preuve sur la commande complète. La référence
officielle doit rester valide et reproductible. Les futurs candidats ouverts
par T31 restent comparés à cette référence : leur variation autorisée ne doit
pas être confondue avec une modification de la référence figée.

## Contrôles réussis

- Suite PHP du paquet : **43 tests, 8 990 assertions**, sous PHP CLI 8.2.33.
- Suite Node `acceptance-zones-model.test.js` : réussie.
- Lints PHP du validateur, de la commande et des tests T30 : réussis.
- `git diff --check` sur le paquet et le suivi : réussi.
- Régénération dans `var/waar-micro-combat/t30-astra-review/` : les **six
  artefacts** officiels ont exactement les empreintes publiées par Sol.
- Refus de réutiliser la sortie non vide : code **1**, aucun fichier modifié,
  comparaison des empreintes avant/après.
- Lecture du manifeste : les 15 initiales, minima, maxima et pas correspondent
  au tableau T30 proposé. Les propositions brutes hors borne sont rejetées
  avant quantification ; la quantification utilise le fixed-point, moitié vers
  le haut, sans conversion flottante.
- Les contrôles exécutés par la commande officielle totalisent **6 464 combats**
  (3 232 candidat, 3 232 témoin), distincts d'une recherche qui reste non exécutée.

Les sondes tout-minimum/tout-maximum livrées passent sur les 16 scénarios.
Elles ne constituent pas une exploration exhaustive des combinaisons de bornes.

## Bornes et périmètre

Les plages restent celles proposées : ×0,5 à ×2, pas 0,001. Le rapport rend
correctement visible que les trois contres peuvent devenir inférieurs à 1 et
que les rôles ne sont pas garantis par ces plages. Cette revue ne transforme
pas ces propositions en règles d'équilibrage approuvées.

La clôture technique de T30 reste suspendue à R1. Aucun optimiseur, recherche
T31, changement de candidat ou activation n'a été effectué pendant cette revue.
Les suites Legacy/export, la suite applicative complète, PHP 8.4, le navigateur
et la CI n'ont pas été rejoués dans cette contre-recette ciblée.
