# Waar Micro Combat

## Scope

This is a PHP 8.2+ workbench for configuring and evaluating the selected Rust
cohort combat engine for the four Waar units: soldier, spearman, archer, and
knight. PHP owns validation, orchestration, experiments, and reports; Rust owns
combat resolution. It has no Symfony, Doctrine, database, or automatic Arbestra
inheritance.

## Engineering rules

- Keep gameplay rules in pure, testable services. Cover every gameplay change
  with tests. Do not change gameplay as part of repository maintenance.
- Keep visualization code descriptive. JavaScript and HTML must not resolve
  combat, infer objectives, select candidates, or approve a result.
- Preserve schema contracts, deterministic seeds, PO objectives, candidate
  order, sampling budgets, and frozen artifact hashes.
- A search may write a new report. It must never edit objectives or imply that a
  candidate was approved.
- Never hand-edit frozen files under `experiments/references/` to make tests pass.
- Keep experiments bounded. The routine smoke search is 8 candidates. T31's
  128-candidate search and full T33 validation are long, manual operations.

## Frontend iteration

- Do not rebuild container images for each frontend/CSS adjustment when the
  change can be validated locally or in a mounted development environment.
- Use a short edit/refresh/validate loop. Batch visual changes and perform a
  single final image rebuild after validation, only if deployment requires it.
- If a rebuild is unavoidable for validation, explain the concrete constraint
  before rebuilding and preserve Docker layer caching where possible.

## Verification

Run `composer validate --strict`, `composer install`, `composer test`,
`composer test:js`, and `composer smoke`. For a reference-dependent change, also
run `php bin/render-finalist-comparison.php`.

## Work units

Keep tasks and pull requests narrow. State the concrete problem, resulting
behavior, verification evidence, and known limits. Do not reinterpret gameplay
or research acceptance rules from an issue title alone.
