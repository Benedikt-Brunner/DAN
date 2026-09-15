# AGENTS.md — dan/lib

Rules specific to `lib/` (`Dan\Lib\`), on top of the root `AGENTS.md`.

- **Framework-free, dependency-free** (`php >=8.2` only). It must load inside both the harness and every DAL runtime the probe runs in.
- **What belongs here:** code both runtime packages use at runtime, plus general-purpose building blocks that are specific to neither the harness nor the probe (collections, time, filesystem paths) — even while only one package happens to use them. Whether a class is "general" is the maintainer's call; when in doubt, keep it in the package that needs it and ask. Code that only makes sense inside one package stays in that package. Current residents: the protocol vocabulary (`Tier`, `ScenarioName`, `ScenarioResultSchemaVersion`), `Collections\{Collection,Set}`, `Time\{Duration,Timestamp}`, `Filesystem\Path`.
- **Namespace = directory, exactly.** Both consumers load this package via symlinked Composer path repositories; a PSR-4 path/namespace mismatch here surfaces as confusing missing-class failures in *their* test suites, not here.
- No test setup of its own: lib tests live in the root harness suite (`tests/Lib/`, `tests/Time/`, `tests/Collections/`).
- The package is pinned at version `1.0.0` and consumed as `^1.0` — leave the version alone while pre-release.
