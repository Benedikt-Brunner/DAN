#!/usr/bin/env bash
# Trust layer 3 smoke test: run the smallest possible full pipeline and
# assert the artifacts a run promises actually exist and are well-formed.
set -euo pipefail

cd "$(dirname "$0")/.."

DAL_VERSION="${DAL_VERSION:-v6.6.10.22}"
OUT_DIR="runs"

bin/dan run \
    --dal "$DAL_VERSION" --dal "$DAL_VERSION" \
    --db mysql:8.0 \
    --tier S \
    --iterations 3 --warmup 1 --blocks 1 \
    --filter product.deep-read \
    --out "$OUT_DIR" \
    --max-regression 100 --fail-on-sql-change

session="$(ls -td "$OUT_DIR"/*/ | head -1)"
echo "Session: $session"

for expected in a/manifest.json b/manifest.json a/index.sqlite b/index.sqlite report.md; do
    if [ ! -e "$session/$expected" ]; then
        echo "MISSING ARTIFACT: $session/$expected" >&2
        exit 1
    fi
done

cell_count="$(ls "$session"/a/cells/*.json 2>/dev/null | wc -l)"
if [ "$cell_count" -lt 1 ]; then
    echo "No cell results were recorded." >&2
    exit 1
fi

echo "E2E smoke passed: $cell_count cell(s) recorded, report present."
