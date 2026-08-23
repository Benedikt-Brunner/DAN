# DAN — DAL ANalyzer

Repeatable, isolated profiling of the **SQL produced by Shopware's DAL**: both *what* SQL a given DAL implementation generates (inspectable, diffable) and *how well* that SQL executes against representative workloads on a MySQL/MariaDB version matrix.

Generation *time* is explicitly out of scope — producing the SQL is never the bottleneck.

## Model

- **The DAL implementation is the only independent variable.** A run profiles one implementation across a fixed grid of `(scenario × dataset tier × database version)`. Diffs happen between profiles of two implementations.
- **Live per cell, no record-and-replay.** The DAL executes every scenario live against every grid cell; the SQL is captured as an *artifact* of the cell. Replay would lie: DAL reads are data-dependent multi-statement sequences (searcher ids feed the reader query), and the DAL emits engine-dependent SQL in places (MySQL vs MariaDB JSON paths).
- **Checkout-first.** Point `--dal` at a local `shopware/shopware` checkout (its `src/Core` contents are fingerprinted, including uncommitted and untracked files), or at a released version for baselines.
- **Datasets are seeded through the DAL under test** (deterministic ids, fixed data, idempotent upserts) and snapshotted into a cache keyed by `(DAL fingerprint × tier × engine × seeder version)`. Tiers: S / M / L. The snapshot cache is load-bearing — L-tier seeding takes hours and must happen at most once per implementation.
- **Latency is the headline metric, warm-cache only.** Fixed warmup, then N measured iterations, median + p95. Noise control is mandatory: A/B runs measure both implementations **within one session** in mirrored alternating blocks (`A,B / B,A / …`), each implementation against its **own isolated database container**. Latency numbers are never compared across CI jobs — GitHub-hosted runner VMs are not comparable to each other.
- **Protocols are frozen into run manifests.** The matrix is chosen via CLI flags, but every profile records its fully-resolved protocol; `dan diff` refuses protocol-mismatched comparisons unless overridden.

## Usage

Running DAN requires PHP 8.2 or newer, Composer, and Docker.

```bash
# A/B diff: local checkout (candidate) vs released baseline
bin/dan run \
  --dal v6.6.10.0 \
  --dal ../shopware \
  --db mysql:8.0 --db mariadb:11.4 \
  --tier S --tier M \
  --iterations 30 --warmup 5 --blocks 4

# Single-implementation profile
bin/dan run --dal ../shopware --db mysql:8.0 --tier S

# Diff two stored profiles (SQL/structure only — cross-session latency is not gated)
bin/dan diff runs/<session>/a runs/<session>/b --out report.md
```

A/B runs write `runs/<session>/report.md` (PR-comment-ready markdown) and exit non-zero on gate violations (`--max-regression`, default 15%; `--fail-on-sql-change` opt-in).

## Development

Contributor and agent documentation — repository layout, commands, design rules, and the four-layer testing model — lives in [AGENTS.md](AGENTS.md), with package-specific rules in [bundle/AGENTS.md](bundle/AGENTS.md) and [lib/AGENTS.md](lib/AGENTS.md). The `CLAUDE.md` files are symlinks to their sibling `AGENTS.md`.

## Roadmap

See [ROADMAP.md](ROADMAP.md) — phased implementation plan (toolchain split, Pest/Infection/Eris adoption, pipeline hardening, result-set equivalence, scenario auto-discovery, `dan explore`) plus the testing decision log.

## Status

Scaffold. Deterministic core (protocol, scheduling, normalization, diffing, storage) is implemented and unit-tested. Known-unproven parts, in dependency order:

1. **DAL runtime construction** (`RuntimeFactory`) — needs hardening against real 6.5/6.6/6.7 skeletons (create-project shape, bundle registration, path-repo resolution).
2. **`system:install` + seeding flow** against live containers; L-tier seeding endurance; snapshot size vs GitHub Actions cache limits.
3. **DBAL compatibility** — the recorder is implemented as DBAL driver middleware so it does not depend on the deprecated `SQLLogger` hook.
4. **Synthetic-entity breadth** — the typed JSON-path entity is registered, seeded and measured; inheritance, translation and deep-association shapes remain.
5. **Corpus breadth** — orders/customers in the seeder and their scenarios.
6. HTML report for local runs; GUI on top of `index.sqlite`.
