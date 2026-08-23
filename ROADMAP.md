# DAN implementation roadmap

Working plan, ordered by dependency. Each item has a **done-when** so progress is checkable. Update this file as phases land; design rationale lives in `README.md`, decisions summarized at the bottom.

## Phase 0 — Toolchain split (unblocks everything below)

- [ ] **Split PHP floors.** Harness `composer.json` moves to `php: >=8.4` (QA tooling included); probe keeps its low floor (matches supported DAL versions; only the probe runs inside DAL runtimes). Local dev + CI use PHP 8.4 for the harness, 8.2 for the probe.
  *Done when:* `composer install` + full QA pass on 8.4 at the root, bundle suite still runs on 8.2.
- [ ] **Adopt Pest v5 as the harness test runner.** Existing PHPUnit-class tests run unmodified under Pest; new tests use Pest style. Probe kernel tests stay plain PHPUnit (Shopware `TestBootstrapper` world).
  *Done when:* `vendor/bin/pest` runs the whole harness suite green; `composer test` points at Pest.
- [ ] **Wire Infection against the Pest suite.** Mutation scope = deterministic core only (`Comparison`, `Measurement`, `Protocol`, `RunStore`); boundary classes (`Database`, `Implementation\Runtime\Runtime`, `Implementation\Runtime\RuntimeFactory`, `Measurement\Execution\GridCellMeasurer`) excluded.
  *Done when:* `infection.json5` exists, a full run completes locally in minutes, baseline covered-MSI recorded.
- [ ] **Update CI:** quality job on PHP 8.4 (Pest + Infection gate `min-covered-msi` ≥ 85, ratchet upward later), bundle job stays on 8.2, nightly full-`src/` mutation report job.
  *Done when:* both jobs green on a PR; a deliberately weakened test fails the mutation gate.

## Phase 1 — Test quality (the trust core)

- [ ] **Eris property-based tests** replacing the hand-picked invariant sweeps: normalizer idempotence over *generated* SQL, scheduler fairness/mirroring over random `(impls × iterations × blocks)`, merge commutativity on sample multisets, artifact round-trips (`Protocol::fromArray(Protocol::toArray(x)) == x`; `fromDecodedArray(toArray(x)) == x` for `RunManifest` and `CellResult`), statistics bounds/monotonicity. Random seeds in CI (low iterations on PRs, high nightly); failure output must include the reproducing seed.
  *Done when:* old `InvariantsTest` sweeps are replaced, properties shrink failures to minimal counterexamples.
- [ ] **Regex fuzz-lite:** Eris adversarial-SQL generator (deep placeholder nesting, huge IN-lists, pathological whitespace) with a wall-time budget assertion on `SqlNormalizer::normalize()` (ReDoS guard). Coverage-guided `nikic/php-fuzzer` stays an optional nightly upgrade, not scheduled.
  *Done when:* the property exists and a deliberately catastrophic regex variant fails it.
