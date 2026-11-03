"""Check that a blocked local preview retries without blocking the HTTP request."""
import sys
import time
from playwright.sync_api import sync_playwright

job_id = int(sys.argv[1])
with sync_playwright() as p:
    browser = p.chromium.launch(channel="chrome", headless=True)
    page = browser.new_page()
    page.goto("http://localhost:8085/?view=library", wait_until="networkidle")
    page.locator(f"[data-job='{job_id}']").click()
    page.locator("#job-dialog").wait_for(state="visible")
    started = time.monotonic()
    with page.expect_response(lambda r: "action=api" in r.url and r.request.method == "POST", timeout=15000) as response:
        page.get_by_role("button", name="Retry job", exact=True).click()
    result = response.value.json()
    assert result.get("ok"), result
    elapsed = time.monotonic() - started
    assert elapsed < 10, elapsed
    print(f"PASS: preview {job_id} retry returned in {elapsed:.2f}s while the detached worker continued")
    browser.close()
