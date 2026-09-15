# DAN profile diff

| | A (baseline) | B (candidate) |
|---|---|---|
| Implementation | v6.6.10.22 | local checkout |
| Identity | `baseline-bbbbbbbb` | `candidate-bbbbbbb` |
| Recorded | 2026-08-20 10:00:00 GMT+0000 | 2026-08-20 10:20:00 GMT+0000 |

Protocol: 5 warmup + 30 measured iterations in 4 blocks, 2 warmup at the start of every block.

## S / mysql-8.0

| Scenario | Statements | SQL | Median A | Median B | Delta | p95 A | p95 B |
|---|---|---|---:|---:|---:|---:|---:|
| product.deep-read | 4 -> 5 | :warning: changed (1, 3) | 12.50ms | 12.60ms | +0.8% | 14.10ms | 14.30ms |
| synthetic.json-path | 4 -> 4 | unchanged :grey_question: divergent | 3.00ms | 3.10ms | +3.3% | 3.40ms | 3.50ms |

## Block diagnostics

Median wall time per mirrored block pair. "Order" is which implementation ran first within the pair; a delta that flips sign between pairs points at an order effect or host drift rather than at the implementation.

| Cell | Block | Order | Median A | Median B | Delta |
|---|---:|---|---:|---:|---:|
| product.deep-read / S / mysql-8.0 | 0 | A, B | 12.50ms | 12.60ms | +0.8% |
| product.deep-read / S / mysql-8.0 | 1 | B, A | 12.50ms | 12.60ms | +0.8% |
| synthetic.json-path / S / mysql-8.0 | 0 | A, B | 3.00ms | 3.10ms | +3.3% |
| synthetic.json-path / S / mysql-8.0 | 1 | B, A | 3.00ms | 3.10ms | +3.3% |
