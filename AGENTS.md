# AGENTS.md

Instructions for coding agents working in this repository. DAN profiles the SQL that Shopware's DAL generates and how it executes across a `(scenario × dataset tier × database version)` grid. What DAN is, why it is designed this way, and how to use it: `README.md`. The phased plan and testing decision log: `ROADMAP.md`. Package-specific rules live in nested files: `bundle/AGENTS.md`, `lib/AGENTS.md`.

## Monorepo layout

Three Composer packages with separate vendor dirs, PHPStan configs, and test setups:

- **Root (`dan/harness`)** — `Dan\Harness\` in `src/`, the plain Symfony Console application (`bin/dan`) that orchestrates runs. PHP ≥ 8.4; test runner is Pest.
- **`lib/` (`dan/lib`)** — `Dan\Lib\` in `lib/src/`, framework-independent contracts and value objects shared by both runtime packages. PHP ≥ 8.2 (must load everywhere the probe does).
- **`bundle/` (`dan/probe`)** — `Dan\Probe\` in `bundle/src/`, a Symfony bundle installed *inside* each DAL runtime under test. PHP ≥ 8.2 (matches supported DAL versions); test runner stays plain PHPUnit.

The harness and probe communicate only via CLI: the harness builds a DAL runtime from a Shopware skeleton with the probe (Composer path repo), then invokes the probe's `dan:execute` / `dan:seed` commands and parses their JSON output.

**Dependency direction (inviolable):** `Dan\Harness\` never imports `Shopware\*`. `Dan\Probe\` imports only `Dan\Lib\` outside its own namespace. `Dan\Lib\` imports neither consumer. Enforced by phpat rules inside both PHPStan gates (`tests/Arch/DependencyDirectionRules.php`, `bundle/tests/Arch/DependencyDirectionRules.php`) — but write conforming code, don't lean on the gate.

Run artifacts: `runs/<session>/<slot>/manifest.json` + `cells/*.json` are the source of truth; `index.sqlite` is derived from them.

## Commands

Root harness:

```bash
composer check            # lint + phpstan + unit tests — the gate
composer lint             # php-cs-fixer --dry-run + phpcs
composer lint:fix         # phpcbf, then php-cs-fixer
composer phpstan          # level max + dead-code + deprecation rules
composer test             # pest, tests/ (includes the lib tests)
composer test:mutation    # infection over the deterministic core (needs pcov or xdebug)
vendor/bin/pest --filter SomeTest                    # single test class/method
vendor/bin/pest tests/Comparison/RunComparatorTest.php  # single file
```

Mutation testing note: Infection drives the suite through its PHPUnit adapter (Infection has no Pest adapter anymore), so every test that must kill mutants is written as a PHPUnit class — which Pest runs unmodified. Pest-DSL files are reserved for tests where mutation coverage is meaningless (e.g. architecture rules).

Bundle: see `bundle/AGENTS.md` (own `composer install`, own PHPStan/PHPUnit).

`tools/e2e-smoke.sh` runs the smallest full A/A session (needs Docker). It is a CI job, not a local gate.

## Definition of done

1. `composer check` passes at the root.
2. If anything under `bundle/` changed: the bundle's PHPStan passes, and the bundle kernel tests run when a database is available.
3. If seeder output changed in any way: `SnapshotCache::SEEDER_VERSION` is bumped. Stale snapshots silently poison every subsequent measurement.
4. New code follows every rule below — the custom tooling enforces most of them, but write conforming code the first time instead of lint-fixing afterwards.

Report validation results precisely: distinguish failures your change introduced from pre-existing ones, and say exactly which gates ran and which were skipped (e.g. database-dependent tests without `DATABASE_URL`).

## Domain modeling

This codebase is built around typed domain constructs. Plain strings, numbers, and arrays crossing a class or function boundary are a design smell.

- **Enums for closed value sets.** Values are meaningful domain words, never opaque letters (`RunSlot::Baseline`/`Candidate` with values `baseline`/`candidate` — not `a`/`b`). Schema versions are backed enums with a `getCurrent()` factory (`CellResultSchemaVersion`, `ScenarioResultSchemaVersion`).
- **Value objects for domain scalars.** A string that *is* something gets a type: `Path` (never string paths), `ScenarioName`, `Identity`. Time values go through `Dan\Lib\Time` — `Duration` and `Timestamp` track raw values internally and convert via explicit methods (`toMsFloat()`, `toSecondsFloat()`); no raw ns/ms arithmetic or unit division at call sites. Process timeouts, sleeps, and deadlines are `Duration`/`Timestamp`, not naked numbers.
- **Typed collections.** Extend `Dan\Lib\Collections\Collection` with PHPDoc generics (`SampleCollection extends Collection<Sample>`) instead of passing `list<X>` around. Collections own their conversion factories (`SampleCollection::fromArray(array<int|float>)`) and domain operations (`merge()`).
- **Convert to scalars only at explicit boundaries:** filesystem, container names, CLI, JSON serialization, SQL, array keys. When an array is unavoidable, give it the narrowest accurate `list<>`, `array<key, value>`, or array-shape type — never `mixed` or an unshaped array.
- **Use class constants over string literals** when the dependency provides them (e.g. `ProductDefinition::ENTITY_NAME`, never `'product'`). Association field names and SQL table names are different concepts and stay strings.

## Serialization boundaries

Artifacts (manifests, cell results, probe output) are a first-class versioned format. The rules:

- **No parser classes, no generic JSON accessors.** Construction from payloads lives in named factory methods *on the owning object*, composed along the object graph (aggregates delegate nested payloads to the nested object's factory).
- **Two-layer factory API:** `fromArray()` takes the exact documented payload shape as a PHPStan array-shape type (declared via `@phpstan-type XPayload`, imported with `@phpstan-import-type`) and stays free of `mixed`. `fromDecodedArray()` takes untrusted `json_decode()` output, runtime-validates it, and throws schema-specific exceptions with domain context before constructing typed objects.
- **`toArray()` returns the documented payload shape.** Round-trip tests prove `fromDecodedArray(toArray(x))` fidelity.
- **Schema versions:** one version enum per independently versioned payload — probe→harness protocol and stored artifacts are different schemas even when they look similar. Validate the version before the fields; an incompatible layout must fail explicitly, never be misinterpreted.
- **Pre-release policy:** we are pre-1.0 with zero users. No backward-compatibility shims, no migration code, no legacy field names kept "for compatibility". When domain vocabulary improves, the persisted format follows it, and schema versions stay at 1 until first release.

## Naming and namespaces

Names describe DAN's domain concepts, never implementation mechanics.

- **No generic buckets:** never `Model`, `Service`, `Util`, `Helper`, `ValueObject`, `Manager` (as a namespace), or a `Scenarios`-style plural under `Scenario`. Never invent concepts the domain doesn't have (there is no "app" anywhere in DAN — the thing the harness builds is a `Runtime`).
- **No namespace stutter:** `Gate\Policy`, not `Gate\GatePolicy`.
- **Namespaces are organized by responsibility, not left flat.** When a namespace mixes abstraction levels (domain values next to orchestration next to infrastructure), split it into sub-namespaces along those lines. Established structure: `Implementation\{Reference,Identity,Runtime}`, `Measurement\{Scheduling,Result,Execution}`, `RunStore\{Artifact,Filesystem,Index}`, `Probe\Execution\{Command,Measurement,Result}`, `Probe\Recorder\Dbal`, `Probe\Seeding\{Command,Dataset,Progress}`, `Probe\Scenario\Corpus`. Avoid one-class namespaces; a sub-namespace must carry a cohesive responsibility.
- **Established vocabulary — use it, don't reinvent it:**
  - *baseline / candidate* — the two comparison roles. Never `a`/`b` in identifiers or values; the persisted slot directories are `baseline/` and `candidate/` too.
  - *implementation* — the DAL implementation under measurement; referenced by a `Reference`, identified by a content-hash `Identity`, executed as a `Runtime`.
  - *comparison* vs *gate* — comparing two runs is `Comparison` (`RunComparator::compare()` → `RunComparison`/`CellComparison`); pass/fail policy is `Gate` (`Policy`, `Violation`, `ViolationKind`). `diff` survives only as the user-facing CLI command name.
  - *corpus* — the built-in scenario set (`Probe\Scenario\Corpus`).
  - *harness / probe / lib* — the three packages.
- **Renaming is a vocabulary decision, not a find-and-replace.** When naming is bad, map what the classes actually represent, then propose a coherent replacement set (with tradeoffs) covering classes, methods, properties, and persisted formats — and carry the rename through *all* of them, including CLI-internal names, cache paths, SQLite columns, and report headings.

## Class and service design

- **Console commands are thin adapters:** option parsing, context creation, and an obvious control loop — nothing else. Validation, staging, measurement, serialization, and progress reporting live in focused, separately testable services (`DatasetSeeder`, `ScenarioMeasurer`, `ScenarioResultWriter`). Progress/output crosses into services only through a typed reporter interface, never as a console dependency.
- **Builder pattern for assembly-heavy code.** Markdown reports go through `MarkdownBuilder` with one focused method per report section — no string-concatenation render blobs. External commands go through `DockerCommandBuilder`: argument vectors only, identifier validation built in, no shell interpretation, file redirection via process streams instead of `<`/`>`. Safety by construction, not by escaping.
- **No raw `Process` instantiation outside `Dan\Harness\Process`.** Everything runs through the `ProcessRunner` abstraction (`SymfonyProcessRunner` is the only place that touches Symfony Process), which also makes callers unit-testable without real processes.
- **Separate mutable accumulation from immutable results.** A measuring loop uses an accumulator (`StatementMeasurementAccumulator`); what leaves the loop is an immutable result object (`StatementResult`). Domain objects are `final`, constructor-promoted `public readonly`, and combine via pure methods (`merge()` returns `new self`).
- **Composition roots wire concretions.** Orchestration depends on interfaces where a second provider is plausible (`DatabaseManager`); the CLI command injects the concrete implementation (`DockerDatabaseManager`).

## Abstraction discipline

- **No speculative infrastructure.** Do not add attributes, interfaces, registries, or config for capabilities no current code needs — that includes "future-proofing" like version-gating scenarios before a scenario needs it. Introduce an interface when a second implementation is plausible and wanted, not by reflex; a self-contained helper can be a concrete class or a static method.
- **No half-implemented scaffolds.** A feature is either absent or finished end-to-end with tests proving the load-bearing claim. If you find a scaffold built on a false premise, say so and propose the two honest states (remove it, or finish it properly).
- **Let the framework do its job.** Never rebuild what Symfony/Shopware already provides (Shopware's `EntityCompilerPass` constructs entity repositories — DAN only defines entity shapes). Check what the installed framework version actually does before writing a compatibility layer.
- **Dead code gets deleted, not baselined.** PHPStan runs `shipmonk/dead-code-detector` in both packages. When it flags something: remove it if genuinely dead, or register the real dynamic entry point (service definitions, reflection) — never suppress to make the gate green.
- **No deprecated APIs.** PHPStan deprecation rules fail the build. When a dependency deprecates something (e.g. DBAL's `SQLLogger` → driver middleware), migrate to the supported mechanism.
- **No implicit system dependencies.** Anything DAN shells out to is an explicit, validated input with an actionable failure message — or better, eliminated (checkout identity is a content hash of `src/Core`, not a `git` invocation).

## Code style (enforced by tooling)

php-cs-fixer (PER-CS 2.0 + Symfony + project rules), phpcs (PSR-12 + custom sniffs), PHPStan level max. All configs are local to this repo and deliberately self-contained. The project-specific rules you must write to:

- `declare(strict_types=1);` in every file.
- **Named arguments** on every call into `Dan\*` code passing two or more arguments (custom PHPStan rule `dan.namedArguments`; vendor callees, single-argument calls, and variadics exempt).
- **Multi-entry arrays put every entry on its own line**, including nested arrays (custom sniff, auto-fixable). Single-entry arrays may stay inline.
- **No `static fn` and no inline `static function`** (custom sniff; named static methods are fine — `static_lambda` is disabled in php-cs-fixer so it can't reintroduce them).
- **Empty function bodies collapse to `{}`** on the signature line: `public function __construct(...) {}` (`single_line_empty_body`).
- Trailing commas in all multiline constructs; alphabetically ordered imports; global classes imported via `use` (`use RuntimeException;`); no yoda conditions; ` . ` concatenation with spaces.
- **Forbidden functions:** `var_dump`, `print_r` (leak into recorded artifacts), `sleep`, `usleep` (skew latency samples — use `Duration::sleep()`; the Docker readiness probe is the sole exclusion).

Extending the tooling: custom phpcs sniffs live in `dev/PhpCs/Sniffs/`, custom PHPStan rules in `dev/PhpStan/` — each with focused tests (accepted, rejected, and auto-fixed fixtures). When adding or changing a lint rule, migrate every existing violation in the same change so the baseline stays green, and check the other lint engine doesn't fight the new rule (disable the conflicting rule on the losing side).

## Testing model (four trust layers)

DAN's authority as a benchmark rests on four testable claims, each with its own layer:

1. **The numbers are computed correctly** — unit + property-style tests for the deterministic core (`tests/`): statistics, block-scheduler fairness, sample-merge order-independence, normalizer idempotence, store round-trips. Includes the A/A property at the unit level: identical runs must diff clean.
2. **What DAN records is what the DAL actually did** — kernel integration tests (`bundle/tests/`) boot a real Shopware against a real MySQL and assert recorder fidelity (exact statement sequence, params, positive durations) and seeder idempotence.
3. **The pipeline doesn't lie** — `tools/e2e-smoke.sh` asserts the promised artifacts exist after a real tiny session. Wired as a CI job.
4. **The instrument is calibrated** — `.github/workflows/calibration.yml`, nightly: A/A runs (measured false-positive record) and an injected known regression (measured false-negative check).

When changing measurement, diffing, or recording semantics, identify which layer proves the change and add the test at that layer.

Test-writing rules:

- **Never use factory methods to create the service under test in unit tests** — construct it directly with `new` and explicit dependencies.
- **Renderers get exact-output regression tests** (byte-for-byte expected strings), so formatting changes surface as reviewable diffs.
- **Test compiled behavior, not configuration text.** A service-wiring test that greps YAML proves nothing; kernel tests assert the container actually builds the service / the repository actually performs CRUD / the emitted SQL contains the expected shape (`JSON_EXTRACT`).
- Tests mirror source namespaces directory-for-directory and move together with the code they cover.

## Working practices

- **Diagnose before changing.** When asked *why* something is the way it is or *whether* it is necessary, investigate read-only and answer with findings, root cause, and a recommendation with tradeoffs — do not change code until asked. When asked to fix a named problem, fix it.
- **Refactors are behavior-preserving and scoped.** A namespace/structure refactor changes no behavior, no artifact schema, and no seeder output — and says so. Don't combine structural moves with domain renames unless a broader cleanup was requested.
- **Leave unrelated worktree changes untouched.** Concurrent edits by the user are common; build on them, never revert or "fix" them.
- **Sweep after renames:** no stale imports, references, docs, or terminology may survive (grep for the old names before finishing).
