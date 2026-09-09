# Extraction provenance

Extraction performed on 2026-09-09 from the local Waar v3 repository at source
commit `98ac1186f3c9c82bac63f73d87b791bfa2a6a850`.

## History and local work

The source was cloned with `--no-local --single-branch --no-tags`. A positive
history filter retained `packages/waar-micro-combat/`, tracked
`docs/waar-micro-combat-*.md`, and tracked objectives, then moved the package to
the repository root and objectives to `experiments/objectives/`. Only one source
commit remained non-empty:

| Source commit | Filtered commit | Subject |
| --- | --- | --- |
| `98ac1186f3c9c82bac63f73d87b791bfa2a6a850` | `3018ee1dc184db2cac5c2b8955e2818c4617dc97` | Add micro-combat workbench with canonical objectives and defender tie-breaks |

Uncommitted and untracked package work through T34 was copied from the source
working tree and committed as
`435a80f` (`Import current T29-T34 micro-combat workbench`). No synthetic T29-T34 historical
commits were created. The extraction orchestration plan itself was excluded.

## Frozen inputs and results

Files were copied byte-for-byte from these source paths:

| Source | Destination | Role |
| --- | --- | --- |
| `var/waar-micro-combat/t25a1/` | `experiments/references/t25a1/` | Legacy overlay input |
| `var/waar-micro-combat/t28-defender-tie-break/` | `experiments/references/t28-defender-tie-break/` | Initial T32 result |
| `var/waar-micro-combat/t31-standard-seed-314159/` | `experiments/references/t31-standard-seed-314159/` | Search result and finalists used by T32/T33 |
| `var/waar-micro-combat/t33-finalist-stability/` | `experiments/references/t33-finalist-stability/` | Stability result and T34 input |
| `var/waar-micro-combat/t34-mixed-composition-observation/` | `experiments/references/t34-mixed-composition-observation/` | Reproducible mixed-composition archive |

`experiments/references/manifest.sha256` records every reference file. Git
attributes disable text conversion for references, objectives, and the vendored
ECharts files. Historical internal paths remain metadata and are not executable
dependencies.

## Validation performed

- Composer 2.10.3 resolved and installed the standalone lockfile on PHP 8.2.33.
- `composer validate --strict` passed.
- PHPUnit 11.5.56 passed 70 tests with 10,408 assertions.
- All four Node presentation-model tests passed on Node 24.19.0.
- The T24 smoke report and reference-backed T32 report were generated offline.

The extraction did not rerun the long T31 or full T33 calibration and did not
change gameplay rules. Linux and PHP 8.4 verification are delegated to the
published GitHub Actions matrix. Review of the source working tree was limited
to the documented positive paths and targeted secret/path checks.
