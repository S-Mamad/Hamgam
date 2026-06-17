# Measures user-perceived timing across the Google OAuth onboarding flow.
# Usage: powershell -ExecutionPolicy Bypass -File scripts\test-oauth-flow-timing.ps1
# Optional: $env:HAMGAM_TEST_BASE = "http://127.0.0.1:8765"

$ErrorActionPreference = "Stop"
$base = if ($env:HAMGAM_TEST_BASE) { $env:HAMGAM_TEST_BASE.TrimEnd('/') } else { "https://hamgam.zamanak24.ir" }
$hamdastToken = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzY29wZSI6WyJwcm92aWRlci5hcHBvaW50bWVudC5yZWFkIiwicHJvdmlkZXIuYXBwb2ludG1lbnQud2ViaG9vayIsInByb3ZpZGVyLm1hbmFnZW1lbnQucmVhZCIsInByb3ZpZGVyLm1hbmFnZW1lbnQud3JpdGUiLCJwcm92aWRlci5wcm9maWxlLnJlYWQiXSwiaXNzIjoiaGFtZGFzdCIsInN1YiI6IjIzNDg5NDQyIiwiYXVkIjoiZnppeGpheTRpNThkZGFjIiwiaWF0IjoxNzgxNDM5MjM2fQ.rSpaevPNorVA8Hwkks8sxQwp_Z2LbQEVpVCpFOXSKLc"

function Measure-Request {
    param(
        [string]$Name,
        [string]$Url,
        [string]$Method = "GET",
        [string]$Body = $null,
        [hashtable]$Headers = @{}
    )

    $tmp = Join-Path $env:TEMP ("hamgam-timing-" + [guid]::NewGuid().ToString() + ".out")
    $curlArgs = @("-s", "-o", $tmp, "-w", "%{time_namelookup} %{time_connect} %{time_starttransfer} %{time_total} %{http_code} %{size_download}")

    if ($Method -eq "GET") {
        $raw = & curl.exe @curlArgs $Url
    } else {
        $bodyFile = Join-Path $env:TEMP ("hamgam-body-" + [guid]::NewGuid().ToString() + ".json")
        [IO.File]::WriteAllText($bodyFile, $Body)
        $curlHeaders = @("-H", "Content-Type: application/json")
        foreach ($k in $Headers.Keys) {
            $curlHeaders += @("-H", "$k`:$($Headers[$k])")
        }
        $raw = & curl.exe @curlArgs -X POST $Url @curlHeaders --data-binary "@$bodyFile"
        Remove-Item $bodyFile -Force -ErrorAction SilentlyContinue
    }

    $content = ""
    if (Test-Path $tmp) {
        $content = Get-Content -Raw $tmp
        if ($null -eq $content) { $content = "" }
    }
    Remove-Item $tmp -Force -ErrorAction SilentlyContinue

    $line = ($raw -split "`n" | Where-Object { $_ -match '^\d' } | Select-Object -Last 1)
    if (-not $line) { $line = $raw.Trim() }
    $parts = $line.Trim() -split '\s+'
    if ($parts.Count -lt 6) {
        throw "Unexpected curl output for $Name`: $raw"
    }

    $ttfbMs = [math]::Round([double]$parts[2] * 1000, 0)
    $totalMs = [math]::Round([double]$parts[3] * 1000, 0)
    $code = $parts[4]
    $size = $parts[5]

    $redirectType = "unknown"
    if ($content -match 'location\.replace') { $redirectType = "instant-html (fixed)" }
    elseif ($code -eq "302" -and [int]$size -eq 0) { $redirectType = "bare-302 (old/slow risk)" }
    elseif ($code -eq "200" -and $content -match '<!DOCTYPE') { $redirectType = "html-page" }

    [pscustomobject]@{
        Stage = $Name
        TTFB_ms = $ttfbMs
        Total_ms = $totalMs
        HTTP = $code
        Bytes = $size
        Redirect = $redirectType
        Preview = if ($content.Length -gt 0) {
            ($content.Substring(0, [Math]::Min(90, $content.Length)) -replace "`r?`n", " ")
        } else { "" }
    }
}

