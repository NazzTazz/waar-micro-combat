# Premier candidat analytique C1

Point de départ mesuré, pas un équilibrage validé ni un candidat approuvé.

## Utilisation

1. Importer `modele-cohortes-c1.json` dans le profil de la soufflerie.
2. Mesurer les monotypes avec météo neutre, seed 42 et 100 combats par paire.
3. Dans l'éditeur des zones, importer `modele-cohortes-c1-ellipses-t27.json`.

Le fichier `modele-cohortes-c1-objectifs-api.json` est destiné à l'API, pas au bouton d'import de l'éditeur. Les mesures complètes sont dans `modele-cohortes-c1-mesures.json`. Les zones sont des brouillons à examiner, jamais des objectifs implicitement approuvés.

## Construction

Coûts conservés : soldat 10, lancier 70, archer 70, chevalier 550. Dix rounds, reddition désactivée. Le départage par structure est un choix de travail explicite, permettant une décision avec peu de morts. Les précisions faibles des spécialistes viennent du modèle de premières touches ; les relations sont un premier réglage analytique, non une solution optimisée.

L'axe vertical mesure les blessés + morts avant compression, rapportés à l'effectif initial. Pour un monotype, ce ratio est aussi la fraction de coût touchée. Les prisonniers ne sont pas ajoutés. Les mesures sont moyennées sur tous les combats, et non conditionnées au statut de vainqueur.

## Résultat reproductible

1600 combats, soit 100 par confrontation : **15 points sur 32 dans les ellipses**. Ce comptage géométrique n'est pas une approbation.

| Miroir | Victoires attaquant | Pertes attaquant / défenseur |
| --- | ---: | ---: |
| Soldats | 48 % | 99,05 % / 99,02 % |
| Lanciers | 9 % | 10,02 % / 10,00 % |
| Archers | 77 % | 10,00 % / 9,92 % |
| Chevaliers | 41 % | 10,16 % / 9,97 % |

Les chevaliers attaquants battent encore les lanciers défenseurs dans 95 % des combats : contradiction importante avec la cible. Contre les archers, les chevaliers gagnent mais subissent environ 57 % de pertes contre 1,3 % : la hiérarchie financière souhaitée est inversée. Plusieurs autres avantages sont trop forts ou insuffisants. Les miroirs chevaliers sortent de la fenêtre 45–55 % sur cet échantillon.

## Portée de la carte

Les avantages légers à nets sont représentés par une plage de victoires 60–80 %. Pour les dominations fortes, 90–100 % est une convention de travail. Le miroir archers est adouci vers 10 % de pertes des deux côtés. Le miroir soldats vise près de 100 % de pertes.

Lorsque les pertes ne sont pas chiffrées, le rayon vertical est large (1, centre 0,5). Cela ne supprime pas mathématiquement la contrainte verticale : une ellipse couple ses deux axes, notamment aux extrémités horizontales.

Les comparaisons de pertes entre camps, les pertes conditionnées à la victoire, le gain marginal des prisonniers et l'intérêt du mélange soldats-chevaliers ne sont pas validés par ces ellipses indépendantes. Ils restent à contrôler séparément. Aucun changement du moteur n'a été nécessaire pour ce candidat.

Pour reproduire mesures, objectifs et bilan sur la sortie standard : `php profiles/mesurer-modele-c1.php`.
