# Expérience locale — budgets natifs Legacy / candidat 116

Demande : cinq compositions cibles, exprimées en parts du coût de construction, à 20k, 50k et 80k. Matrice complète des quinze armées, avec budgets inégaux : 225 duels ordonnés.

- Ordre : soldat, lancier, archer, chevalier.
- Coûts Legacy : 10 / 70 / 70 / 550.
- Coûts candidat 116 : 80 / 110 / 130 / 350.
- Cibles : 100/0/0/0, 70/30/0/0, 70/0/30/0, 70/0/0/30, 0/40/40/20.
- Tous les budgets sont exactement dépensés. Les effectifs minimisent la somme des écarts quadratiques aux parts demandées, sans introduire de type absent ; départage par ordre lexicographique croissant des effectifs.
- 1 000 répétitions par moteur et duel, seed 49979687, soit 450 000 combats. Le Legacy est vérifié au préalable par reproduction des 1 200 combats de sa référence T24.

Le rapport est `reports/budget-matrix-116/report.html`. Les données, le plan antérieur aux mesures, le code local d’orchestration et les empreintes restent dans ce même répertoire ignoré par Git. Il ne faut pas les confondre avec les références historiques figées.

Le runner local appelle les services purs du micromoteur et l’oracle Legacy dans le dépôt frère `../waar-v3`, sans modifier celui-ci, charger Symfony/Doctrine, ni recopier ses règles dans le paquet autonome. Ce dépôt frère est une dépendance explicite de l’expérience locale seulement. Le générateur d’effectifs reste un service pur du paquet, couvert par tests.

Pour un rejeu : copier `run.php`, `render.php` et `template.html` dans un nouveau sous-dossier vide de `reports/`, puis exécuter `php reports/<nouveau-dossier>/run.php` et `php reports/<nouveau-dossier>/render.php`. Le runner refuse un dossier contenant déjà `plan.json`. Ne pas relancer une recherche T31 ou une validation T33 pour reproduire cette expérience.

La comparaison porte sur des proportions restantes, normalisées par l’effectif initial ou le budget natif de chaque moteur. La vue dilatée amplifie seulement les pertes Legacy ×20 et conserve les valeurs réelles. Les scores et deltas d’effectifs utilisent cette référence transformée. Aucun appariement statistique inter-moteurs, aucun changement de candidat, de gameplay ou d’objectif PO.
