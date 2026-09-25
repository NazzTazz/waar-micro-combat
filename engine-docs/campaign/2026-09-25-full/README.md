# Campagne complète — météo commune, coupe du 25 septembre 2026

Cette coupe figée publie les **157 plans VPS terminés** et **30 plans PC complets**
copiés le 25 septembre entre 16:49 et 17:14 UTC. Les 187 plans passent l'audit
des lots et exports sans rejet : 48 596 000 combats inscrits, 41 152 000 après
déduplication de 3 710 directions effectives répétées. Les 30 plans PC incluent
18 plans M actifs et douze diagnostics antérieurs ; ces comptes ne sont donc pas
le seul total des plans actifs. Aucun combat n'a été lancé pour cette analyse.

## Décision produit et portée de W

Le PO a précisé le 25 septembre que **la météo est commune aux deux armées d'un
même combat**. Le protocole W antérieur faisait aussi varier la météo d'A seule
ou de B seule. Sur ses 26 640 000 combats inscrits, **25 200 000** opposent des
météos différentes et sont exclus des conclusions de gameplay. Ils restent
traçables dans les agrégats publiés. Les **1 440 000** combats à météo commune
donnent **1 080 000** combats effectifs uniques après déduplication. Les 80 plans
`W-factor-*` appartiennent tous au groupe exclu. Voir le
[comptage vérifiable](weather-context-audit.json) et les [constats](findings.md).

Les comparaisons W exploitables portent sur dix plans de presets : mêmes armées,
budgets, sens et 2 000 répétitions par point, météo partagée dans chaque combat.
Le [CSV dédié](shared-weather-comparisons.csv) comporte 540 comparaisons à
`neutral`. Parmi elles, 60 `cloudy` ont une requête effective identique au
témoin ; les 480 autres changent réellement le contexte. Ce sont des monotypes,
pas une validation des compositions mixtes sous météo.

## Lire et contrôler les résultats

1. [Constats exploratoires](findings.md), [figure météo](figures/04-shared-weather-spearman-archer.svg)
   et [CSV de la figure](figures/04-shared-weather-spearman-archer.csv).
2. [Comparaisons météo commune](shared-weather-comparisons.csv), puis
   [agrégats complets](aggregates.csv) et [comparaisons intra-plan](comparisons.csv).
   Ces deux derniers CSV conservent aussi les scénarios W asymétriques pour
   audit ; les filtrer avant toute interprétation de gameplay.
3. [Alias de doublons](duplicate-aliases.csv),
   [manifeste d'analyse](input-manifest.json),
   [inventaire et SHA des sources](snapshot-source.json),
   [plans exacts](plans/) et [profil authentique](../profile.json).

Les trois figures M déjà [publiées dans la première coupe](../2026-09-24-initial/README.md)
et les deux figures E/X de la [coupe précédente](../2026-09-24-e-x/README.md)
ne sont pas recopiées. Les coupes se chevauchent : ne pas additionner leurs
combats comme des échantillons indépendants.

Le profil est `Nazz-Eq-20%-Rc2`, SHA-256
`4018d6ce3fdab2705dd6d3b5f1b78959ee6e86c653ce5e02b7627cae78557765`.
Les manifestes lient plans, exports, code Rust et binaire Linux. Les plans copiés
gardent les chemins de l'environnement source et ne s'exécutent pas directement
depuis `engine-docs/`. Les requêtes et réponses batch originales restent dans le
snapshot local ignoré sous
`reports/campagne-coeur/analyse/snapshots/2026-09-25T184900-full` ; les scripts
de capture et d'analyse restent sous `reports/campagne-coeur/analyse/`. Un clone peut
contrôler les agrégats publiés, mais ne peut pas refaire l'audit intégral des lots.

Les intervalles de Wilson sont marginaux sous l'hypothèse d'échantillonnage du
protocole. Les lots contiennent comptes et sommes, ni trajectoires individuelles
ni différences appariées. Les constats n'approuvent ni le moteur ni un réglage
d'équilibrage. Aucun moteur, profil, plan actif, worker ou déploiement n'a été
modifié pour cette publication.

## Historique et portée

ATT-07/ATT-08/ATT-14 ; sources relues : registre produit, consignes d'Astra
`PROMPT-SOL-ANALYSE-CAMPAGNE-WAAR.md`, protocole `ops/campaign/README.md`, plans
effectifs, auditeur, coupes précédentes et code Rust `v2.rs` correspondant au SHA
déclaré. Réemploi de la structure de la PR #21. Échec à éviter : interpréter
les météos différentes d'A et B comme un combat Waar valide, compter deux fois
un témoin, ou additionner morts physiques et conséquences projetées. L'écart
traité est la disponibilité de W et sa portée réelle après la correction du PO.
Les données publiées sont sur `docs/publish-full-campaign-2026-09-25`, issue de
`main` `fb7e6f0` ; le checkout principal avec ses modifications antérieures est
préservé. Prochaine lecture : contre-analyser les constats avant tout nouveau
protocole W à météo commune.
