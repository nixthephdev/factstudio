"""Full free production check through the UI; does not use ElevenLabs or publish."""
from pathlib import Path
import time
import sys
from playwright.sync_api import sync_playwright, expect

root = Path(__file__).resolve().parents[1]
with sync_playwright() as p:
    browser = p.chromium.launch(channel="chrome", headless=True)
    page = browser.new_page(viewport={"width": 1440, "height": 1100})
    page.goto("http://localhost:8085/?view=library", wait_until="networkidle")
    before = page.request.get("http://localhost:8085/index.php?action=api").json()["speech_used"]
    if len(sys.argv) > 1:
        job_id = int(sys.argv[1])
    else:
        with page.expect_response(lambda r: "action=api" in r.url and r.request.method == "POST") as response:
            page.get_by_role("button", name="Free AI preview", exact=True).click()
        result = response.value.json()
        assert "id" in result, result
        job_id = result["id"]
    print(f"AI preview {job_id} started from the browser", flush=True)
    previous_stage = None
    for attempt in range(240):
        job = page.request.get(f"http://localhost:8085/index.php?action=api&resource=job&id={job_id}").json()["job"]
        assert job["mode"] == "preview", job
        assert job["status"] != "blocked", job.get("error")
        if job["stage"] != previous_stage:
            print(f"Stage: {job['stage']} / {job['title']}", flush=True)
            previous_stage = job["stage"]
        if job["status"] == "ready":
            break
        time.sleep(3)
    else:
        raise AssertionError("AI preview did not finish within twelve minutes")
    assert len(job["data"]["images"]) == 4
    assert len(job["data"]["script"]["scenes"]) == 4
    assert job["data"]["sources"]
    assert job["data"]["video"]["duration"] > 5
    assert job["data"]["voice"]["provider"] == "Windows demo voice"
    assert not job["publications"]
    after = page.request.get("http://localhost:8085/index.php?action=api").json()["speech_used"]
    assert after == before
    page.reload(wait_until="networkidle")
    page.locator(f"[data-job='{job_id}']").wait_for(state="visible")
    page.locator(f"[data-job='{job_id}']").click()
    page.locator("#job-dialog").wait_for(state="visible")
    expect(page.locator("#job-dialog .detail-description")).to_contain_text("free Windows preview voice")
    assert page.locator("#job-dialog video").count() == 1
    page.screenshot(path=str(root / "storage/ai-preview-desktop.png"), full_page=True)
    print(f"PASS: original AI preview {job_id} finished: {job['data']['video']['duration']} seconds, four images, captions and audio", flush=True)
    print("PASS: zero ElevenLabs usage and no publication records", flush=True)
    browser.close()
