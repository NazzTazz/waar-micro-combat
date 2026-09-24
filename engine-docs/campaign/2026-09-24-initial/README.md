# Première lecture de la campagne Waar — instantané figé

Instantané `2026-09-24T012437` (sélection VPS arrêtée le 23 septembre 2026 à 23:24:38 UTC). Il s'agit d'une **analyse exploratoire des plans terminés à cet instant**, pas de la campagne entière, d'une validation globale du moteur ou du manuel. Aucun combat n'a été lancé pour cette analyse. Les workers VPS ont continué indépendamment.

## Corpus et provenance

- 70 plans complets admis : 40 copiés du VPS et 30 résultats locaux PC, dont 18 plans M et 12 diagnostics/confirmations. Aucun plan complet exclu pour incohérence.
- 77 140 lots audités ; 7 642 lignes expérience/sens ; **15 428 000 combats inscrits**. Après retrait de 1 234 directions effectives dupliquées entre plans (2 492 000 combats), **12 936 000 combats uniques retenus** dans 6 408 directions effectives. Les témoins répétés restent traçables dans `duplicate-aliases.csv`, mais ne grossissent pas l'échantillon.
- Profil authentique des résultats : `Nazz-Eq-20%-Rc2`, SHA-256 `4018d6ce3fdab2705dd6d3b5f1b78959ee6e86c653ce5e02b7627cae78557765`, seuil de blessure 0,2. Ne pas le confondre avec un profil Astra-RC1.
- HEAD déclaré par les résultats : `bd79dff8e07e85c6d1c01d6adaaee2382576b459`, avec modifications locales capturées par empreintes de sources. Rust `waar-cohort-v2`, RNG `sha256-binomial-tree/1`. Source `engines/waar-cohort/rust/src/v2.rs` identique dans les 70 manifestes (SHA-256 `c413c842d6f1296a7fe701a1b00976ecc13e8ba1c813ca6ddfbf9bef465000b0`), binaires PC et Linux distincts ; empreintes et politique de conséquences détaillées dans `input-manifest.json`. Un lot témoin Windows/Linux identique est documenté dans `ops/campaign/README.md` ; ce n'est pas une certification générale de parité.
- Hors corpus : un plan Windows partiel de 1 600 combats, son témoin technique de 200 combats, les smoke tests et replays individuels D3. Ils ne sont ni mélangés ni comptés ici.

À la coupure, **117 plans actifs n'étaient pas disponibles en entier** : 5 M, 90 W (météo), 6 E (ajout/remplacement), 16 X (compositions et échelle). Les trois confirmations C indépendantes ne figurent pas dans la liste des plans actifs initiaux, mais sont incluses comme plans complets distincts. Aucune conclusion générale sur W/E/X ne découle de cet instantané. La liste précise se trouve dans `input-manifest.json`.

## Contrôles et sens des données

Le script vérifie les empreintes des plans/profils/exports, l'identité du runtime, les configurations, les deux rôles, les bornes de chaque lot et la couverture exacte des répétitions sans trou ni chevauchement. Il recompute les comptes/sommes des réponses batch et contrôle toutes les colonnes numériques de chaque CSV source. Les 70 plans admis passent ces contrôles ; ils ne prouvent ni justesse de chaque règle de gameplay ni parité universelle des binaires.

`aggregates.csv` contient une ligne par expérience et sens. Les pertes physiques et conséquences sont des **moyennes par combat** dérivées de sommes entières ; victoires/nuls/défaites sont des comptes. `comparisons.csv` compare chaque variante à son témoin du **même plan, scénario, contexte et sens**, avec tailles, budgets, météos, effets et graines. Un coût à effectifs fixes ne constitue pas une confrontation à budget égal. Les colonnes `wilson_attacker_low/high` sont des intervalles de Wilson marginaux à 95 % pour le taux de victoire ; elles ne testent pas une différence appariée. Les sorties batch ne conservent ni résultats individuels, ni quantiles, ni covariance, ni différences par répétition.

`bench_economic_loss_rate` = somme, aux prix du profil effectif, des **morts + blessés projetés**, divisée par le coût réel initial du camp. Les prisonniers sont exclus. C'est une convention de banc, pas une facture de soins. Les morts et blessés physiques ne s'additionnent pas aux catégories projetées.

## Ordre de lecture

1. [findings.md](findings.md) : cinq constats priorisés, mécanismes, limites et questions de gameplay.
2. [figures/](figures/) : reddition, deux modes de coût et seuil de blessure ; chaque SVG a son CSV source homonyme.
3. [comparisons.csv](comparisons.csv) pour toutes les variations par contexte et [aggregates.csv](aggregates.csv) pour les comptes originaux.
4. [input-manifest.json](input-manifest.json) et [duplicate-aliases.csv](duplicate-aliases.csv) pour provenance, couverture et déduplication.
5. [plans/](plans/), [profil](../profile.json) et [snapshot-source.json](snapshot-source.json) : entrées déclaratives, inventaire et empreintes SHA-256 du snapshot source.

Les requêtes/réponses batch et configurations effectives générées restent dans le snapshot local `reports/campagne-coeur/analyse/snapshots/2026-09-24T012437`, **non publié dans cette branche**. Les analyses ne modifient pas ces sources. Les propositions de prochaines expériences ne sont pas publiées comme résultats.

## Reproduction locale, avec le snapshot original uniquement

L'audit intégral des lots exige le snapshot local original et les scripts d'analyse qui restent sous `reports/` (ignoré par Git). Un clone de cette branche peut contrôler les tableaux et les constats publiés, mais ne peut pas recalculer les lots non publiés. Leurs empreintes et leur inventaire figurent dans `input-manifest.json` et `snapshot-source.json`.
