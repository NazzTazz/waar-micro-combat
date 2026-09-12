# Présentation synthétique du candidat 116

La présentation utilise le Legacy mesuré sur les 16 duels monotypes exacts du candidat 116. Chaque vecteur part du Legacy, avec pertes multipliées par 20, et arrive aux observations T31 du candidat. Les ellipses sont centrées sur le Legacy ; leurs rayons de présentation sont fixes (5 points de victoire, 10 points d’indice de pertes). Ce ne sont ni les objectifs PO, ni des intervalles statistiques, ni des critères d’acceptation.

Le HTML est autonome et fonctionne hors ligne. Il contient les valeurs brutes, les écarts par camp, les différences entre les moteurs et les quatre rôles narratifs. Même effectif par moteur ; budget égal au barème commun du candidat, pas aux coûts natifs Legacy.

## Génération

```powershell
php bin/observe-legacy-monotypes.php reports/legacy-monotypes-116
php bin/render-candidate-116-pitch.php reports/candidate-116-final reports/legacy-monotypes-116
```

La première commande nécessite l’oracle dans `../waar-v3`, dont les empreintes doivent correspondre à la référence T24. Elle vérifie les 1 200 combats historiques, puis observe 16 duels × 200 répétitions (seed 42), dans un nouveau dossier. Elle ne relance ni T31 ni T33. La seconde consomme les résultats et refuse d’écraser un dossier non vide. Les artefacts sont locaux, hors références figées.

Le candidat reste exploratoire. Le constat T33 (0/32 objectifs historiques atteints) est conservé comme provenance, sans utiliser ces objectifs pour les ellipses de présentation.
