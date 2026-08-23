# AGENTS.md — dan/lib

Rules specific to `lib/` (`Dan\Lib\`), on top of the root `AGENTS.md`.

- **Framework-free, dependency-free** (`php >=8.2` only). It must load inside both the harness and every DAL runtime the probe runs in.
- **Only genuinely shared code belongs here.** A class qualifies when both runtime packages use it at runtime — "one package plus the other package's tests" does not count; move such a class into its real owner. Current residents: the protocol vocabulary (`Tier`, `ScenarioName`, `ScenarioResultSchemaVersion`), `Collections\Collection`, `Time\{Duration,Timestamp}`, `Filesystem\Path`.
- **Namespace = directory, exactly.** Both consumers load this package via symlinked Composer path repositories; a PSR-4 path/namespace mismatch here surfaces as confusing missing-class failures in *their* test suites, not here.
- No test setup of its own: lib tests live in the root harness suite (`tests/Lib/`, `tests/Time/`, `tests/Collections/`).
- The package is pinned at version `1.0.0` and consumed as `^1.0` — leave the version alone while pre-release.
