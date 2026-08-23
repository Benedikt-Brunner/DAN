# Curated pessimization patches

Each `*.patch` here is a small, deliberate performance regression applied to a
DAL checkout by the nightly `injected-regression` calibration job. DAN diffs
the pessimized DAL against its unpatched baseline and **must fail the gate** —
a passing gate is a false negative and fails the calibration job.

Rules for a good pessimization patch:

- It must change DAL *query generation* (an extra join, a dropped index hint,
  a forced full scan via a non-sargable predicate), not add artificial delay —
  `sleep()` would test the stopwatch, not the instrument.
- It should be large enough to clear the gate threshold on the S tier with CI
  noise (target: >2x the `--max-regression` used by the calibration job).
- Name it `NNN-short-description.patch` against the tag pinned in
  `.github/workflows/calibration.yml` (`DAL_BASELINE`), and note here which
  scenario(s) it is expected to affect.

| Patch | Expected detection |
|---|---|
| _none yet_ | Authoring the first patch requires a checkout of the pinned baseline; see workflow. |
