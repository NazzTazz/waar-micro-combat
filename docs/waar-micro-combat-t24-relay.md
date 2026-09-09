# Relais T24 — corrections Astra de la soufflerie Waar

Statut : livré pour revue légère. Le quadruplet `roles-a`, le témoin, les graines et les 200 itérations sont figés. Seules deux compositions du corpus et la présentation des vecteurs changent.

Artefacts :

- soufflerie : `var/waar-micro-combat/t24/report.html` ;
- synthèse : `var/waar-micro-combat/t24/report.md` ;
- agrégats : `var/waar-micro-combat/t24/report.json` ;
- expérience rejouable : `var/waar-micro-combat/t24/experiment.json` ;
- source modifiable : `packages/waar-micro-combat/experiments/t24-astra-vector-corrections.json`.

Corpus exploré : effectifs attaquants seulement, sans variation du moteur. L'écran retenu utilise 22 Soldats et 8 Archers face à 20 Soldats et 8 Lanciers ; il produit `9,0 → 73,5 %` de victoires défenseur. Le duel Archer retenu utilise 12 Soldats et 22 Archers face à 12 Soldats et 18 Lanciers ; il produit `88,5 → 31,5 %` de victoires attaquant.

Écart persistant : le candidat améliore fortement le Lancier et le Chevalier, mais dégrade nettement l'Archer contre le Lancier. L'arbitrage suivant porte donc sur le rôle Archer, avec le corpus T24 désormais capable de montrer le mouvement.

Corrections visuelles : interpolation bornée à 200 ms depuis le dernier état, annulation de l'animation précédente, point unique en cas d'égalité, lecture des deux camps, rôle et effectif `n` visibles. La première tentative « deux camps » a révélé une boucle infinie de placement ; elle est remplacée par vingt essais alternés et bornés.

Preuves : 10 tests, 8 270 assertions, lints PHP, JSON, `node --check` et `git diff --check`. T23 est conservée avec son hash d'origine ; T24 porte le SHA-256 `1D3CFE2363690131324DB392588B4EB4DBFEEF08374F4B5E5F080BD82312549A` pour `report.json`.
