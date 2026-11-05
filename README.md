# FACT Studio

A local PHP studio for original illustrated shorts. Compatible with the PHP 8.0 installation in XAMPP. No Composer, Node, paid text API, or database server is required at runtime.

**Short motion tests:** see [LOCAL-ANIMATION.md](LOCAL-ANIMATION.md). **Animate a shot** generates a short, silent temporal-diffusion preview locally. New renders omit text overlays; existing videos keep their original appearance.

**Moving to another PC:** see [HOME-SETUP.md](HOME-SETUP.md). Clone the source, restore your separate encrypted connection backup, and install the local tools for the new machine. Credentials and model installations are excluded from Git.

**Returning to the office PC:** read [the work PC handoff](WORK-PC-2026-11-04.md) and [Instagram reconnection instructions](OFFICE-INSTAGRAM.md). Keep the office library and installed tools; reconnect Instagram locally without carrying credentials through Git.

## Open the studio

```powershell
cd C:\xampp\htdocs\fact
powershell -ExecutionPolicy Bypass -File scripts/start-studio.ps1
```

Open **http://localhost:8085/**. Alternatively, run `php -S 127.0.0.1:8085 router.php` in a terminal, or use `http://localhost/fact/` when XAMPP Apache is running with this project's `.htaccess` enabled. All application screens and APIs require a loopback client and a localhost Host header. This is a personal local application, not a remotely accessible multi-user service.

The SQLite database and encryption key are created automatically under `storage/`. Back up `storage/studio.sqlite` and `storage/secret.key` together. Credentials are encrypted with AES-256-GCM, never returned by the settings API, and redacted from recorded errors. Keep `storage/`, tools, and source directories private if deploying with a different server.

## A real, free demo

Click **Make a demo film**. This runs a detached PHP worker, draws four sample frames with PHP GD, uses Windows System.Speech for narration, and renders an H.264/AAC MP4 at 720 × 1280 with camera motion and no text overlays. Demo artwork and narration are explicitly labeled; they are not AI generation. Demo jobs cannot publish.

