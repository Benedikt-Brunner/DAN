# DAN profile diff

| | A (baseline) | B (candidate) |
|---|---|---|
| Implementation | v6.6.10.22 | local checkout |
| Identity | `baseline-cccccccc` | `candidate-ccccccc` |
| Recorded | 2026-08-20 10:00:00 GMT+0000 | 2026-08-20 10:20:00 GMT+0000 |

Protocol: 5 warmup + 30 measured iterations in 4 blocks, 2 warmup at the start of every block.

## Gate violations

- :x: product.deep-read / S / mysql-8.0: median wall time regressed 55.2% (95% interval [+53.2%, +57.2%] excludes zero; 12.50ms -> 19.40ms, limit 10.0%)
- :x: product.keyword-listing / S / mysql-8.0: generated SQL changed (~0)

## S / mysql-8.0

| Scenario | Statements | SQL | Median A | Median B | Delta | p95 A | p95 B |
|---|---|---|---:|---:|---:|---:|---:|
| product.deep-read | 4 -> 4 | unchanged | 12.50ms | 19.40ms | +55.2% [+53.2%, +57.2%] | 14.10ms* | 26.00ms* |
| product.keyword-listing | 4 -> 4 | :warning: changed (~0) | 8.20ms | 8.30ms | +1.2% [-0.8%, +3.2%] :grey_question: blocks disagree | 9.90ms* | 10.00ms* |

Delta: estimated median shift with its 95% bootstrap interval. \* p95 from fewer than 100 samples is close to the largest observed value and only indicative.

## Block diagnostics

Median wall time per mirrored block pair. "Order" is which implementation ran first within the pair; a delta that flips sign between pairs points at an order effect or host drift rather than at the implementation.

| Cell | Block | Order | Median A | Median B | Delta |
|---|---:|---|---:|---:|---:|
| product.deep-read / S / mysql-8.0 | 0 | A, B | 12.50ms | 19.40ms | +55.2% |
| product.deep-read / S / mysql-8.0 | 1 | B, A | 12.50ms | 19.40ms | +55.2% |
| product.keyword-listing / S / mysql-8.0 | 0 | A, B | 8.10ms | 8.60ms | +6.2% |
| product.keyword-listing / S / mysql-8.0 | 1 | B, A | 8.30ms | 8.00ms | -3.6% |
