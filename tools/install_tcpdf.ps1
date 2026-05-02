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

# ไม่ใช้โฟลเดอร์ตัวอย่างใน production — เก็บเฉพาะรูปว่างที่ TCPDF ต้องใช้
$exImages = Join-Path $dst "examples\images\_blank.png"
$imgDir = Join-Path $dst "images"
if (Test-Path $exImages) {
    New-Item -ItemType Directory -Force -Path $imgDir | Out-Null
    Copy-Item -Path $exImages -Destination (Join-Path $imgDir "_blank.png") -Force
}
$ex = Join-Path $dst "examples"
if (Test-Path $ex) {
    Remove-Item $ex -Recurse -Force
}

# ตัดส่วนที่ runtime ไม่ต้องใช้ (ลดขนาด deploy)
$toolsDir = Join-Path $dst "tools"
if (Test-Path $toolsDir) {
    Remove-Item $toolsDir -Recurse -Force
}
$chg = Join-Path $dst "CHANGELOG.TXT"
if (Test-Path $chg) { Remove-Item $chg -Force }
$rm = Join-Path $dst "README.md"
if (Test-Path $rm) { Remove-Item $rm -Force }

Write-Host "Done. TCPDF is at: $dst"