FFmpeg is installed locally under `tools/ffmpeg/bin`. To install on another machine:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/install-ffmpeg.ps1
```

The installer verifies the archive against the publisher's SHA-256 checksum. It does not alter PATH or system settings. FFmpeg binaries are ignored by Git; see [FFmpeg licensing](https://ffmpeg.org/legal.html) before distributing binaries as part of a product.

**Free AI preview:** click “Try an AI preview” on Overview or “Free AI preview” in the library. This runs real NASA research, local script generation, source checks and ComfyUI image generation, then uses a free Windows system voice to render a complete film. These are original AI illustrations, unlike the sample demo. AI previews never consume ElevenLabs credits and cannot publish. The Windows voice is a temporary quality preview; live production uses your chosen ElevenLabs voice. You can also run `php bin/console.php preview`.

## Enable live production

1. **Ollama:** install [Ollama for Windows](https://docs.ollama.com/windows), then run `ollama run qwen3:4b`. A project-local installer is also provided: `powershell -ExecutionPolicy Bypass -File scripts/install-writer.ps1`. It downloads the official portable build and model (several GB), verifies the build's GitHub asset digest, starts a local-only server, and stores weights under `tools/ollama-models`. Run it again after restarting Windows to restart this project-local writer. Local cloud features are disabled for this process.
2. **ComfyUI:** run `powershell -ExecutionPolicy Bypass -File scripts/install-illustrator.ps1` to create an isolated Python environment, install CUDA 12.6 PyTorch and ComfyUI, and download the SHA-256-verified SD 1.5 checkpoint. Python 3.13 and Git are required for this installer. Downloads use several GB. It starts the local server with low-VRAM loading and FP32 diffusion for the GTX 1660 Ti. Alternatively, install [ComfyUI](https://github.com/Comfy-Org/ComfyUI) and a [Stable Diffusion 1.5 checkpoint](https://huggingface.co/stable-diffusion-v1-5/stable-diffusion-v1-5) yourself, put the checkpoint in `models/checkpoints`, start on `127.0.0.1:8188`, and save its exact filename in Connections. `workflows/sd15.json` is the API workflow: one 512 × 896 image at a time. Model licensing remains applicable. A real one-image benchmark on this GTX 1660 Ti took approximately 55 seconds including first model load. It is not a guarantee for every scene.
3. **ElevenLabs:** create an API key with TTS and voice-list access. Save it in Connections, click Load voices, and choose a Voice ID. Save your preferred speech model. Only live narration requests consume paid credits. Requests reserve characters against a daily app limit before submission; failed/uncertain requests also count conservatively. The provider's actual credit accounting can differ by model and request. Set a provider-side key credit limit too if available.
4. Click **Create a video**. Leave the angle blank for automatic story selection. Until automatic publishing is enabled, the finished video stays ready in the local library.

Live production: NASA RSS candidates → optional public competitor metadata → local AI story selection → readable article extraction → original script → source excerpt citations → a separate local AI check of the title and every scene → four ComfyUI images → ElevenLabs speech with character timestamps → FFmpeg edit → stream validation → optional publishing. Up to three free local script attempts are allowed before pausing; failed paid narration is not automatically repeated. Qwen's thinking mode is disabled to keep structured answers within the local generation budget.

The writer first chooses four passages, then writes each scene using only its assigned passage. Each scene is independently checked using that same passage, so neighboring text cannot silently supply missing evidence. A local log of the most recent review is saved in `storage/logs/source-check.json`. These checks reduce unsupported claims but remain model judgments, not proof of accuracy.

After restarting Windows, run `powershell -ExecutionPolicy Bypass -File scripts/start-ai.ps1` to start both project-local AI services without downloading them again.

The writer unloads its model before image generation to conserve the 6 GB GPU. Rendering uses CPU x264. The research/narration pipeline still animates generated stills. The separate **Animate a shot** preview uses AnimateLCM for short, silent generated motion clips; it does not yet replace the full narrated production pipeline. It does not use free cloud demos or promise unlimited cloud generation. Source checks are heuristic and can miss false claims. It makes no earnings or monetization guarantee.

Research currently supports **NASA space and astronomy stories**. It filters used source URLs, restricts article fetches to known NASA HTTPS hosts, treats fetched text as data, and includes source links in publication descriptions. A custom angle must be compatible with the available sources. Competitor research is optional: add a YouTube Data API key and search keywords. Only public metadata is compared; private retention, earnings and transcripts are not obtained.

## Publishing

### YouTube

Create a Google Cloud project, enable YouTube Data API v3, configure OAuth consent, and create a **Web application** client. Add your Google account as a test user if required by your consent screen configuration. Save the client ID/secret and the local studio URL. Register the **exact callback shown in Connections**, usually:

```text
http://localhost:8085/index.php?action=oauth_callback
```

Save settings before clicking Connect YouTube. Requested scopes are `youtube.upload` and `youtube.readonly`. The refresh token is encrypted. Enable the YouTube destination and select public, unlisted or private visibility, then enable Automatic publishing in Automation. Account consent, app verification where required, quotas, OAuth testing expiry, and channel eligibility are controlled by Google. This application cannot grant itself those permissions.

Uploads use the [resumable protocol](https://developers.google.com/youtube/v3/guides/using_resumable_upload_protocol). The session is saved before sending file chunks. Retries query the existing session rather than starting a second upload. An expired/unrecoverable session stops for attention rather than risking a duplicate. The saved publication visibility is respected; the library's “Published” status means all selected platforms accepted the submission, even when a YouTube destination is unlisted or private.

### Facebook

Save a Facebook Page ID and a Page access token with the appropriate Reel publishing permissions. This publishes to Pages, not personal profiles. The adapter creates a Reel upload session, uploads bytes, requests publication, and polls processing/publication status. It records the remote ID to reuse the existing Reel on retry. Configure the Graph API version supported by your Meta app (the default is v23.0; it is a configurable compatibility target, not a claim about the latest release).

### Instagram

This adapter uses **Instagram API with Facebook Login**. You need a linked professional Instagram account, its ID, and a token with the appropriate publishing permissions. It creates a Reel container with a public `video_url`, waits for FINISHED, then calls media_publish. A public HTTPS route to this studio is necessary for Meta to retrieve the MP4; enter its base address in Connections. This can be supplied by your own HTTPS tunnel, which is not installed or configured by this app.

Only an MP4 endpoint with a valid, job-specific, two-hour signature can be accessed through a public host. Dashboard/API access remains restricted to localhost. The signed link acts as a temporary bearer credential, so do not share it. The studio must stay reachable until Meta finishes fetching the file. This app does not automatically renew Meta tokens; expired tokens or missing app permissions pause publishing. An ambiguous Instagram media_publish result stops for manual reconciliation rather than blindly posting again.

**No account connection, posting, or paid generation is tested with real credentials by default.** UI readiness for saved credentials indicates configuration, not proof of account permissions or token validity. Demo mode always bypasses publishers.

## Scheduling and recovery

Choose a daily production time (Asia/Manila), turn on Daily production, then register the Windows task:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/register-schedule.ps1
```

