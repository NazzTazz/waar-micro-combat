# Relais T32 — comparaison visuelle des finalistes

9 septembre 2026 — tranche validée par Tristan ; relais historique conservé.

## Décision d'entrée

Tristan accepte T31 sans réserve et autorise T32. Les finalistes, leur ordre et
leurs mesures restent ceux du run standard T31. T32 ne lance aucune simulation,
ne reclasse aucun candidat et ne modifie aucun objectif. T33 a depuis été livrée
séparément, sans modifier ces artefacts.

## Génération et rapport à ouvrir

Depuis la racine du dépôt, vers une sortie absente ou vide :

```powershell
php packages/waar-micro-combat/bin/render-finalist-comparison.php var/waar-micro-combat/t31-standard-seed-314159 var/waar-micro-combat/t28-defender-tie-break/micro-report.json var/waar-micro-combat/t32-finalist-comparison
```

Ouvrir exactement :

`C:\Users\trist\PhpstormProjects\waar-v3\var\waar-micro-combat\t32-finalist-comparison\report.html`

Le fichier est autonome et ouvrable localement. Il embarque ECharts 5.6.0,
le modèle de présentation, l'application et les données ; aucun CDN ni runtime
Symfony n'est requis.

Le second argument est le rapport micro T28 autoritaire du candidat initial
`roles-a`. T31 contient son évaluation canonique, mais pas son rapport micro à
trois axes. Le builder exige que l'identité du candidat et de l'expérience
corresponde à T31 avant de présenter cette source.

## Parcours manuel court

1. Vérifier l'état « Recherche achevée — aucun candidat strict dans ce budget »
   et la comparaison initiale avec le finaliste de rang 1.
2. Choisir `Lancier attaque Chevalier`, puis passer au finaliste de rang 2.
   Les deux camps restent distingués par cercle turquoise et losange corail.
3. Passer de `Effectifs survivants` à `Valeur économique restante` : les mêmes
   deux objectifs PO restent visibles. Passer ensuite à `Structure restante` :
   aucune ellipse cible n'est affichée et le tableau indique le diagnostic.
4. Cocher le témoin neutre T28. Il apparaît comme comparateur distinct, sans
   remplacer l'initial ni les flèches « Initial → candidat ».
5. Dans la vue globale, activer l'objectif 16, le pire objectif du run. La
   confrontation correspondante s'ouvre directement.
6. Choisir le finaliste de rang 3 et télécharger sa variante, puis son
   évaluation. Dans les deux JSON, vérifier le candidat
   `t31-candidate-0123-3805dc464a60`, le rang `3` et le plan
   `t31-standard-seed-314159-budget-128`.

Tous les contrôles sont utilisables au clavier. Les lignes du tableau sont
focusables et exposent les valeurs exactes, le nombre de simulations, l'état,
l'excès et la contribution à la perte.

## Résultat présenté

Le rapport conserve l'initial à `9,739174155519`, puis les trois finalistes T31
dans leur ordre contractuel :

| Rang | Candidat | Perte | Objectifs | Nuls |
|---:|---|---:|---:|---:|
| 1 | `t31-candidate-0116-d0c5f6473d05` | 6,173725600720 | 0/32 | 0 |
| 2 | `t31-candidate-0128-96382d7e8496` | 6,250069488043 | 0/32 | 0 |
| 3 | `t31-candidate-0123-3805dc464a60` | 6,284652072411 | 0/32 | 0 |

La recherche est achevée, mais aucun candidat strict n'a été trouvé dans ce
budget. Le rapport utilise le terme `exploratoire` et ne transforme jamais la
perte moyenne en verdict d'acceptation.

## Contrat de lecture

- Les SHA du candidat initial, des objectifs et de chaque triplet finaliste
  variante/évaluation/rapport micro sont vérifiés avant génération.
- Chaque évaluation doit comporter 32 contributions uniques et chaque rapport
  16 confrontations avec deux camps.
- L'économique réutilise les cibles, états, excès et contributions survivants
  uniquement après vérification de l'égalité monotype des observations.
- La structure transporte ses observations mais aucune cible.
- Le navigateur reçoit `simulationAllowed = false`,
  `objectiveInferenceAllowed = false` et les états déjà calculés.
- Une exécution interrompue ou incomplète reçoit le bandeau `Run partiel` avec
  ses compteurs ; elle ne peut pas ressembler à une recherche achevée.
- Pour modifier le design PO, utiliser l'éditeur existant, exporter un nouveau
  document et lancer une expérience distincte.

## Recette automatisée et navigateur

- 57 tests PHP, 9 717 assertions : construction réelle, immutabilité des
  entrées, SHA, alias économique, structure sans cible, un seul finaliste, run
  partiel, renderer autonome et exports avec provenance.
- Modèle JavaScript : points initial/candidat superposés, 32/32, 0/32,
  changement de candidat/scénario/Y, absence de mutation et d'objectif dupliqué.
- Chrome réel sur le rapport officiel : bureau 1 440 × 1 000 et mobile
  390 × 844 ; largeur de document inférieure au viewport, aucune erreur console,
  ECharts 5.6.0, 32 objectifs et zéro simulation après interactions.
- Navigation clavier vérifiée sur les sélecteurs. Économique : deux ellipses ;
  structure : zéro ellipse et six lignes de diagnostic avec le témoin actif.
- Exports Chrome du rang 3 relus : l'artefact et la provenance portent le bon
  candidat, le bon rang, le bon plan et les SHA attendus.
- Audit axe-core : 0 violation, 0 résultat incomplet, 40 règles réussies.

Captures :

- `var/waar-micro-combat/t32-finalist-comparison/screenshots/desktop.png`
- `var/waar-micro-combat/t32-finalist-comparison/screenshots/mobile-390.png`

## Artefacts et empreintes

| Artefact | SHA-256 |
|---|---|
| `report.html` | `167F1512B60535B60F059F23B2D7A0348D5B251D13CEE14671D1C85DE1CA1DDB` |
| `presentation.json` | `17C89014E49D78B7ACC326BCC26BC9048EBF64D4FE1FBED220CF2E8EA549679B` |
| `report.md` | `7A47ADEEC077967BE15F1CE90D9568882336CF387CFC86AAAA9652BDB7ADF7B4` |
| `screenshots/desktop.png` | `72E5F20ADFE768E53B452386A37FB506653EED640E4B805FA650DEA0D7CED500` |
| `screenshots/mobile-390.png` | `326F1D9B22249A52811306F997DF4D113478FBE8BE504FBEDCA672A3375B6D88` |

Les captures sont des preuves de recette et dépendent du rasteriseur Chrome.
Les trois premiers fichiers constituent la sortie déterministe de génération.

## Limite et suite

T32 rend les mesures T31 inspectables ; elle ne mesure pas leur stabilité. La
mesure réservée est désormais livrée dans `docs/waar-micro-combat-t33-relay.md`.
