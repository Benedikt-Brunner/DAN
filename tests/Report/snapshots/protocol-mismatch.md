# DAN profile diff

| | A (baseline) | B (candidate) |
|---|---|---|
| Implementation | v6.6.10.22 | v6.7.0.0 |
| Identity | `baseline-dddddddd` | `candidate-ddddddd` |
| Recorded | 2026-08-20 10:00:00 GMT+0000 | 2026-08-21 09:00:00 GMT+0000 |

> [!WARNING]
> The two runs were recorded under **different protocols**. Latency comparisons below are not meaningful.

> [!WARNING]
> The two runs were recorded by **different DAN revisions or database images** (see Reproducibility). SQL comparisons may reflect the tooling or the image rather than the implementation.

Protocol: 5 warmup + 30 measured iterations in 4 blocks, 2 warmup at the start of every block.

## Dataset divergence

> [!CAUTION]
> The two runs did not seed logically equivalent datasets. Every comparison over these datasets is void: the implementations were measured against different data.

- S / mysql-8.0: product: 1000 vs 998 rows; product.categories: same 1000 rows, different values

Cells only present in run A: `product.deep-read--S--mysql-8.0.json`

Cells only present in run B: `order.aggregation--S--mysql-8.0.json`

## Reproducibility

| | A (baseline) | B (candidate) |
|---|---|---|
| DAN revision | `dddddddddddd` | `eeeeeeeeeeee` |
| PHP | 8.4.24 | 8.4.24 |
| Composer | 2.10.3 | 2.10.3 |
| Host | Linux 6.8.0-1021-azure, x86_64 | Linux 6.8.0-1021-azure, x86_64 |
| Host CPU | AMD EPYC 7763 64-Core Processor | AMD EPYC 7763 64-Core Processor |
| Host CPU limit | unknown | unknown |
| Host memory limit | unknown | unknown |
| Docker engine | 29.5.2 on Ubuntu 24.04.4 LTS, x86_64, 4 CPUs, 15.6 GiB | unknown |
| Database network path | published-port (kernel NAT, userland proxy disabled) | published-port |
| Database images | mysql-8.0 @ `sha256:7dcddc01f13b` | mysql-8.0 @ unknown digest |
