#!/usr/bin/env python3
"""Publish only sanitized Playwright failing-spec names and generic error classes, never raw log content."""
import json
import re
import sys
from pathlib import Path

log=Path(sys.argv[1])
out=Path(sys.argv[2])
if not log.is_file():
    raise SystemExit("STOP: E2E log absent")
raw=re.sub(r"\x1b\[[0-9;]*m","",log.read_text(errors="replace"))
# Known, non-sensitive file paths only. Do not forward titles, assertions, stdout or environment variables.
files=sorted(set(re.findall(r"tests/e2e/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+\.spec\.ts",raw)))
categories={
  "timeout":bool(re.search(r"Timeout (?:of [0-9]+ms |[0-9]+ms )?exceeded|Test timeout of",raw,re.I)),
  "assertion":bool(re.search(r"(?:AssertionError|expect\(received\)|expect\(locator\))",raw)),
  "navigation":bool(re.search(r"net::ERR_|page\.goto:|Navigation failed",raw)),
  "locator":bool(re.search(r"locator\.(?:click|fill|waitFor|textContent)",raw)),
  "browser":bool(re.search(r"Target closed|browserType\.launch|Browser closed",raw)),
  "http_500":bool(re.search(r"HTTP (?:500|502|503)|Internal Server Error",raw,re.I)),
}
match=re.findall(r"\b(\d+)\s+(?:failed|passed|skipped)\b",raw)
# Only explicit static milestones. This never forwards URL parameters, accounts, DOM, secret or raw logs.
allowed_steps={
 "auth.start","auth.login.page","auth.login.open","auth.login.rendered",
 "auth.login.navigated","auth.login.path.login","auth.login.path.other",
 "auth.login.http.ok","auth.login.http.servererror","auth.login.http.unexpected",
 "auth.login.mount.present","auth.login.mount.absent",
 "auth.login.heading.visible","auth.login.email.visible","auth.login.email.exists",
 "auth.login.email.absent","auth.login.email.filled","auth.login.password.filled",
 "auth.login.scripts.present","auth.login.scripts.absent",
 "auth.login.headings.present","auth.login.headings.absent",
 "auth.login.submit","auth.login.responded","auth.login.throttled",
 "auth.challenge.visible","auth.challenge.screenshot","auth.otp.submit",
 "auth.authenticated","auth.login.done","auth.accounts.menu","auth.accounts.click",
 "auth.accounts.visible","auth.journal.navigate","auth.journal.visible",
}
steps=[step for step in re.findall(r"IKOMA_E2E_STEP:(auth\.[a-z.]+)",raw) if step in allowed_steps]
result={
 "scope":"sandbox-e2e-diagnostic-only",
 "raw_logs_uploaded":False,
 "failure_spec_paths_only":files,
 "error_categories":categories,
 "numeric_counts_reported":match[-8:],
 "last_safe_stage":steps[-1] if steps else None,
 "safe_stage_trace":steps[-20:],
 "limits":["No raw assertions, test data, screenshots, traces, environment variables or credentials published",
           "If no failed spec can be distinguished, a narrower diagnostic run is required",
           "Failing E2E status is preserved and never waived"]
}
out.parent.mkdir(parents=True,exist_ok=True)
out.write_text(json.dumps(result,ensure_ascii=False,indent=2)+"\n")
print("E2E diagnostic categories:",",".join(k for k,v in categories.items() if v) or "none",
      "referenced spec files:",len(files))
