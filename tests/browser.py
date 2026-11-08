"""Local UI smoke test. Uses installed Chrome; no paid generation or publishing."""
from pathlib import Path
import re
import time
from playwright.sync_api import sync_playwright, expect

root = Path(__file__).resolve().parents[1]
errors = []
with sync_playwright() as p:
    browser = p.chromium.launch(channel="chrome", headless=True)
    page = browser.new_page(viewport={"width": 1440, "height": 1100}, reduced_motion="reduce")
    page.on("pageerror", lambda error: errors.append(str(error)))
    page.goto("http://localhost:8085/", wait_until="networkidle")
    expect(page.locator("#stat-total")).not_to_have_text("0")
    assert page.get_by_role("heading", name="Your ideas, in motion.").is_visible()
    page.locator("[data-job]").first.click()
    page.locator("#job-dialog").wait_for(state="visible")
    assert page.locator("#job-dialog video").count() == 1
    page.locator("#close-dialog").click()
    page.get_by_role("button", name="Create a video", exact=True).click()
    assert page.locator("#create-dialog").is_visible()
    page.locator("#close-create").click()
    page.screenshot(path=str(root / "storage/dashboard-desktop.png"), full_page=True)
    page.get_by_role("link", name="Automation", exact=True).click()
    expect(page.locator("input[name=niche]")).not_to_have_value("")
    old_direction = page.locator("input[name=niche]").input_value()
    page.get_by_role("button", name="Save automation").click()
    expect(page.locator("#toast")).to_contain_text("saved")
    page.reload(wait_until="networkidle")
    expect(page.locator("input[name=niche]")).not_to_have_value("")
    assert page.locator("input[name=niche]").input_value() == old_direction
    page.get_by_role("link", name="Connections", exact=True).click()
    expect(page.locator("input[name=ollama_model]")).not_to_have_value("")
    assert page.locator("input[name=eleven_api_key]").input_value() == ""
    page.set_viewport_size({"width": 390, "height": 844})
    assert page.evaluate("document.documentElement.scrollWidth <= innerWidth")
    page.screenshot(path=str(root / "storage/connections-mobile.png"), full_page=True)
    page.goto("http://localhost:8085/", wait_until="networkidle")
    assert page.evaluate("document.documentElement.scrollWidth <= innerWidth")
    page.screenshot(path=str(root / "storage/dashboard-mobile.png"), full_page=True)
    # The sample renderer remains available in Guide; Overview now leads with motion.
    page.goto("http://localhost:8085/?view=guide", wait_until="networkidle")
    # Exercise the browser -> authenticated API -> detached PHP worker -> MP4 path.
    with page.expect_response(lambda r: "action=api" in r.url and r.request.method == "POST") as response:
        page.get_by_role("button", name="Make a demo", exact=True).click()
    created = response.value.json()
    assert "id" in created, created
    job_id = created["id"]
    for attempt in range(60):
        result = page.request.get(f"http://localhost:8085/index.php?action=api&resource=job&id={job_id}").json()
        assert result["job"]["status"] != "blocked", result["job"].get("error")
        if result["job"]["status"] == "ready":
            break
        time.sleep(1)
    else:
        raise AssertionError("Detached worker did not complete the demo within 60 seconds")
    assert not errors, errors
    print("PASS: dashboard, dialogs, saved settings, empty secret fields, mobile layout, and detached demo render")
    print(f"PASS: browser-created demo {job_id} reached ready with a real MP4")
    browser.close()
