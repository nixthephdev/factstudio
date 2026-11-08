# Connect the office GPU studio

Dashboard: **https://fulodental.com/studio/**. Login details are in the setup PC's private storage/deploy/control-room-access.txt file. The site waits for the office until these steps are completed on that PC.

Bring these files privately from the setup PC:

- storage/deploy/FACT-Studio-office-worker.zip ? source update without video data, credentials or installed AI tools.
- storage/deploy/office-pairing.json ? secret pairing file. Keep it out of Git, public hosting and chat.

## Update the office project

Locate the existing office project folder, usually C:\xampp\htdocs\fact or C:\xampp\htdocs\factstudio. Keep its storage and tools directories. Finish any active production job, then make a backup:

```powershell
cd C:\xampp\htdocs\fact
php bin/transfer.php export .\backups\office-before-hosted.factbackup
```

Extract the source ZIP into that existing folder. It contains no database, keys, model weights or media. Retain any office-specific source edits before replacing files.

## Pair and check once

Use the real private path where you copied the pairing JSON:

```powershell
php bin/hub.php pair C:\private\office-pairing.json
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/start-ai.ps1
php bin/console.php health
php bin/hub.php monitor --once
```

The token is encrypted in the office database. Monitor mode reports status and uploads saved previews but executes no dashboard commands, scheduled production or publishing. Check that the website shows the office name and video library. The initial sync may take longer while existing MP4s upload.

Check existing model installations before downloading anything. start-ai.ps1 starts project-local tools when present. See WORK-PC-2026-11-04.md for earlier hardware and tool setup. Voice and platform credentials stay in the office Connections screen.

## Start the production connection

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/start-hub-worker.ps1
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/register-hub-worker.ps1
```

The first command starts the connector hidden. The second registers it at Windows sign-in with restart handling. Keep the office PC awake and logged in. The connector does not turn on Daily production or Automatic publishing. Review before publishing remains enabled by default.

Generate a **Free AI preview** from the website first to check models, captions and pacing without ElevenLabs credits or publishing. Original production uses the saved paid narration connection. Approve & publish sends the finished video to the selected office destinations. Instagram uses the hosted MP4 when the dashboard connection is configured.

## Troubleshooting

Read storage/logs/hub.err.log, hub.out.log and hub-service.log on the office PC. A second connector exits instead of taking over the local lock. If the website reports a different installation binding, inspect which PC was paired; do not replace databases or clear bindings to bypass it.

To remove future sign-in startup:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/register-hub-worker.ps1 -Remove
```

Stop an already-running connection separately if needed. Do not enable production on both the laptop and office PC.
