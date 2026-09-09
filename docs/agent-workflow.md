# Issue to reviewed change

1. Define one bounded issue with objective, allowed scope, acceptance criteria,
   deterministic seeds, and an explicit experiment budget.
2. Give the issue to an agent together with `AGENTS.md`. The agent changes only
   that scope and never edits frozen references to satisfy a test.
3. Open a pull request describing the problem, resulting behavior, evidence, and
   limits. Generated candidates remain unapproved observations.
4. Let CI validate Composer, PHP 8.2/8.4, PHP and JavaScript tests, and a short
   offline report.
5. Review gameplay semantics, artifact provenance, and budget compliance before
   merging. Model-calling automation requires a separately chosen provider,
   secrets policy, and spending limit; none is enabled here.
