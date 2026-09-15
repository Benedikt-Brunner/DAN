# Corpus coverage matrix

The built-in scenario corpus (`bundle/src/Scenario/Corpus`) is organised along general DAL dimensions, not along optimizations someone wanted to prove. Every scenario states the DAL behaviour it represents (`Scenario::describe()`) and the exact total it must return per tier (`Scenario::expectedTotal()`), derived from the same dataset rules the seeder writes with (`TierSpec`). A new DAL optimization PR should find its workload here; a scenario that only exists to make one patch look good does not belong in the corpus (checkout-local scenarios are the roadmap's answer for that).

Every scenario runs on every dataset tier (S / M / L) and every supported engine (MySQL, MariaDB) — those two dimensions are the grid, not the corpus. The kernel test `CorpusCoverageTest` seeds tier S and asserts every scenario's total and determinism against it; the M and L expectations follow from the same formulas and are checked at run time through the recorded result set.

## Dimensions and the scenarios covering them

| Dimension | Cell | Scenario | DAL behaviour |
|---|---|---|---|
| Result cardinality | empty | `product.empty-result` | indexed equality matching nothing; no reader query for zero ids |
| Result cardinality | below one page | `product.below-page` | indexed range selecting a handful of rows |
| Result cardinality | exactly one page (at S) | `product.page-boundary` | indexed range whose match count equals the limit at S |
| Result cardinality | many pages | `product.multi-page` | paged listing with a large exact total |
| Query shape | flat | `product.stock-range` | single-table range predicate, sort, no associations |
| Query shape | to-one | `product.tax-to-one` | filter and load through a many-to-one association |
| Query shape | to-many | `product.deep-read` | several associations of different cardinality behind one read |
| Query shape | many-to-many | `product.in-category` | filter through a mapping table by associated id |
| Query shape | deep association | `review.deep-association` | filter three joins deep into a translated field, load the product |
| Translations | translated field filter/sort | `product.keyword-listing`, `category.translated-name` | translation join with system-language fallback |
| Inheritance | inherited field on variants | `product.inherited-name` | value resolved through the parent (inheritance-aware context) |
| Inheritance | inherited association on variants | `product.in-category`, `product.keyword-listing` | variants found through their parent's categories / name |
| Inheritance | inheritance off | `product.score-query` and every parents-only scenario | nameless children never match; no parent joins emitted |
| Grouping | GROUP BY | `product.grouped-by-tax` | field grouping collapses rows to groups |
| Scoring | score query | `product.score-query` | computed `_score`, ranked, query doubles as filter |
| Post-filter / aggregation | post-filter + terms aggregation | `product.post-filter` | aggregation sees the unfiltered set, the result the filtered one |
| Count sorting | sort by association count | `category.by-product-count` | GROUP BY + COUNT inside the sort |
| Predicate | indexed | `product.empty-result`, `product.below-page`, `product.stock-range` | unique / secondary index lookups |
| Predicate | intentionally unindexed | `product.unindexed-ean` | equality on a column without an index |
| JSON | typed JSON path | `synthetic.json-path` | engine-sensitive `JSON_EXTRACT` filtering and sorting |
| Entity | product | most of the above | the widest core entity |
| Entity | category | `category.translated-name`, `category.by-product-count` | translated, hierarchical |
| Entity | product review | `review.deep-association` | second entity with a to-one to product |
| Entity | media | `media.by-extension` | runtime fields and thumbnails hydrated by the reader |
| Entity | synthetic blob | `synthetic.json-path` | probe-owned JSON shape |

Each scenario also declares whether the search considers inheritance (`Scenario::considerInheritance()`): the DAL only resolves inherited fields for variant children — and only emits the parent joins and `COALESCE` expressions to do so — when the context asks for it. Storefront contexts have it on, the default context off; both are DAL shapes worth measuring. Scenarios without `TOTAL_COUNT_MODE_EXACT` report the loaded page as their total, and their expectation says so.

## Dataset rules the expectations rest on

Seeded by `DatasetSeeder`, described by `TierSpec` (any change bumps `SnapshotCache::SEEDER_VERSION`):

- Products `DAN-%08d` with stock `index % 1000`, EAN `DANEAN%08d`, category `index % categories`, the DAN 19 % tax. Every tenth product has one variant child (`DAN-%08d-V1`) that inherits name, tax, price and categories and has its own product number and EAN.
- Review `r` belongs to product `2r` (even products carry one review each), points `r % 5 + 1`.
- Media `dan-media-%08d` rotate through `png`, `jpg`, `webp`, `pdf`.
- Synthetic blobs: segment `index % 16`, score `index % 1000`, active when `index % 3 == 0`.

| Tier | products | variants | categories | reviews | media | synthetic blobs |
|---|---|---|---|---|---|---|
| S | 1 000 | 100 | 50 | 500 | 250 | 1 000 |
| M | 100 000 | 10 000 | 500 | 50 000 | 25 000 | 100 000 |
| L | 1 000 000 | 100 000 | 2 000 | 500 000 | 250 000 | 1 000 000 |

## Known gaps

- **Orders and customers** are not seeded yet. Both need sales-channel-bound satellites (addresses, payment and shipping methods, state machine states) whose required fields differ across the supported DAL range (6.5–6.7 removed `defaultPaymentMethodId`, for one), so their seeding needs version-aware payloads. They stay on the roadmap (Phase 4) rather than being half-seeded here.
- **Inheritance beyond a single variant level** and **translations with a non-system language fallback** are not covered; the synthetic-entity plan in the roadmap is the place for them.
