# Waar Micro Combat

## Scope

This is a pure PHP 8.2+ combat workbench for the four Waar units: soldier,
spearman, archer, and knight. It has no Symfony, Doctrine, database, Rust, or
automatic Arbestra inheritance.

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

## Verification

Run `composer validate --strict`, `composer install`, `composer test`,
`composer test:js`, and `composer smoke`. For a reference-dependent change, also
run `php bin/render-finalist-comparison.php`.

## Work units

Keep tasks and pull requests narrow. State the concrete problem, resulting
behavior, verification evidence, and known limits. Do not reinterpret gameplay
or research acceptance rules from an issue title alone.
