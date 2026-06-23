# Hamgam — سرور توسعه لوکال (PHP + فایل‌های استاتیک)
$ErrorActionPreference = "Stop"
$root = $PSScriptRoot
$port = if ($env:HAMGAM_DEV_PORT) { $env:HAMGAM_DEV_PORT } else { "8765" }
$phpIni = Join-Path $root "dev\php.ini"

if (-not (Test-Path $phpIni)) {
    Write-Error "dev\php.ini not found. Run from project root."
}

Write-Host ""
Write-Host "Hamgam dev server"
Write-Host "  URL:  http://127.0.0.1:$port"
Write-Host "  Stop: Ctrl+C"
Write-Host ""
Write-Host "Important: open the URL above in your browser."
Write-Host "Do NOT use Live Server — it cannot run PHP API endpoints."
Write-Host ""

Set-Location $root
php -c $phpIni -S "127.0.0.1:$port" -t .
