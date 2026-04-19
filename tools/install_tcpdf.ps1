# ดาวน์โหลดและแตกไฟล์ TCPDF ไปที่ lib/tcpdf (สำหรับรายงาน PDF แอดมิน)
$ErrorActionPreference = "Stop"
$ver = "6.7.5"
$url = "https://github.com/tecnickcom/TCPDF/archive/refs/tags/$ver.zip"
$root = Split-Path -Parent $PSScriptRoot
$lib = Join-Path $root "lib"
New-Item -ItemType Directory -Force -Path $lib | Out-Null
$zip = Join-Path $lib "tcpdf.zip"
$tmp = Join-Path $lib "tcpdf_tmp_extract"
Write-Host "Downloading TCPDF $ver ..."
Invoke-WebRequest -Uri $url -OutFile $zip -UseBasicParsing -TimeoutSec 120
if (Test-Path $tmp) {
    Remove-Item $tmp -Recurse -Force
}
Expand-Archive -Path $zip -DestinationPath $tmp -Force
$src = Join-Path $tmp "TCPDF-$ver"
$dst = Join-Path $lib "tcpdf"
if (Test-Path $dst) {
    Remove-Item $dst -Recurse -Force
}
Move-Item -Path $src -Destination $dst -Force
Remove-Item $tmp -Recurse -Force
Remove-Item $zip -Force
Write-Host "Done. TCPDF is at: $dst"
