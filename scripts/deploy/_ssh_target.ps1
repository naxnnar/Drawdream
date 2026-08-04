# Shared SSH target helper for deploy scripts
param(
    [string]$ServerIp = "82.26.104.99",
    [string]$User = "root",
    [string]$SshHost = ""
)

if ($SshHost -ne "") {
    $script:SshTarget = $SshHost
} else {
    $script:SshTarget = "${User}@${ServerIp}"
}

function Invoke-SshCommand {
    param([string]$Command)
    & ssh $script:SshTarget $Command
    if ($LASTEXITCODE -ne 0) {
        throw "ssh failed: $Command"
    }
}

function Copy-FileToServer {
    param(
        [string]$LocalPath,
        [string]$RemotePath
    )
    & scp $LocalPath "${script:SshTarget}:${RemotePath}"
    if ($LASTEXITCODE -ne 0) {
        throw "scp failed: $LocalPath"
    }
}

function Sync-FileListToServer {
    param(
        [string]$Root,
        [string[]]$Files,
        [string]$RemoteApp
    )
    $staging = Join-Path $env:TEMP ("drawdream-sync-" + [guid]::NewGuid().ToString("n"))
    New-Item -ItemType Directory -Path $staging -Force | Out-Null
    try {
        foreach ($rel in $Files) {
            $local = Join-Path $Root $rel
            if (-not (Test-Path $local)) {
                throw "Missing local file: $rel"
            }
            $dest = Join-Path $staging $rel
            $destDir = Split-Path $dest -Parent
            if (-not (Test-Path $destDir)) {
                New-Item -ItemType Directory -Path $destDir -Force | Out-Null
            }
            Copy-Item $local $dest -Force
        }
        $tarPath = Join-Path $env:TEMP ("drawdream-sync-" + [guid]::NewGuid().ToString("n") + ".tar.gz")
        Push-Location $staging
        tar -czf $tarPath .
        Pop-Location
        Copy-FileToServer -LocalPath $tarPath -RemotePath "/tmp/drawdream-partial.tar.gz"
        $extractCmd = 'bash -lc ''mkdir -p "' + $RemoteApp + '"; tar -xzf /tmp/drawdream-partial.tar.gz -C "' + $RemoteApp + '"; rm -f /tmp/drawdream-partial.tar.gz'''
        Invoke-SshCommand $extractCmd
        Remove-Item $tarPath -Force -ErrorAction SilentlyContinue
    } finally {
        Remove-Item $staging -Recurse -Force -ErrorAction SilentlyContinue
    }
}

function Invoke-DrawdreamPostDeploy {
    param([string]$RemoteApp = "/var/www/drawdream")
    $inner = 'cd "' + $RemoteApp + '" && php tools/run_migrations.php && php tools/audit_schema.php --strict && php tools/e2e/run_all.php --static && systemctl reload php8.1-fpm 2>/dev/null; systemctl reload php8.2-fpm 2>/dev/null; systemctl reload nginx 2>/dev/null; true'
    Invoke-SshCommand "bash -lc '$inner'"
}
