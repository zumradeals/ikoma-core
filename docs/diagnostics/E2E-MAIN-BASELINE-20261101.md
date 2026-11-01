# E2E baseline — IKOMA ACCESS

Purpose: trigger the unchanged CI against the exact main commit `9a7729f75dcd99ae005bfe993a040be5d09ab937`, before attributing browser failures to PR #60 or #61.

This branch adds only this diagnostic note. It changes no application code, fixtures, permissions, workflows, or tests.

Reference observations:
- PR #60: production passed; verification failed at `make e2e` and Agent V2 captures.
- PR #61: production passed; verification failed at `make e2e` despite docs-only diff.
- Main commit #59 disabled mandatory 2FA. Its impact on existing browser scenarios is a hypothesis, not a demonstrated cause.

Acceptance: record the baseline CI run, compare the failing test names and failure evidence, then propose the smallest functional fix on a separate branch. Do not merge this diagnostic PR.
