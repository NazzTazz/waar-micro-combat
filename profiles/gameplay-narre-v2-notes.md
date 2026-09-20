# Candidat v2 : coûts legacy

Importer `gameplay-narre-v2-legacy.json`, pas le fichier de mesures.
Le candidat v1 reste disponible. Aucun moteur, objectif enregistré ni fichier
de référence n'est modifié. Ce candidat n'est pas approuvé automatiquement.

Coûts soldat / lancier / archer / chevalier : 10 / 70 / 70 / 550.
Les contraintes sans rôle précisé sont testées dans les deux sens.

## Mesure reproductible

`php profiles/measure-gameplay.php`

Rust natif, météo neutre, sans effets acquis, seed de base 42, 100 répétitions
pour chacune des 16 paires, budget par camp de 400 400, trois rounds maximum.
Effectifs exacts : 40 040 soldats, 5 720 lanciers, 5 720 archers ou 728 chevaliers.
Les résultats complets et l'empreinte du profil sont dans
`gameplay-narre-v2-measurements.json`.

- Archers contre soldats : archers vainqueurs 100/100 dans chaque sens.
- Soldats contre chevaliers : soldats vainqueurs 100/100 dans chaque sens.
- Chevaliers attaquant des lanciers : lanciers vainqueurs 100/100.
- Lanciers attaquant des chevaliers : chevaliers vainqueurs 100/100 ;
  100 % de morts bruts chez les lanciers, aucun mort brut chez les chevaliers.

Ces observations ne garantissent pas tous les seeds ou tous les budgets.

## Choix et limites

- Précision soldat 10 %, lancier 80 %, archer 80 %, chevalier 60 %.
  L'amplitude est nulle mais les touches restent aléatoires.
- Quatre flèches et cinq frappes conservées ; dégâts par touche inchangés
  sauf le facteur lancier vers chevalier, passé de 3 à 0,3.
- Coefficient défensif lancier 10, chevalier 5 : il multiplie les dégâts
  contre TOUTES les cibles, pas uniquement leur contre.
- Le chevalier attaquant doit toujours toucher cinq fois le lancier ;
  le chevalier défenseur le tue désormais en une touche.
- Le lancier attaque le chevalier pour 3 dégâts par touche, contre 30 en
  défense : 20 touches contre 2 pour épuiser ses 60 points de structure.
- Le lancier et le soldat ont la même attaque nominale, mais pas la même
  précision : leur rendement offensif individuel n'est donc pas similaire.
- L'archer tue toujours le soldat et l'archer en une touche ; le soldat tue
  le lancier en trois touches et l'archer en deux touches.
- La compression des pertes reste à 8 %. Une destruction brute complète
  devient environ 8 % de morts appliqués, pas une disparition de l'armée.
- Les miroirs archers et chevaliers produisent une destruction brute mutuelle
  dans cette mesure ; le départage attribue alors la victoire au défenseur.
  Cela constitue une limite d'équilibrage importante, pas une victoire intacte.
- Seuls les soldats blessés vaincus sont capturables, à 10 % avant compression.
  La capture n'est donc pas garantie comme principal risque du soldat.
- Toutes les météos restent neutres. Tampon, compositions mixtes, petits
  budgets et objectifs historiques ne sont pas validés par cette mesure.
