# AGENTS.md — dan/probe (the bundle)

Rules specific to `bundle/` (`Dan\Probe\`), on top of the root `AGENTS.md`. The probe is a Symfony bundle installed *inside every DAL runtime under test* — that placement drives every constraint here.

## Toolchain

Separate Composer package: run `composer install` here first. Then, from `bundle/`:

```bash
vendor/bin/phpstan analyse    # level max, analyzed against a real shopware/core dev dependency
DATABASE_URL=mysql://root:dan@127.0.0.1:3306/dan_test vendor/bin/phpunit
# kernel integration tests self-skip when DATABASE_URL is unset
```

## Constraints

- **Low PHP floor (currently 8.2), regardless of the harness's.** The probe runs inside runtimes for every supported DAL version (`shopware/core >=6.5`, no upper bound). No newer PHP syntax in `bundle/src/`, and no API used may be unavailable anywhere in that Shopware range — verify against the range, not just the installed dev dependency.
- **Corpus scenarios use only the public Criteria API** — no Shopware internals. Scenarios live in `Scenario\Corpus`, are tagged `dan.scenario`, and declare their entity via the definition's `ENTITY_NAME` constant.
- **Shopware constructs the repositories; DAN only defines entity shapes.** Synthetic entity definitions are autoconfigured; Shopware's `EntityCompilerPass` generates `<entity>.repository` with the correct constructor for that runtime. Never hand-build an `EntityRepository` or add a version-tolerant repository factory. Schema DDL lives in a dedicated installer (`SyntheticSchemaInstaller`), never as a side effect of seeding.
- **Seeding is deterministic and idempotent:** deterministic ids (`DeterministicId`), fixed data, upserts. Any change that alters seeder output — payloads, ordering, chunking, row counts — requires bumping `SnapshotCache::SEEDER_VERSION` in the harness. Behavior-preserving refactors must state that no bump is needed and why.
- **Recording rides the kernel connection.** The recording middleware is injected where Shopware injects its own profiler middleware: `bin/dan-console` (staged by the harness) builds the connection via `MySQLFactory::create([...])` and passes it to `KernelFactory::create()`, so the measured connection *is* the system connection — one session, kernel-applied session variables, nothing mirrored. `RecordingBootstrap` hands the shared `QueryRecorder` across the pre-container boundary. Never build a second connection for recording.
- **Recording is explicitly scoped.** The DBAL middleware recorder captures only scenario execution; boot and seeding queries must never contaminate samples.
- **Probe→harness output is a versioned JSON protocol** (`ScenarioResultSchemaVersion` in `dan/lib`). It is a different schema from the harness's stored cell artifacts, even where fields overlap.

## Service configuration

`src/Resources/config/services.yaml` with `autowire: true` and `autoconfigure: true` as defaults. Keep the file minimal: only DAN-specific tags (`dan.scenario`), the tagged iterator for `ScenarioRegistry`, and the `QueryRecorder` factory are explicit. Commands rely on constructor autowiring plus `#[AsCommand]` — no manual argument lists, no `console.command` tags. No XML anywhere (deprecated in Symfony and Shopware).

## Tests

`bundle/tests/` is trust layer 2: kernel tests boot a real Shopware against a real MySQL and prove recorder fidelity, seeder idempotence, and synthetic-entity behavior (repository CRUD, emitted SQL shape). Pure logic (accumulators, result objects, id derivation) gets plain unit tests that run without a database.
