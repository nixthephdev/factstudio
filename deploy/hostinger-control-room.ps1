param([string]$IdentityFile = (Join-Path $env:USERPROFILE '.ssh/hostinger_syndicate'))
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$sshArgs = @('-p','65002','-i',$IdentityFile,'-o','BatchMode=yes','-o','ConnectTimeout=15','-o','StrictHostKeyChecking=yes','u758889313@145.79.14.41')
$scpArgs = @('-P','65002','-i',$IdentityFile,'-o','BatchMode=yes','-o','ConnectTimeout=15','-o','StrictHostKeyChecking=yes')
$archive = Join-Path $projectRoot 'storage/deploy/fact-control-room.tar'
$environment = Join-Path $projectRoot 'storage/deploy/hosted-production.env'
if (!(Test-Path -LiteralPath $archive) -or !(Test-Path -LiteralPath $environment)) { throw 'Prepare the private deployment files and build the code archive first.' }
$entries = & tar -tf $archive
if ($LASTEXITCODE -ne 0) { throw 'Cannot inspect deployment archive.' }
if ($entries | Where-Object { $_ -match '^\./(\.env$|node_modules/|storage/|database/.*\.sqlite)' }) { throw 'Deployment archive contains private or local runtime files.' }
$prepare = @'
set -eu
app="$HOME/domains/fulodental.com/fact-studio"
if [ -e "$app" ] && [ ! -f "$app/.fact-studio-control-room" ]; then
    echo 'The private deployment directory is already occupied. Stopped.' >&2
    exit 1
fi
mkdir -p "$app"
chmod 700 "$app"
touch "$app/.fact-studio-control-room"
if [ -f "$app/.env" ]; then printf 'existing'; else printf 'new'; fi
'@
$mode = $prepare | & node (Join-Path $PSScriptRoot 'ssh-input.cjs') @sshArgs 'bash -s'
if ($LASTEXITCODE -ne 0) { throw 'Remote directory check failed.' }
if ($mode.Trim() -eq 'new') {
    & scp @scpArgs $environment 'u758889313@145.79.14.41:domains/fulodental.com/fact-studio/.env'
    if ($LASTEXITCODE -ne 0) { throw 'Private environment upload failed.' }
}
& scp @scpArgs $archive 'u758889313@145.79.14.41:domains/fulodental.com/fact-control-room.tar'
if ($LASTEXITCODE -ne 0) { throw 'Dashboard archive upload failed.' }
Get-Content -LiteralPath (Join-Path $PSScriptRoot 'hostinger-control-room.sh') -Raw | & node (Join-Path $PSScriptRoot 'ssh-input.cjs') @sshArgs 'bash -s'
if ($LASTEXITCODE -ne 0) { throw 'Dashboard installation failed.' }
