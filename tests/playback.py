"""Confirm a rendered video actually decodes and advances in the browser."""
import sys
from playwright.sync_api import sync_playwright

job_id = int(sys.argv[1])
with sync_playwright() as p:
    browser = p.chromium.launch(channel="chrome", headless=True)
    page = browser.new_page()
    page.goto("http://localhost:8085/?view=library", wait_until="networkidle")
    page.locator(f"[data-job='{job_id}']").click()
    video = page.locator("#job-dialog video")
    video.wait_for(state="visible")
    video.evaluate("video => { video.muted = true; return video.play(); }")
    page.wait_for_timeout(1200)
    result = video.evaluate("video => ({time: video.currentTime, duration: video.duration, ready: video.readyState, error: video.error?.message})")
    assert result["time"] > 0 and result["ready"] >= 2 and not result["error"], result
    print(f"PASS: preview {job_id} decodes and plays in Chrome ({result['duration']:.1f}s)")
    browser.close()
