# Contributing to DAN

Thanks for your interest! DAN is pre-1.0 and moving fast — opening an issue to discuss a change before investing in a PR is usually worth it.

## Setup

Three Composer packages with separate vendor dirs (see the monorepo layout in [AGENTS.md](AGENTS.md)):

```bash
composer install                # root harness
composer install -d lib         # shared lib
composer install -d bundle      # probe bundle
```

Requires PHP 8.4+ for the harness (the probe bundle and lib keep an 8.2 floor, matching the DAL versions the probe runs inside), Composer, and Docker (for the database-backed tests and the e2e smoke run).

## The gate

Every change must pass at the repository root:

```bash
composer check   # lint + PHPStan (level max) + unit tests
```

If anything under `bundle/` changed, the bundle's own PHPStan must pass too, and the kernel integration tests run when a database is available (`DATABASE_URL`).

## Design rules

The full contributor guide — domain modeling, serialization boundaries, naming vocabulary, class design, code style, and the four-layer testing model — lives in [AGENTS.md](AGENTS.md), with package-specific rules in [bundle/AGENTS.md](bundle/AGENTS.md) and [lib/AGENTS.md](lib/AGENTS.md). Read it before writing code; the custom PHPStan rules and phpcs sniffs enforce most of it mechanically.

Two rules that bite most often:

- **Named arguments** on every call into `Dan\*` code passing two or more arguments.
- If seeder output changes in any way, bump `SnapshotCache::SEEDER_VERSION`.

## License

By contributing, you agree that your contributions are licensed under the [MIT License](LICENSE).
