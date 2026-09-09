# Contre-recette Astra T31 — 9 septembre 2026

**T31 acceptée sans réserve dans son périmètre de recherche bornée.**
Aucun code applicatif modifié pendant cette revue. Cette validation technique
ne signifie pas qu'un candidat satisfaisant le design PO a été trouvé.

## Contrat et implémentation examinés

- Lecture de la stratégie globale/locale, quantification, identité paramétrique,
  déduplication, plafonds de propositions et d'évaluations, classement et exports.
- Le candidat initial est évalué en premier et conservé ; le classement reste
  la perte T29c croissante puis l'empreinte en cas d'égalité exacte. Les critères
  d'acceptation stricte et l'exclusion des finalistes avec nuls sont distincts.
- Lecture de la réutilisation du témoin : contrat de variante, corpus ordonné,
  répétitions et seeds contrôlé ; seul le candidat est recalculé lors d'un hit.
- Les trois finalistes sont rejoués par le runner complet, sans cache, avant
  export et avec comparaison stricte de leur évaluation à celle de recherche.
- Les champs figés T30 et les objectifs PO sont conservés. La décision de passage
  à T31 et d'acceptation des bornes pour ce run est consignée dans le suivi Sol.

## Vérifications exécutées

- Suite PHP du paquet : **54 tests, 9 037 assertions**, réussis.
- Suites Legacy/export : **13 tests, 201 assertions**, réussis.
- Total : **67 tests PHP, 9 238 assertions**, sous PHP CLI **8.2.33**.
- Suite Node `acceptance-zones-model.test.js` : réussie.
- **46 fichiers PHP** lintés, aucun échec ; `git diff --check` sur le périmètre réussi.
- Rejeu smoke (8 évaluations) : `var/waar-micro-combat/t31-astra-smoke/`.
- Rejeu standard (128 évaluations) : `var/waar-micro-combat/t31-astra-standard/`.
- Chacun des deux runs reproduit les **18 artefacts déterministes** de Sol
  octet pour octet, y compris JSONL et variantes/évaluations/rapports des finalistes.
  Seules les métadonnées d'exécution sont exclues de cette comparaison.
- Copies de l'expérience, des objectifs et du manifeste comparées aux sources
  courantes : identiques octet pour octet.
- Audit indépendant des exports : identités uniques, bornes, grille, champs
  figés, cibles conservées, recalcul des **4 096 contributions** du standard
  et des **256 contributions** du smoke, moyennes et pires objectifs.
  Recalcul du classement, de chaque étape de progression, des finalistes et
  des nombres de combats : concordance complète.
- Le script de cet audit est conservé dans
  `var/waar-micro-combat/t31-astra-audit.php`. Il lit les rapports sans exécuter
  le service de classement ni les évaluateurs de perte applicatifs.
- Arrêt CLI avec budget 8/plafond de propositions 1 : un seul candidat évalué,
  résultat `interrupted-proposal-limit`, artefacts exploitables et un finaliste.
  Sortie : `var/waar-micro-combat/t31-astra-proposal-stop/`.
- Refus CLI d'une sortie smoke non vide : code **1**, aucune modification des
  fichiers, empreintes comparées avant/après.

Durées observées pour cette contre-recette : smoke **24,188 s**, standard
**193,646 s**. Des tests et calculs ont été exécutés concurremment : ces mesures
décrivent cette exécution, pas un benchmark isolé.

## Résultats confirmés

| Run | Évaluations | Meilleure perte | Objectifs du meilleur | Finalistes sans nul | Candidats stricts |
|---|---:|---:|---:|---:|---:|
| Smoke | 8/8 | 9,306561977486 | 0/32 | 3 | 0 |
| Standard | 128/128 | 6,173725600720 | 0/32 | 3 | 0 |

Perte initiale : **9,739174155519**. Le standard exécute **432 000 combats**,
dont 409 600 candidat et 3 200 témoin pendant la recherche, puis 19 200 combats
pour les relectures complètes des trois finalistes.

Les finalistes sont identiques à ceux livrés :
`t31-candidate-0116-d0c5f6473d05`, `t31-candidate-0128-96382d7e8496`,
`t31-candidate-0123-3805dc464a60`, dans cet ordre.

Le candidat intermédiaire à **2/32** et perte **6,725911717271** est bien présent,
mais ne surclasse pas le meilleur à **0/32**, dont la perte est plus faible.
C'est le classement demandé, sans substitution du nombre d'objectifs au score.
Les trois finalistes restent exploratoires : aucun candidat strictement
satisfaisant n'a été trouvé dans ce budget.

## Suite et limites

T32 peut consommer les artefacts standard acceptés pour la comparaison visuelle.
Aucun élargissement de bornes ni recherche supplémentaire demandé par cette revue.
Pas de test navigateur requis pour ce CLI ; la suite applicative complète,
PHP 8.4, la CI et l'intégration Legacy n'ont pas été exécutés.
