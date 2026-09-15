# DAN profile diff

| | A (baseline) | B (candidate) |
|---|---|---|
| Implementation | v6.6.10.22 | local checkout |
| Identity | `baseline-bbbbbbbb` | `candidate-bbbbbbb` |
| Recorded | 2026-08-20 10:00:00 GMT+0000 | 2026-08-20 10:20:00 GMT+0000 |

Protocol: 5 warmup + 30 measured iterations in 4 blocks, 2 warmup at the start of every block.

## Gate violations

- :x: product.deep-read / M / mysql-8.0: the implementations returned different results (different ids (3 in A, 2 in B); total 120 in A, 118 in B; B returned varying results between its own iterations)

## Result divergence

> [!CAUTION]
> The two implementations did not return the same result for these cells. Their latency deltas compare different work and must not be read as performance.

- product.deep-read / M / mysql-8.0: different ids (3 in A, 2 in B); total 120 in A, 118 in B; B returned varying results between its own iterations

## M / mysql-8.0

| Scenario | Result | Statements | SQL | Median A | Median B | Delta | p95 A | p95 B |
|---|---|---|---|---:|---:|---:|---:|---:|
| product.deep-read | :x: differs | 4 -> 4 | unchanged | 40.00ms | 31.00ms | -22.5% [-24.5%, -20.5%] | 44.00ms* | 35.50ms* |

Delta: estimated median shift with its 95% bootstrap interval. \* p95 from fewer than 100 samples is close to the largest observed value and only indicative.

## S / mysql-8.0

| Scenario | Result | Statements | SQL | Median A | Median B | Delta | p95 A | p95 B |
|---|---|---|---|---:|---:|---:|---:|---:|
| product.deep-read | identical | 4 -> 5 | :warning: changed (~1, ~3, +4) | 12.50ms | 12.60ms | +0.8% [-1.2%, +2.8%] | 14.10ms* | 14.30ms* |
| synthetic.json-path | identical | 4 -> 4 | unchanged :grey_question: A #3 intermittent (5/30); B #1 SQL varies (30/30) | 3.00ms | 3.10ms | +3.3% [+1.3%, +5.3%] | 3.40ms* | 3.50ms* |

Delta: estimated median shift with its 95% bootstrap interval. \* p95 from fewer than 100 samples is close to the largest observed value and only indicative.

## Query plans of changed statements

Captured with `EXPLAIN FORMAT=JSON` after timing, bound to the parameter values the DAL used. Row counts are the optimizer's estimates.

| Cell | Statement | Plan A | Plan B | Material changes |
|---|---|---|---|---|
| product.deep-read / S / mysql-8.0 | ~1 | ref product via idx_product_number (~1 rows) | ALL product (~1000 rows); temporary table; filesort | product: access ref -> ALL; product: index idx_product_number -> none; product: ~1 -> ~1000 rows; temporary table introduced; filesort introduced |
| product.deep-read / S / mysql-8.0 | ~3 | eq_ref product via PRIMARY (~1 rows) | eq_ref product via PRIMARY (~1 rows) | none |
| product.deep-read / S / mysql-8.0 | +4 | no statement | not explainable | n/a |

## Block diagnostics

Median wall time per mirrored block pair. "Order" is which implementation ran first within the pair; a delta that flips sign between pairs points at an order effect or host drift rather than at the implementation.

| Cell | Block | Order | Median A | Median B | Delta |
|---|---:|---|---:|---:|---:|
| product.deep-read / S / mysql-8.0 | 0 | A, B | 12.50ms | 12.60ms | +0.8% |
| product.deep-read / S / mysql-8.0 | 1 | B, A | 12.50ms | 12.60ms | +0.8% |
| synthetic.json-path / S / mysql-8.0 | 0 | A, B | 3.00ms | 3.10ms | +3.3% |
| synthetic.json-path / S / mysql-8.0 | 1 | B, A | 3.00ms | 3.10ms | +3.3% |
| product.deep-read / M / mysql-8.0 | 0 | A, B | 40.00ms | 31.00ms | -22.5% |
| product.deep-read / M / mysql-8.0 | 1 | B, A | 40.00ms | 31.00ms | -22.5% |

## Reproducibility

| | A (baseline) | B (candidate) |
|---|---|---|
| DAN revision | `dddddddddddd` | `dddddddddddd` |
| PHP | 8.4.24 | 8.4.24 |
| Composer | 2.10.3 | 2.10.3 |
| Host | Linux 6.8.0-1021-azure, x86_64 | Linux 6.8.0-1021-azure, x86_64 |
| Host CPU | AMD EPYC 7763 64-Core Processor | AMD EPYC 7763 64-Core Processor |
| Host CPU limit | unknown | unknown |
| Host memory limit | unknown | unknown |
| Docker engine | 29.5.2 on Ubuntu 24.04.4 LTS, x86_64, 4 CPUs, 15.6 GiB | 29.5.2 on Ubuntu 24.04.4 LTS, x86_64, 4 CPUs, 15.6 GiB |
| Database network path | published-port (kernel NAT, userland proxy disabled) | published-port (kernel NAT, userland proxy disabled) |
| Database images | mysql-8.0 @ `sha256:7dcddc01f13b` | mysql-8.0 @ `sha256:7dcddc01f13b` |