- [ ] **Architecture tests** (Pest `arch()`): `Dan\Harness\` never depends on `Shopware\*`; `Dan\Probe\` only depends on `Dan\Lib\` outside its own namespace; `Dan\Lib\` depends on neither consumer; only `Dan\Harness\Database` and `Dan\Harness\Implementation\Runtime` may use `Symfony\Component\Process`.
  *Done when:* rules pass, and adding a forbidden import fails the suite.
- [ ] **Renderer snapshot tests:** golden-file snapshots for `MarkdownReportRenderer` over fixture `RunComparison`s (clean A/A, SQL-change, regression-with-violations, protocol-mismatch cases).
  *Done when:* report formatting changes show up as reviewable snapshot diffs.
- [ ] **Cross-package contract fixture** for the `dan:execute` JSON: one golden file; probe test asserts `ExecuteScenariosCommand` output matches it, harness test asserts `CellResult::fromDecodedScenarioArray` parses it.
  *Done when:* changing either side without the fixture breaks a test in that package.

Deliberately skipped: interfaces/fakes for boundary classes (`DockerDatabaseManager`, `RuntimeFactory`, `Runtime`) — e2e carries them until a second implementation exists.

## Phase 2 — Pipeline hardening (make the e2e path real)

- [ ] **Runtime factory hardening** against real 6.5 / 6.6 / 6.7 skeletons: `create-project` shape, path-repo wiring to `<checkout>/src/Core`, bundle registration, `system:install` flow. The least-proven component; expect iteration.
  *Done when:* `tools/e2e-smoke.sh` passes locally against a released version AND a local checkout.
- [ ] **E2E smoke in CI** (`ci.yml` job) actually green.
- [ ] **Calibration workflows live:** nightly A/A run producing artifact history; derive the real gate threshold from measured A/A variance (replace the guessed 15% default).
- [ ] **First pessimization patch** in `calibration/pessimizations/` (must change query generation, never add delay); injected-regression job flips from skip to enforcing.
  *Done when:* the job fails if DAN misses the known regression.
- [ ] **Snapshot-cache pressure check:** measure M/L-tier dump sizes vs GitHub Actions cache limits; decide on external storage if needed.

## Phase 3 — Correctness + workflow features

- [ ] **Result-set equivalence in A/B runs:** each scenario records returned entity ids; the comparator flags any A/B result divergence as a first-class (gateable) violation. Closes the correctness blind spot — a DAL change returning different rows is worse than a slow one.
- [ ] **Scenario auto-discovery:** DAN loads PHP scenario files from `.dan/scenarios/` inside any DAL checkout it's pointed at, plus repeatable `--scenario-dir`. Artifacts and reports tag scenario origin (`corpus` / `checkout` / `ad-hoc`); origin-mismatched cells never silently merge into corpus continuity.
  *Enables:* a DAL-fix PR carries its own pathological scenario in the same diff — zero CI plumbing, upstream or fork.

## Phase 4 — Corpus & dataset breadth

- [x] Synthetic-entity foundation: let Shopware compile the generated repository, install the probe-owned schema, seed typed JSON payloads, and measure JSON-path Criteria.
- [ ] Additional synthetic shapes: inheritance, translations, deep to-many chains and heavy many-to-many.
- [ ] Seeder graph beyond products/categories: orders with line items + deliveries and customers. Bump `SnapshotCache::SEEDER_VERSION` with every seeder-output change.
- [ ] Corpus breadth: order/customer scenarios and aggregation-heavy scenarios.

## Phase 5 — Exploration & reporting

- [ ] **`dan explore` — differential criteria fuzzing:** generate random valid Criteria over registered definitions, execute on both DALs against the identical dataset, report SQL-shape and result-set divergences, shrink findings to minimal criteria, freeze them into the corpus as regression scenarios. The highest-leverage feature on this roadmap.
- [ ] HTML report for local runs; GUI reading `index.sqlite`.
- [ ] Optional: coverage-guided php-fuzzer nightly for normalizer/`Json`.

## Decision log (testing)

| Decision | Choice |
|---|---|
| PHP floors | Split: harness ≥ 8.4, probe stays low (only probe runs inside DAL runtimes) |
| Framework | Pest v5 runner/DSL + Infection mutation engine (hybrid); bundle kernel tests stay PHPUnit |
| Mutation policy | Core-namespaces scope, PR-gated on `min-covered-msi` (~85%, ratchet), nightly full report |
| Property-based | Eris, truly randomized in CI, seed printed on failure; PR iterations low, nightly high |
| Fuzzing | Eris adversarial generators + time budget for regexes now; php-fuzzer optional later; differential criteria fuzzing is a product feature (`dan explore`) |
| Correctness | Result-set equivalence asserted in every A/B run (Phase 3) |
| Ad-hoc scenarios | PHP files only; auto-discovered from `.dan/scenarios/` in checkouts + `--scenario-dir`; origin-tagged |
| Guardrails | Arch tests, renderer snapshots, execute-JSON contract fixture: yes. Boundary seams/fakes: deliberately skipped |

Earlier design decisions (scope, live-per-cell topology, corpus format, seeding, noise control, CI shape, artifacts) are recorded in `README.md`.
