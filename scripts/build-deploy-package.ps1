# Creates a clean deploy/ folder ready for cPanel upload
$root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$out = Join-Path $root "deploy"
$utf8NoBom = New-Object System.Text.UTF8Encoding $false

if (Test-Path $out) {
    Remove-Item -Recurse -Force $out
}
New-Item -ItemType Directory -Path $out | Out-Null

function Copy-Tree($src, $dest) {
    New-Item -ItemType Directory -Path $dest -Force | Out-Null
    Get-ChildItem -Path $src -Force | ForEach-Object {
        if ($_.Name -in @(".git", ".vscode", "dev", "deploy", "scripts", "node_modules")) { return }
        $target = Join-Path $dest $_.Name
        if ($_.PSIsContainer) {
            Copy-Tree $_.FullName $target
        } else {
            Copy-Item $_.FullName $target -Force
        }
    }
}

# Stamp asset URLs in HTML before packaging (cache busting for CSS/JS)
$stampScript = Join-Path $root "scripts\stamp-asset-version.ps1"
if (Test-Path $stampScript) {
    & $stampScript -Root $root
}

# Root frontend
foreach ($f in @("index.html", "script.js", "style.css", ".htaccess")) {
    $src = Join-Path $root $f
    if (Test-Path $src) { Copy-Item $src (Join-Path $out $f) -Force }
}

# Re-stamp deploy HTML so ?v= always matches copied CSS/JS bytes.
if (Test-Path $stampScript) {
    & $stampScript -Root $root -DeployDir $out
}

# PHP backend (exclude secrets and local-only)
Copy-Tree (Join-Path $root "php") (Join-Path $out "php")

$excludeInPhp = @(
    ".env",
    ".env.local",
    "storage\database.sqlite",
    "storage\php-errors.log"
)
foreach ($rel in $excludeInPhp) {
    $p = Join-Path $out "php\$rel"
    if (Test-Path $p) { Remove-Item $p -Force }
}

# Ensure writable storage
$storage = Join-Path $out "php\storage"
New-Item -ItemType Directory -Path $storage -Force | Out-Null
if (-not (Test-Path (Join-Path $storage ".htaccess"))) {
    Copy-Item (Join-Path $root "php\storage\.htaccess") (Join-Path $storage ".htaccess") -ErrorAction SilentlyContinue
}

# Strip BOM only from PHP files that actually have it (never rewrite clean files)
Get-ChildItem -Path $out -Recurse -Filter *.php | ForEach-Object {
    $bytes = [System.IO.File]::ReadAllBytes($_.FullName)
    if ($bytes.Length -ge 3 -and $bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF) {
        $text = [System.Text.Encoding]::UTF8.GetString($bytes, 3, $bytes.Length - 3)
        [System.IO.File]::WriteAllText($_.FullName, $text, $utf8NoBom)
        Write-Host "Stripped BOM: $($_.Name)"
    }
}

Write-Host "Deploy package ready: $out"
Write-Host ""
Write-Host "NEXT STEPS:"
Write-Host "1. Upload ALL contents of deploy/ to cPanel Document Root (hamgam folder)"
Write-Host "2. On server: ensure php/.env exists (copy from php/.env on your PC - do NOT use .env.local)"
Write-Host "3. chmod 755 or 775 on php/storage/"
Write-Host "4. Run: powershell -File scripts\test-server.ps1"
