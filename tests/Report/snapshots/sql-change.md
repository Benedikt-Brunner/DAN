# DAN profile diff

| | A (baseline) | B (candidate) |
|---|---|---|
| Implementation | v6.6.10.22 | local checkout |
| Identity | `baseline-bbbbbbbb` | `candidate-bbbbbbb` |
| Recorded | 2026-08-20 10:00:00 GMT+0000 | 2026-08-20 10:20:00 GMT+0000 |

Protocol: 5 warmup + 30 measured iterations in 4 blocks.

## S / mysql-8.0

| Scenario | Statements | SQL | Median A | Median B | Delta | p95 A | p95 B |
|---|---|---|---:|---:|---:|---:|---:|
| product.deep-read | 4 -> 5 | :warning: changed (1, 3) | 12.50ms | 12.60ms | +0.8% | 14.10ms | 14.30ms |
| synthetic.json-path | 4 -> 4 | unchanged :grey_question: divergent | 3.00ms | 3.10ms | +3.3% | 3.40ms | 3.50ms |
