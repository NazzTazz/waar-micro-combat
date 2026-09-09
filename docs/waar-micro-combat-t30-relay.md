# Relais T30d — espace de paramètres inspectable

9 septembre 2026 — réserves R1 et R2 corrigées, prête pour contre-recette Astra.

## Correction R1

La contre-recette T30 a montré qu'une expérience déjà modifiée avant la
construction de l'espace pouvait devenir sa propre référence. Le manifeste
`t30.1-proposed` contient maintenant l'empreinte canonique attendue du document
T28 complet :

```text
4BA491716061F41CCB90A72E94A33E909716FB3ACC9B4C2408A85B85A9922E06
```

`MonotypeSearchSpace::fromArray()` compare cette valeur avant de construire
l'espace et avant toute simulation. La reproduction Astra où l'attaque du
Soldat témoin passe de 7 à 70 échoue avec le code 1, publie les empreintes
attendue/reçue dans l'erreur et ne crée aucun répertoire de sortie.

La canonicalisation `experiment-definition-json-v1` parse l'entrée avec
`ExperimentDefinition`, réexporte sa représentation structurée, trie les
contres de chaque variante par couple dirigé et encode un JSON compact UTF-8
sans échappement des barres ni d'Unicode. Elle ignore donc espaces, indentation,
fins de ligne et ordre des contres. L'ordre des scénarios et toutes les valeurs
restent significatifs.

## Correction R2

La contre-recette T30c a relevé une incohérence résiduelle : l'empreinte
canonique ignorait l'ordre des contres, mais la comparaison ultérieure des
`counterIdentities` conservait l'ordre du document de référence. Les identités
déclarées et reçues sont désormais comparées comme deux listes triées de couples
dirigés.

Le test de non-régression passe par la chaîne complète `fromArray()` avec les
contres du témoin et du candidat en ordre inverse. La référence est acceptée,
les quinze paramètres restent associés aux mêmes couples et une identité
manquante, remplacée ou dupliquée reste rejetée.

## Statut

T30 livre le manifeste et ses contrôles sans lancer de recherche. Les quinze
plages sont explicitement marquées `proposed-awaiting-review`. Leur validation
technique signifie qu'elles sont représentables et exécutables ; elle ne vaut
ni approbation d'équilibrage ni autorisation implicite de lancer T31.

Le rapport avertit que les minima des trois contres sont inférieurs à 1 et
peuvent inverser leur bonus initial. Les plages ne garantissent pas les rôles
des unités et aucune contrainte de rôle cachée n'entre dans l'objectif T29c.

## Rejeu

Depuis la racine du dépôt, vers un répertoire absent ou vide :

```powershell
php packages/waar-micro-combat/bin/validate-monotype-search-space.php packages/waar-micro-combat/experiments/t28-defender-tie-break.json var/waar-micro-combat/objectives/20260909-po-design-01-canonical/acceptance-zones.json packages/waar-micro-combat/experiments/t30-proposed-search-space.json var/waar-micro-combat/t30-search-space-proposed
```

Une seconde exécution vers le même répertoire échoue explicitement, car la
sortie n'est plus vide. Ce garde-fou empêche d'écraser ou de mélanger deux runs.

## Livrables

- Manifeste : `packages/waar-micro-combat/experiments/t30-proposed-search-space.json`
- Validateur pur : `packages/waar-micro-combat/src/Experiment/MonotypeSearchSpace.php`
- Commande : `packages/waar-micro-combat/bin/validate-monotype-search-space.php`
- Tests : `packages/waar-micro-combat/tests/MonotypeSearchSpaceTest.php`
- Artefacts : `var/waar-micro-combat/t30-search-space-proposed/`

Le manifeste ouvre exactement :

- attaque, structure et efficacité défensive pour Soldat, Lancier, Archer et
  Chevalier ;
- `Lancier → Chevalier`, `Archer → Lancier` et `Chevalier → Archer`, identifiés
  par couple dirigé et jamais par leur position dans le tableau.

Chaque minimum vaut 0,5 fois l'initial, chaque maximum 2 fois l'initial. Le pas
est `0.001`, avec arrondi au plus proche et moitié vers le haut avant identité du
candidat. Une proposition brute hors borne est refusée avant quantification.

## Champs figés

- témoin T28 complet ;
- identifiant et contenu des 16 scénarios, compositions et budgets ;
- 200 répétitions, `baseSeed = 42` et seeds appariées ;
- coûts `80/110/130/350`, trois rounds et dispersion `0.1` ;
- départage `defender` et identités des trois contres ;
- treize autres cellules de la matrice, implicitement neutres à 1 ;
- 32 objectifs PO, centres, rayons, identifiants et provenances.

Les identifiants, libellés et versions des futurs candidats sont des
métadonnées exclues de leur identité paramétrique, conformément à T31.

## Contrôles et coût réel

- Candidat initial : valide, aller-retour complet strictement identique.
- Vecteurs tout-minimum et tout-maximum : valides sur les 16 scénarios sans
  dépassement numérique.
- Rejets couverts : chemin inconnu, chemin dupliqué, contre dupliqué ou remplacé,
  valeur non finie, hors borne, hors grille et modification d'un champ figé.
- Réordonnancement des trois contres : accepté avec les mêmes associations et
  valeurs.
- Objectifs : 32 zones canoniques `survivors`, actives et confirmées.

La commande exécute `6 464` combats de contrôle : `3 232` candidat et `3 232`
témoin. Ils comprennent le prévol initial à 200 répétitions et deux sondes
min/max à une répétition par scénario. Le témoin est rejoué par le runner
autoritaire ; aucun cache n'est revendiqué. Les compteurs de recherche restent
à **0 candidat** et **0 combat**.

## Vérifications

- 45 tests PHP du paquet, 8 994 assertions.
- 13 tests Legacy/export, 201 assertions.
- Test Node et 43 lints PHP réussis.
- Refus d'un répertoire non vide reproduit.
- Deux générations isolées : six artefacts strictement identiques.
- Les JSON du manifeste et des artefacts sont valides.
- La référence déjà modifiée de R1 est rejetée avant construction et avant
  simulation ; aucun répertoire de sortie n'est créé.
- La validation complète accepte une référence sémantiquement identique dont
  les contres sont réordonnés.

SHA-256 :

- expérience source : `9E0B0B42071C5B4684E5ED596A470868AD2819E67670A486DB052179983C8835`
- objectifs : `F4399119A6A82ABFE7FD14965CFE125D5645FBBC2CF5311D3B78F86F76DB8C79`
- espace de recherche : `97421DEA05DB731FC1B7D1388FBB0714978E67CB77A1766F79D9B3225B94EA9E`
- candidat initial : `BA3F6E075E95A5636D6EE561E8A685745CA3E1A0942A3850CAD33B9C0E7057D3`
- validation : `5C7D1D6427225529A764BDD4343E9ED05AA66001B40009A1FF0B0A7B010F80AC`
- rapport Markdown : `03BEC455C41B7D0E0F92B5B3DA31E5F7B0B117DB03004D2BF0903C8C7CCA604E`

La contre-recette doit arrêter ou versionner ces bornes avant que T31 les
consomme. Aucun optimiseur, candidat exploratoire ou nouveau ruleset n'est livré
par T30.