Write-Host "=== Hamgam OAuth flow timing ==="
Write-Host "Base: $base`n"

$jwtStateSettings = [uri]::EscapeDataString('{"acces_token":"' + $hamdastToken + '","return_to":"settings","user_id":"23489442"}')
$jwtStateLauncher = [uri]::EscapeDataString('{"acces_token":"' + $hamdastToken + '","return_to":"launcher","user_id":"23489442"}')
$oauthCallbackSettings = "$base/php/hamgam/google-oauth.php?code=invalid_timing_test&state=$jwtStateSettings"
$oauthCallbackLauncher = "$base/php/hamgam/google-oauth.php?code=invalid_timing_test&state=$jwtStateLauncher"

$results = @(
    Measure-Request "1) App shell (index.html)" "$base/"
    Measure-Request "2) auth.php (session exchange)" "$base/php/hamgam/auth.php" "POST" ('{"hamdast_session_token":"' + $hamdastToken + '"}')
    Measure-Request "3) update.php (settings load)" "$base/php/hamgam/update.php" "POST" ('{"access_token":"' + $hamdastToken + '"}')
    Measure-Request "4a) OAuth callback (settings return)" $oauthCallbackSettings
    Measure-Request "4b) OAuth callback (launcher return)" $oauthCallbackLauncher
    Measure-Request "5) script.js asset" "$base/script.js"
    Measure-Request "6) button.php (launcher entry)" "$base/php/hamgam/button.php?session_token=$hamdastToken"
)

$results | Format-Table Stage, TTFB_ms, Total_ms, HTTP, Bytes, Redirect -AutoSize

Write-Host "`n--- Stage details ---"
foreach ($r in $results) {
    if ($r.Preview) {
        Write-Host "$($r.Stage): $($r.Preview)"
    }
}

$callback = $results | Where-Object { $_.Stage -like "*launcher return*" } | Select-Object -First 1
if (-not $callback) {
    $callback = $results | Where-Object { $_.Stage -like "*settings return*" } | Select-Object -First 1
}
$auth = $results | Where-Object { $_.Stage -like "*auth.php*" } | Select-Object -First 1
$update = $results | Where-Object { $_.Stage -like "*update.php*" } | Select-Object -First 1

Write-Host "`n=== User journey estimate (after Google consent button) ==="
$postOAuthMs = [int]$auth.TTFB_ms + [int]$update.TTFB_ms + 300
Write-Host "OAuth callback (browser pending): $($callback.TTFB_ms) ms  [$($callback.Redirect)]"
Write-Host "Return to app auth.php:           $($auth.TTFB_ms) ms"
Write-Host "Return to app update.php:         $($update.TTFB_ms) ms"
Write-Host "Frontend render (estimate):       ~300 ms"
Write-Host "-------------------------------------------"
Write-Host "Total after last Google click:    ~$([int]$callback.TTFB_ms + $postOAuthMs) ms"

if ($callback.Redirect -eq "bare-302 (old/slow risk)") {
    Write-Host "`nWARNING: OAuth callback still uses bare 302."
    Write-Host "         Upload deploy/ folder to apply instant-redirect fixes."
    exit 2
}

$settingsCallback = $results | Where-Object { $_.Stage -like "*settings return*" } | Select-Object -First 1
if ($settingsCallback.Redirect -eq "bare-302 (old/slow risk)") {
    Write-Host "`nWARNING: Settings OAuth callback still uses bare 302."
    exit 2
}

$button = $results | Where-Object { $_.Stage -like "*button.php*" } | Select-Object -First 1
if ($button.Redirect -eq "bare-302 (old/slow risk)") {
    Write-Host "`nWARNING: button.php still uses bare 302 before Google OAuth."
    exit 2
}

if ([int]$callback.TTFB_ms -gt 3000) {
    Write-Host "`nWARNING: OAuth callback TTFB > 3s even on error path."
    exit 2
}

Write-Host "`nOK: OAuth callback releases browser quickly."
exit 0
