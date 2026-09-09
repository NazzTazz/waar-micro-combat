# T34 — contre-recette légère Astra, 9 septembre 2026

Mesures officielles reproduites ; réserve R1 de provenance avant clôture.

- Tests ciblés : 4 tests PHP, 499 assertions ; modèle Node T34 réussi.
- Rejeu complet : 24 000 combats, 10 renversements, 13,727 s sous PHP 8.2.33.
  Sortie indépendante : `var/waar-micro-combat/t34-astra-light-review/`.
- Plan, résultat, présentation, HTML et Markdown identiques par SHA-256 aux
  cinq artefacts officiels. Corpus canonique protégé par empreinte ; seeds
  appariées, variantes et départage défenseur conservés dans le chemin relu.
- Revue limitée : pas de suite complète, audit exhaustif des métriques ou
  parcours navigateur indépendant. Aucun code applicatif changé.

## R1 — lien plan/résultat T33 non vérifié

`MixedCompositionObservationPlanBuilder::build()` compare les identifiants
T33, mais ne compare pas le SHA-256 du plan reçu à `result.planSha256`.
Reproduction indépendante : copie isolée de T33, première seed du plan remplacée
par 42, résultat inchangé. Le builder accepte malgré l'empreinte divergente.
Script : `var/waar-micro-combat/t34-astra-provenance-check.php` ; entrées :
`var/waar-micro-combat/t34-astra-inconsistent-t33/`.

Corriger avant clôture : vérifier cette empreinte avant construction du plan T34,
recouper les empreintes des variantes entre candidats du plan/résultat et copies,
et ajouter une non-régression rejetant un plan altéré avant toute mesure.
Les mesures officielles reproduites ne sont pas affectées par cette reproduction.

## Parcours utilisateur sans quota

Ouvrir `var/waar-micro-combat/t34-mixed-composition-observation/report.html`.
Choisir un finaliste, puis parcourir les six scénarios et les trois axes.
Les flèches vont de l'initial au finaliste ; X indique la victoire, Y la mesure
sélectionnée. Comparer particulièrement effectifs et économie dans les armées
mixtes. Les budgets inégaux de T24 sont intentionnels.

Contrôler les pertes par type dans le tableau ; exporter JSON/CSV pour garder
les valeurs. Noter scénario, rang, axe et observation souhaitée pour la reprise.
Le rapport est autonome, sa consultation ne consomme pas de quota de modèle.
T33 reste à 0/32 ; T34 décrit des effets, sans valider un équilibrage.