The task checks every five minutes while this Windows user is logged in. Keep the PC awake. It queues one scheduled live job per Manila day, starting at the configured time. The daily video cap includes manually queued live jobs; caps of 2–3 permit additional manual videos, while the daily schedule still creates only one. Unique schedule keys prevent duplicate scheduled jobs. An exclusive worker lock prevents simultaneous GPU and narration jobs. Starting a worker also marks interrupted running jobs as blocked so they can be retried.

Failed jobs pause with an actionable error. Retry reuses completed steps, saved audio, images, and remote upload sessions. Automatic repeated paid requests are intentionally not performed. “Daily production” and “Automatic publishing” are independent switches. Disabling production prevents future scheduled jobs; it does not cancel a current process.

```powershell
php bin/console.php health
php bin/console.php work --once
php bin/console.php tick
php bin/console.php retry 1
powershell -ExecutionPolicy Bypass -File scripts/register-schedule.ps1 -Remove
```

Local data lives in `storage/jobs/<id>/`; logs are in `storage/logs/`. The library provides MP4 download and playback. Byte-range streaming is supported. There is no automatic artifact deletion, so review disk usage as your library grows.

## Validation

```powershell
php tests/run.php
powershell -ExecutionPolicy Bypass -File tests/http.ps1
php tests/research.php
php bin/console.php demo
```

The isolated core checks cover credential encryption/redaction, local endpoint restrictions, job claiming, schedule deduplication, speech spend caps, source-evidence validation, subtitle timing/escaping, signed media URLs, and the prohibition on publishing demos. HTTP checks cover the five screens, omitted secrets, CSRF, private file blocking, Host validation, and MP4 range playback. The research check fetches real NASA content without a paid API. Demo rendering uses no paid credentials. Publishing and live ElevenLabs calls require your accounts and have not been exercised by these checks.

For browser checks using the installed Chrome browser, install Python Playwright in a development environment and run `tests/browser.py`. It checks dialogs, saving settings, mobile overflow, and browser-created demo rendering. `tests/browser-preview.py` exercises real free AI production and can take several minutes; pass an existing preview ID to monitor it instead of creating another. `tests/browser-retry.py <id>` checks that a blocked job is requeued promptly while its worker runs independently. These scripts are development checks, not application runtime dependencies.

## Source documentation

- [Ollama chat API](https://docs.ollama.com/api/chat)
- [ComfyUI API example](https://github.com/Comfy-Org/ComfyUI/blob/master/script_examples/websockets_api_example.py)
- [ElevenLabs speech with timing](https://elevenlabs.io/docs/api-reference/text-to-speech/convert-with-timestamps)
- [NASA RSS](https://www.nasa.gov/rss-feeds/)
- [YouTube upload API](https://developers.google.com/youtube/v3/docs/videos/insert)
- [Meta Facebook API collection](https://www.postman.com/meta/facebook/documentation/r56bjfd/facebook-api)
- [Meta Instagram API collection](https://www.postman.com/meta/instagram/documentation/6yqw8pt/instagram-api)
