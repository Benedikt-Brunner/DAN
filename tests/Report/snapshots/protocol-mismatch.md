# DAN profile diff

| | A (baseline) | B (candidate) |
|---|---|---|
| Implementation | v6.6.10.22 | v6.7.0.0 |
| Identity | `baseline-dddddddd` | `candidate-ddddddd` |
| Recorded | 2026-08-20 10:00:00 GMT+0000 | 2026-08-21 09:00:00 GMT+0000 |

> [!WARNING]
> The two runs were recorded under **different protocols**. Latency comparisons below are not meaningful.

Protocol: 5 warmup + 30 measured iterations in 4 blocks.

Cells only present in run A: `product.deep-read--S--mysql-8.0.json`

Cells only present in run B: `order.aggregation--S--mysql-8.0.json`
