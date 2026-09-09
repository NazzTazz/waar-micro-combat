# Relais T25A1 — référence Legacy comparable

8 septembre 2026 — tranche terminée, prête pour une revue légère d'Astra.

## Artefacts

- Référence rejouable : `var/waar-micro-combat/t25a1/legacy-reference.json`
- Schéma versionné : `packages/waar-micro-combat/schema/legacy-reference.schema.json`
- Exporteur : `tools/export-waar-micro-legacy-reference.php`
- Mesure : `src/Game/Combat/Research/LegacyReferenceExporter.php` et `LegacyOperationalProjection.php`
- Validation des invariants : `src/Game/Combat/Research/LegacyReferenceArtifactValidator.php`
- Tests : `tests/Game/Combat/Research/LegacyReferenceExporterTest.php`

Rejeu depuis la racine :

```powershell
php tools/export-waar-micro-legacy-reference.php
```

SHA-256 reproductible de l'artefact :
`BCCE3DDD65218BD3F752148F864D9CF00AE7E45AF3BAEEB58BE8ED25C459A494`.

Empreinte des sources de mesure :
`221AE93E8488FD3DFACB6B3D010AA0304E881EBA0F0372BBDD62002FA5BFD6F4`.

Empreinte du corpus et de son contexte :
`F9285586B585C9DB9E4D88CB3756885A7AF9E12FBA0A072C8033E675F4474CE4`.

## Paramètres figés et mesure obtenue

Aucun paramètre de gameplay n'a été exploré. Les six compositions, le témoin et
`roles-a` restent ceux de T24. L'oracle est
`waar-v2-legacy-infirmary-5`, avec 200 répétitions, seed de base 42 et dérivation
`sha256-scenario-iteration-first31-v1`. Le barème commun est
`t24-common-valuation-v1` : Soldat 80, Lancier 110, Archer 130, Chevalier 350.

| Scénario | Camp prévu T24 | X Legacy | Effectifs opérationnels | Valeur opérationnelle commune |
|---|---:|---:|---:|---:|
| Soldats seuls | Attaquant | 0 % | 98,50 % | 98,50 % |
| Écran de Lanciers | Défenseur | 0 % | 96,29 % | 96,02 % |
| Lanciers contre Chevaliers | Défenseur | 0 % | 96,78 % | 96,89 % |
| Archers contre Lanciers | Attaquant | 100 % | 97,15 % | 97,38 % |
| Chevaliers contre Archers | Attaquant | 100 % | 94,98 % | 95,08 % |
| Armées mixtes | Attaquant | 100 % | 94,91 % | 93,15 % |

Le X reste exactement 0 ou 1 : les répétitions ne font varier que l'arrondi des
pertes. L'artefact déclare explicitement qu'aucun appariement statistique entre
les RNG Legacy et micro n'est revendiqué.

## Écarts et limites persistants

- La structure micro n'a toujours pas d'équivalent Legacy démontré ; sa coordonnée Legacy reste `null`.
- Les effectifs opérationnels Legacy utilisent exclusivement `valid`. Les blessés hospitalisés ne sont pas comptés comme opérationnels.
- Les tests couvrent 100 Soldats avec des lits disponibles et 8 000 Soldats avec dépassement des 500 lits : changer la capacité déplace blessés et morts, sans changer la projection opérationnelle.
- Cette référence n'est ni une zone acceptée ni une cible d'optimisation. Le PO n'a encore validé aucune tolérance.
- Le graphe ECharts et les ellipses proposées en lecture seule appartiennent à T25A2. Aucun changement d'interface n'est inclus ici.
- Correction T25A1c : `context.modifiers.attacker` et `defender` sont bien encodés comme objets JSON vides `{}`, conformément au schéma. Le validateur filaire et un test dédié rejettent désormais `[]`.

Vérifications : 5 tests ciblés, 165 assertions ; 13 tests Legacy et export, 201
assertions ; le validateur rejette une projection qui inclurait artificiellement
un hospitalisé dans les opérationnels. Deux exports consécutifs ont le même hash.
