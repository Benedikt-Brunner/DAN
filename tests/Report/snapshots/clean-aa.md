# DAN profile diff

| | A (baseline) | B (candidate) |
|---|---|---|
| Implementation | v6.6.10.22 | v6.6.10.22 |
| Identity | `baseline-aaaaaaaa` | `candidate-aaaaaaa` |
| Recorded | 2026-08-20 10:00:00 GMT+0000 | 2026-08-20 10:20:00 GMT+0000 |

Protocol: 5 warmup + 30 measured iterations in 4 blocks.

## M / mariadb-11.4

| Scenario | Statements | SQL | Median A | Median B | Delta | p95 A | p95 B |
|---|---|---|---:|---:|---:|---:|---:|
| product.deep-read | 4 -> 4 | unchanged | 47.30ms | 47.30ms | +0.0% | 55.00ms | 55.00ms |

## S / mysql-8.0

| Scenario | Statements | SQL | Median A | Median B | Delta | p95 A | p95 B |
|---|---|---|---:|---:|---:|---:|---:|
| product.deep-read | 4 -> 4 | unchanged | 12.50ms | 12.50ms | +0.0% | 14.10ms | 14.10ms |
| product.keyword-listing | 4 -> 4 | unchanged | 8.20ms | 8.20ms | +0.0% | 9.90ms | 9.90ms |
