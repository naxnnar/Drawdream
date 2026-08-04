# Setup SSH key once — no password on every deploy file
# Usage: .\scripts\deploy\setup-ssh-key.ps1
# Enter root password only once when copying the public key.

param(
    [string]$ServerIp = "82.26.104.99",
    [string]$User = "root",
    [string]$HostAlias = "drawdream-vps"
)

$ErrorActionPreference = "Stop"
$sshDir = Join-Path $env:USERPROFILE ".ssh"
$keyPath = Join-Path $sshDir "id_ed25519"
$pubPath = "$keyPath.pub"
$configPath = Join-Path $sshDir "config"

if (-not (Test-Path $sshDir)) {
    New-Item -ItemType Directory -Path $sshDir -Force | Out-Null
}

if (-not (Test-Path $keyPath)) {
    Write-Host "==> Creating SSH key (ed25519)..." -ForegroundColor Cyan
    & ssh-keygen -t ed25519 -f $keyPath -N '""' -C "drawdream-deploy"
    if ($LASTEXITCODE -ne 0) {
        Write-Host "ssh-keygen failed" -ForegroundColor Red
        exit 1
    }
} else {
    Write-Host "==> Key already exists: $keyPath" -ForegroundColor DarkGray
}

$target = "${User}@${ServerIp}"

Write-Host ""
Write-Host '==> Upload public key to server (enter root password once)...' -ForegroundColor Cyan
& scp $pubPath "${target}:/tmp/drawdream_key.pub"
if ($LASTEXITCODE -ne 0) {
    Write-Host "scp failed" -ForegroundColor Red
    exit 1
}

$remoteInstall = 'mkdir -p ~/.ssh; chmod 700 ~/.ssh; touch ~/.ssh/authorized_keys; chmod 600 ~/.ssh/authorized_keys; grep -qxFf /tmp/drawdream_key.pub ~/.ssh/authorized_keys; if [ $? -ne 0 ]; then cat /tmp/drawdream_key.pub >> ~/.ssh/authorized_keys; fi; rm -f /tmp/drawdream_key.pub'

& ssh $target $remoteInstall
if ($LASTEXITCODE -ne 0) {
    Write-Host "Failed to install key on server" -ForegroundColor Red
    exit 1
}

Write-Host "==> Testing passwordless login..." -ForegroundColor Cyan
& ssh -i $keyPath -o BatchMode=yes -o ConnectTimeout=10 $target "echo SSH_KEY_OK"
if ($LASTEXITCODE -ne 0) {
    Write-Host "Passwordless login failed" -ForegroundColor Red
    exit 1
}

$block = @"

Host $HostAlias
    HostName $ServerIp
    User $User
    IdentityFile ~/.ssh/id_ed25519
    IdentitiesOnly yes
    ServerAliveInterval 30
"@

$configText = ""
if (Test-Path $configPath) {
    $configText = Get-Content $configPath -Raw
}
$aliasPattern = "Host\s+" + [regex]::Escape($HostAlias) + "\s"
if ($configText -notmatch $aliasPattern) {
    Add-Content -Path $configPath -Value $block -Encoding utf8
    Write-Host "==> Added alias '$HostAlias' to $configPath" -ForegroundColor Green
} else {
    Write-Host "==> Alias '$HostAlias' already in config" -ForegroundColor DarkGray
}

Write-Host ""
Write-Host "Done. Deploy with:" -ForegroundColor Green
Write-Host "  .\scripts\deploy\sync-needlist-ux.ps1 -SshHost $HostAlias" -ForegroundColor DarkGray
Write-Host "  .\scripts\deploy\sync-code.ps1 -SshHost $HostAlias" -ForegroundColor DarkGray
