# T34 — observation des compositions mixtes T24

Plan `t34-mixed-composition-observation` : 1000 répétitions par scénario et variante, seed réservée `32452843`, 24000 combats réels.

Initial `roles-a` — statut T33 `objectifs-non-atteints`.

| Ordre T31 | Finaliste | Statut T33 | Renversements de majorité T24 |
|---:|---|---|---:|
| 1 | `t31-candidate-0116-d0c5f6473d05` | `objectifs-non-atteints` | 3 |
| 2 | `t31-candidate-0128-96382d7e8496` | `objectifs-non-atteints` | 4 |
| 3 | `t31-candidate-0123-3805dc464a60` | `objectifs-non-atteints` | 3 |

## Renversements de majorité observés

- `t31-candidate-0116-d0c5f6473d05`, Écran de Lanciers en défense : `defender` → `attacker`.
- `t31-candidate-0116-d0c5f6473d05`, Lanciers contre Chevaliers : `defender` → `attacker`.
- `t31-candidate-0116-d0c5f6473d05`, Archers contre Lanciers : `defender` → `attacker`.
- `t31-candidate-0128-96382d7e8496`, Soldats seuls — témoin de stabilité : `defender` → `attacker`.
- `t31-candidate-0128-96382d7e8496`, Écran de Lanciers en défense : `defender` → `attacker`.
- `t31-candidate-0128-96382d7e8496`, Lanciers contre Chevaliers : `defender` → `attacker`.
- `t31-candidate-0128-96382d7e8496`, Archers contre Lanciers : `defender` → `attacker`.
- `t31-candidate-0123-3805dc464a60`, Écran de Lanciers en défense : `defender` → `attacker`.
- `t31-candidate-0123-3805dc464a60`, Lanciers contre Chevaliers : `defender` → `attacker`.
- `t31-candidate-0123-3805dc464a60`, Archers contre Lanciers : `defender` → `attacker`.

## Plus grands écarts absolus

| Mesure | Finaliste | Scénario | Camp | Initial | Finaliste | Delta |
|---|---|---|---|---:|---:|---:|
| `winRate` | `t31-candidate-0116-d0c5f6473d05` | Lanciers contre Chevaliers | `attacker` | 0.225000 | 1.000000 | +0.775000 |
| `survivors` | `t31-candidate-0116-d0c5f6473d05` | Archers contre Lanciers | `attacker` | 0.043176 | 0.745735 | +0.702559 |
| `structure` | `t31-candidate-0116-d0c5f6473d05` | Archers contre Lanciers | `attacker` | 0.042638 | 0.718268 | +0.675630 |
| `economicValue` | `t31-candidate-0116-d0c5f6473d05` | Archers contre Lanciers | `attacker` | 0.033348 | 0.735366 | +0.702018 |
| `meanRounds` | `t31-candidate-0116-d0c5f6473d05` | Archers contre Lanciers | `attacker` | 3.000000 | 1.677000 | -1.323000 |

> Lecture descriptive : aucun score d’acceptation T24, aucun verdict de fidélité Legacy et aucune nouvelle sélection. Les statuts T33 restent applicables, y compris l’échec des objectifs monotypes.

Référence T24 historique : 200 répétitions, seed `42`, départage `draw` implicite. Ses mesures ne sont pas affichées et les différences de départage ne sont pas attribuées aux paramètres.
