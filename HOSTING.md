# FACT Studio: hosted control, office production

The selected setup uses Laravel 13 and Vue 3 on Hostinger at **https://fulodental.com/studio/**. The office GPU PC runs the existing PHP, Ollama, ComfyUI, ElevenLabs and FFmpeg pipeline. The office connects outward over HTTPS every 15 seconds, so it needs no inbound control port.

The existing dental site and database remain separate. The control room lives at ~/domains/fulodental.com/fact-studio; only its public directory is linked at /studio. Its database, uploaded MP4s, sessions and environment file stay outside the public directory. No dental data is imported.

## Verified hosting limits

Hostinger runs PHP 8.3.36 with the required extensions, but proc_open is disabled and FFmpeg/FFprobe are absent. This account serves the dashboard; the office handles video production and publishing. ElevenLabs, Google and Meta credentials stay encrypted on the office PC. The dashboard receives job summaries, automation settings, health checks and finished MP4s.

The website stays online when the office is off, but production requires the office PC to remain awake, logged in and connected. Offline workers cannot receive new requests; the dashboard shows their last check-in.

## Review and automation

Sign in using the private storage/deploy/control-room-access.txt file. Overview shows production status and request results. Library provides video previews, narration, sources and approval. Automation controls the office schedule and spending caps.

Review is enabled by default. Approve & publish reuses the saved video and narration, even with Automatic publishing off. Approval covers the MP4, title, script, sources and selected destination accounts/visibility. Changes require another review before publishing starts.

Commands have unique IDs and saved office receipts. A retransmission returns its saved result. Interrupted commands pause for inspection. Unanswered commands expire after 30 minutes. Uploaded MP4s remain private and are served using expiring signatures. Instagram can retrieve a signed hosted MP4 without an office tunnel.

An uncertain ElevenLabs request is recorded before sending. A retry cannot send another paid request for that job; reconcile provider history and restore its completed audio first.

## Office connection

Follow [OFFICE-CONNECT.md](OFFICE-CONNECT.md). The source update is storage/deploy/FACT-Studio-office-worker.zip and the separate private pairing file is storage/deploy/office-pairing.json. Keep the pairing file out of Git and public uploads. The server binds to the first installation's persistent ID; do not pair the laptop in place of the office PC.

## Development and deployment

The existing local dashboard remains available. The independent hosted/ Laravel application does not replace the local database or installed models. Run Composer install and npm.cmd install --ignore-scripts inside hosted/, then npm.cmd run build.

php bin/prepare-hosted.php creates private setup files under ignored storage/deploy and preserves generated secrets. Production assets use ASSET_URL=https://fulodental.com/studio. The deployment archive excludes .env, local databases, storage and Node modules. deploy/hostinger-control-room.ps1 checks that archive, preserves the production environment and refuses unrelated occupied directories.

bin/hosting-check.php reads capabilities without reading credentials. Its free-space and PHP memory values are not the account's allocated disk/RAM. bin/daemon.php and deploy/fact-studio-worker.service are templates for a future VPS, not services installed on shared hosting.

## Validation and limits

Run php tests/run.php, php tests/automation.php and php tests/hosted.php (the latter requires hosted Composer dependencies). Tests use isolated data and do not call paid generation or publishing. Browser checks cover sign-in, protected state, worker authentication, signed playback, approval requests, request-forgery protection and mobile layout. Remote browser checks do not pair a worker or execute production.

Uploaded video versions and local artifacts are retained; monitor disk usage. External alert delivery and automatic provider-token renewal are not implemented. A first full AI preview and production trial on the actual office PC remain necessary.
