# Continue on your home laptop

The GitHub repository contains the application code. Your API keys, OAuth tokens, encryption keys, saved settings, video library and large AI installations are deliberately excluded. A clone by itself starts with empty connections.

## Before leaving this PC

Copy **both** Desktop files privately to your home laptop:

- `FACT-Studio-transfer-2026-11-03.factbackup`
- `FACT-Studio-transfer-2026-11-03.factbackup.recovery-key.txt`

The first file is an AES-256-GCM encrypted snapshot of your database, credential encryption key and video library. The second file unlocks it. Together they grant access to the saved connections. Keep both out of GitHub and public shares. The repository ignores these filenames as an extra guard.

## At home

Install Git and XAMPP (PHP 8.0+). For full local AI production, also install Python 3.13 and have a compatible GPU. The office PC has a GTX 1660 Ti; performance and model support on your laptop depend on its hardware. The dashboard and connections can be restored without installing the AI models immediately.

```powershell
git clone https://github.com/nixthephdev/factstudio.git C:\xampp\htdocs\factstudio
cd C:\xampp\htdocs\factstudio
$env:Path = 'C:\xampp\php;' + $env:Path

# Set up the renderer. Add -InstallAI to download local models too.
powershell -ExecutionPolicy Bypass -File scripts/setup-home.ps1

# Use the actual location where you copied the TWO private transfer files.
php bin/transfer.php import "C:\private\FACT-Studio-transfer-2026-11-03.factbackup" "C:\private\FACT-Studio-transfer-2026-11-03.factbackup.recovery-key.txt"

powershell -ExecutionPolicy Bypass -File scripts/start-studio.ps1
```

Open **http://localhost:8085/**. Keep this address and port so the existing Google OAuth callback remains valid. The home PC must use a fresh clone; the restore command refuses to overwrite an existing studio with configured settings or videos.

To install the AI services after restoring:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/setup-home.ps1 -InstallAI
powershell -ExecutionPolicy Bypass -File scripts/start-ai.ps1
```

Local binaries and Python environments must be installed for the new PC; do not copy the office Python virtual environment as a replacement. Model weights can be downloaded again by the installers.

## Will YouTube, Facebook and ElevenLabs stay connected?

**With the private backup restored, the same credentials and settings are available.** A repository clone alone does not include them. YouTube's refresh token and the encryption key are restored together; tokens must still be valid and authorized. Google OAuth Testing grants can expire after seven days, and Meta tokens may expire or be revoked. A transfer does not extend their lifetime. If a provider rejects the restored credentials, reconnect or replace that provider's token in Connections.

Restore keeps Instagram disabled, clears office-specific renderer paths and disables daily production and automatic publishing on the new machine. Review your destinations, then enable the automation you want. Register a new Windows task on the laptop if you want scheduled runs:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/register-schedule.ps1
```

Run scheduled production on one PC at a time to avoid competing queues and duplicate posts.

## Make another backup later

Wait for the worker to finish, then choose a new output filename:

```powershell
php bin/transfer.php export "C:\private\fact-studio-new.factbackup"
```

Copy the resulting backup and its matching `.recovery-key.txt` file privately. Source changes go through Git as usual; connection backups are separate.
