# DAN profile diff

| | A (baseline) | B (candidate) |
|---|---|---|
| Implementation | v6.6.10.22 | local checkout |
| Identity | `baseline-cccccccc` | `candidate-ccccccc` |
| Recorded | 2026-08-20 10:00:00 GMT+0000 | 2026-08-20 10:20:00 GMT+0000 |

Protocol: 5 warmup + 30 measured iterations in 4 blocks.

## Gate violations

- :x: product.deep-read / S / mysql-8.0: median wall time regressed 55.2% (12.50ms -> 19.40ms, limit 10.0%)
- :x: product.keyword-listing / S / mysql-8.0: generated SQL changed (statements 0)

## S / mysql-8.0

| Scenario | Statements | SQL | Median A | Median B | Delta | p95 A | p95 B |
|---|---|---|---:|---:|---:|---:|---:|
| product.deep-read | 4 -> 4 | unchanged | 12.50ms | 19.40ms | +55.2% | 14.10ms | 26.00ms |
| product.keyword-listing | 4 -> 4 | :warning: changed (0) | 8.20ms | 8.30ms | +1.2% | 9.90ms | 10.00ms |
