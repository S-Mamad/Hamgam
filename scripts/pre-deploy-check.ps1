# Pre-deploy validation for Hamgam

$root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)

$errors = @()

$warnings = @()



Get-ChildItem -Path $root -Recurse -Filter *.php | ForEach-Object {

    $bytes = [System.IO.File]::ReadAllBytes($_.FullName)

    if ($bytes.Length -lt 5) {

        $errors += "Empty or too short: $($_.FullName)"

        return

    }

    if ($bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF) {

        $errors += "UTF-8 BOM detected: $($_.FullName)"

    }

    if ($bytes[0] -ne 0x3C) {

        $errors += "Bad PHP start byte: $($_.FullName)"

    }

}



$envPath = Join-Path $root "php\.env"

if (-not (Test-Path $envPath)) {

    $errors += "Missing php/.env"

}



$requiredEnvKeys = @("HAMDAST_API_KEY", "HTTP_SSL_VERIFY", "DB_DRIVER", "GOOGLE_OAUTH_CALLBACK_URI")

if (Test-Path $envPath) {

    $envText = Get-Content -Raw $envPath

    foreach ($key in $requiredEnvKeys) {

        if ($envText -notmatch "(?m)^$key=") {

            $errors += "Missing .env key: $key"

        }

    }

    if ($envText -match "(?m)^APP_DEBUG=true\s*$") {

        $warnings += "APP_DEBUG=true in php/.env (should be false on production)"

    }

}



$engineeringDoc = Join-Path $root "php\docs\ENGINEERING.md"

if (-not (Test-Path $engineeringDoc)) {

    $errors += "Missing php/docs/ENGINEERING.md"

}



$criticalFiles = @(

    "php\includes\WebhookVerifier.php",

    "php\hamgam\health.php",

    "php\cron\renew-google-watches.php"

)

foreach ($rel in $criticalFiles) {

    $full = Join-Path $root $rel

    if (-not (Test-Path $full)) {

        $errors += "Missing critical file: $rel"

    }

}



Write-Host "=== Hamgam pre-deploy check ==="

if ($warnings.Count -gt 0) {

    foreach ($w in $warnings) {

        Write-Host "WARN: $w"

    }

}



if ($errors.Count -eq 0) {

    Write-Host "OK - all PHP files clean, .env keys present."

    Write-Host "Upload: php/hamgam/, php/includes/, script.js, index.html"

    exit 0

}



foreach ($e in $errors) {

    Write-Host "FAIL: $e"

}

exit 1

