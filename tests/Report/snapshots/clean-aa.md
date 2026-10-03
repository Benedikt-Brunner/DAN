# DAN profile diff

| | A (baseline) | B (candidate) |
|---|---|---|
| Implementation | v6.6.10.22 | v6.6.10.22 |
| Identity | `baseline-aaaaaaaa` | `candidate-aaaaaaa` |
| Recorded | 2026-08-20 10:00:00 GMT+0000 | 2026-08-20 10:20:00 GMT+0000 |

Protocol: 5 warmup + 30 measured iterations in 4 blocks, 2 warmup at the start of every block.

## M / mariadb-11.4

| Scenario | Statements | SQL | Median baseline | Median candidate | Delta | p95 baseline | p95 candidate |
|---|---|---|---:|---:|---:|---:|---:|
| product.deep-read | 4 -> 4 | unchanged | 47.30ms | 47.30ms | +0.0% | 55.00ms | 55.00ms |

## S / mysql-8.0

| Scenario | Statements | SQL | Median baseline | Median candidate | Delta | p95 baseline | p95 candidate |
|---|---|---|---:|---:|---:|---:|---:|
| product.deep-read | 4 -> 4 | unchanged | 12.50ms | 12.50ms | +0.0% | 14.10ms | 14.10ms |
| product.keyword-listing | 4 -> 4 | unchanged | 8.20ms | 8.20ms | +0.0% | 9.90ms | 9.90ms |

## Block diagnostics

Median wall time per mirrored block pair. "Order" is which slot ran first within the pair; a delta that flips sign between pairs points at an order effect or host drift rather than at the implementation.

| Cell | Block | Order | Median baseline | Median candidate | Delta |
|---|---:|---|---:|---:|---:|
| product.deep-read / S / mysql-8.0 | 0 | baseline first | 12.50ms | 12.50ms | +0.0% |
| product.deep-read / S / mysql-8.0 | 1 | candidate first | 12.50ms | 12.50ms | +0.0% |
| product.keyword-listing / S / mysql-8.0 | 0 | baseline first | 8.20ms | 8.20ms | +0.0% |
| product.keyword-listing / S / mysql-8.0 | 1 | candidate first | 8.20ms | 8.20ms | +0.0% |
| product.deep-read / M / mariadb-11.4 | 0 | baseline first | 47.30ms | 47.30ms | +0.0% |
| product.deep-read / M / mariadb-11.4 | 1 | candidate first | 47.30ms | 47.30ms | +0.0% |
