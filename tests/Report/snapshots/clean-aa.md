# DAN profile diff

| | A (baseline) | B (candidate) |
|---|---|---|
| Implementation | v6.6.10.22 | v6.6.10.22 |
| Identity | `baseline-aaaaaaaa` | `candidate-aaaaaaa` |
| Recorded | 2026-08-20 10:00:00 GMT+0000 | 2026-08-20 10:20:00 GMT+0000 |

Protocol: 5 warmup + 30 measured iterations in 4 blocks, 2 warmup at the start of every block.

## M / mariadb-11.4

| Scenario | Result | Statements | SQL | Median A | Median B | Delta | p95 A | p95 B |
|---|---|---|---|---:|---:|---:|---:|---:|
| product.deep-read | identical | 4 -> 4 | unchanged | 47.30ms | 47.30ms | +0.0% [-2.0%, +2.0%] | 55.00ms* | 55.00ms* |

Delta: estimated median shift with its 95% bootstrap interval. \* p95 from fewer than 100 samples is close to the largest observed value and only indicative.

## S / mysql-8.0

| Scenario | Result | Statements | SQL | Median A | Median B | Delta | p95 A | p95 B |
|---|---|---|---|---:|---:|---:|---:|---:|
| product.deep-read | identical | 4 -> 4 | unchanged | 12.50ms | 12.50ms | +0.0% [-2.0%, +2.0%] | 14.10ms* | 14.10ms* |
| product.keyword-listing | identical | 4 -> 4 | unchanged | 8.20ms | 8.20ms | +0.0% [-2.0%, +2.0%] | 9.90ms* | 9.90ms* |

Delta: estimated median shift with its 95% bootstrap interval. \* p95 from fewer than 100 samples is close to the largest observed value and only indicative.

## Block diagnostics

Median wall time per mirrored block pair. "Order" is which implementation ran first within the pair; a delta that flips sign between pairs points at an order effect or host drift rather than at the implementation.

| Cell | Block | Order | Median A | Median B | Delta |
|---|---:|---|---:|---:|---:|
| product.deep-read / S / mysql-8.0 | 0 | A, B | 12.50ms | 12.50ms | +0.0% |
| product.deep-read / S / mysql-8.0 | 1 | B, A | 12.50ms | 12.50ms | +0.0% |
| product.keyword-listing / S / mysql-8.0 | 0 | A, B | 8.20ms | 8.20ms | +0.0% |
| product.keyword-listing / S / mysql-8.0 | 1 | B, A | 8.20ms | 8.20ms | +0.0% |
| product.deep-read / M / mariadb-11.4 | 0 | A, B | 47.30ms | 47.30ms | +0.0% |
| product.deep-read / M / mariadb-11.4 | 1 | B, A | 47.30ms | 47.30ms | +0.0% |

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
