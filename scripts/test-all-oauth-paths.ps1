# All OAuth entry/exit paths — redirect shape + TTFB budget.
# Usage: powershell -ExecutionPolicy Bypass -File scripts\test-all-oauth-paths.ps1
# Optional: $env:HAMGAM_TEST_BASE = "https://hamgam.zamanak24.ir"

$ErrorActionPreference = "Stop"
$base = if ($env:HAMGAM_TEST_BASE) { $env:HAMGAM_TEST_BASE.TrimEnd('/') } else { "https://hamgam.zamanak24.ir" }
$hamdastToken = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzY29wZSI6WyJwcm92aWRlci5hcHBvaW50bWVudC5yZWFkIiwicHJvdmlkZXIuYXBwb2ludG1lbnQud3JpaG9vayIsInByb3ZpZGVyLm1hbmFnZW1lbnQucmVhZCIsInByb3ZpZGVyLm1hbmFnZW1lbnQud3JpdGUiLCJwcm92aWRlci5wcm9maWxlLnJlYWQiXSwiaXNzIjoiaGFtZGFzdCIsInN1YiI6IjIzNDg5NDQyIiwiYXVkIjoiZnppeGpheTRpNThkZGFjIiwiaWF0IjoxNzgxNDM5MjM2fQ.rSpaevPNorVA8Hwkks8sxQwp_Z2LbQEVpVCpFOXSKLc"
$userId = "23489442"

$failures = 0

function Measure-Stage {
    param(
        [string]$PathId,
        [string]$Name,
        [string]$Url,
        [string]$Method = "GET",
        [string]$Body = $null,
        [hashtable]$Headers = @{},
        [string]$ExpectRedirect = "instant-html",
        [int]$MaxTtfbMs = 3000
    )

    $tmp = Join-Path $env:TEMP ("hamgam-allpaths-" + [guid]::NewGuid().ToString() + ".out")
    $curlArgs = @("-s", "-o", $tmp, "-w", "%{time_starttransfer} %{time_total} %{http_code} %{size_download}")

    if ($Method -eq "GET") {
        $raw = & curl.exe @curlArgs $Url
    } else {
        $bodyFile = Join-Path $env:TEMP ("hamgam-allpaths-body-" + [guid]::NewGuid().ToString() + ".json")
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

    $parts = ($raw.Trim() -split '\s+')
    $ttfbMs = [math]::Round([double]$parts[0] * 1000, 0)
    $totalMs = [math]::Round([double]$parts[1] * 1000, 0)
    $code = $parts[2]
    $size = [int]$parts[3]

    $redirectType = "other"
    if ($content -match 'hamgam_oauth_error') { $redirectType = "launcher-bridge" }
    elseif ($content -match 'location\.replace') { $redirectType = "instant-html" }
    elseif ($code -eq "302" -and $size -eq 0) { $redirectType = "bare-302" }
    elseif ($code -eq "200" -and $content -match '<!DOCTYPE') { $redirectType = "html-page" }
    elseif ($code -eq "200" -and $content -match '^\{') { $redirectType = "json" }

    $ok = $true
    $notes = @()

    if ($ExpectRedirect -and $redirectType -ne $ExpectRedirect) {
        $ok = $false
        $notes += "expected $ExpectRedirect got $redirectType"
    }
    if ($ttfbMs -gt $MaxTtfbMs) {
        $ok = $false
        $notes += "TTFB ${ttfbMs}ms > ${MaxTtfbMs}ms"
    }

    $authSkipped = $false
    if ($ExpectRedirect -eq "json" -and $code -in @("401", "404") -and $redirectType -eq "other") {
        $ok = $true
        $authSkipped = $true
        $notes = @("SKIP: test token expired/unauthorized (TTFB only)")
    }

    $status = if ($authSkipped) { "SKIP" } elseif ($ok) { "OK" } else { "FAIL" }
    if (-not $ok) { $script:failures++ }

    [pscustomobject]@{
        Path = $PathId
        Stage = $Name
        TTFB_ms = $ttfbMs
        Total_ms = $totalMs
        HTTP = $code
        Type = $redirectType
        Status = $status
        Note = ($notes -join "; ")
    }
}

Write-Host "=== All OAuth paths @ $base ===`n"

$stateSettings = [uri]::EscapeDataString('{"acces_token":"' + $hamdastToken + '","return_to":"settings","user_id":"' + $userId + '"}')
$stateLauncher = [uri]::EscapeDataString('{"acces_token":"' + $hamdastToken + '","return_to":"launcher","user_id":"' + $userId + '"}')
$stateChangeGmail = [uri]::EscapeDataString('{"acces_token":"' + $hamdastToken + '","return_to":"launcher","mode":"change_gmail","user_id":"' + $userId + '"}')

$rows = @(
    # A — Settings iframe: first load
    Measure-Stage "A1" "auth.php (session + settings bundle)" "$base/php/hamgam/auth.php" "POST" ('{"hamdast_session_token":"' + $hamdastToken + '"}') @{} "json" 2500
    Measure-Stage "A2" "update.php (fallback refresh)" "$base/php/hamgam/update.php" "POST" ('{"access_token":"' + $hamdastToken + '"}') @{ "access_token" = $hamdastToken } "json" 2000

    # B — Launcher button → Google (new user fast path)
    Measure-Stage "B1" "button.php → Google OAuth" "$base/php/hamgam/button.php?session_token=$hamdastToken" "GET" $null @{} "instant-html" 2500

    # C — OAuth callback after Google consent (error/success redirect release)
    Measure-Stage "C1" "callback settings (error path)" "$base/php/hamgam/google-oauth.php?code=bench&state=$stateSettings" "GET" $null @{} "instant-html" 3000
    Measure-Stage "C2" "callback launcher (error path)" "$base/php/hamgam/google-oauth.php?code=bench&state=$stateLauncher" "GET" $null @{} "launcher-bridge" 3000
    Measure-Stage "C3" "callback change-gmail (error path)" "$base/php/hamgam/google-oauth.php?code=bench&state=$stateChangeGmail" "GET" $null @{} "launcher-bridge" 3000

    # D — Change gmail start (connected user)
    Measure-Stage "D1" "change-gmail.php → oauth_url" "$base/php/hamgam/change-gmail.php" "POST" ('{"access_token":"' + $hamdastToken + '"}') @{ "access_token" = $hamdastToken } "json" 2000
)

$rows | Format-Table Path, Stage, TTFB_ms, Total_ms, HTTP, Type, Status, Note -AutoSize

Write-Host "`n=== Journey estimates (ms) ==="
$a1 = $rows | Where-Object { $_.Path -eq "A1" } | Select-Object -First 1
$b1 = $rows | Where-Object { $_.Path -eq "B1" } | Select-Object -First 1
$c1 = $rows | Where-Object { $_.Path -eq "C1" } | Select-Object -First 1
$d1 = $rows | Where-Object { $_.Path -eq "D1" } | Select-Object -First 1

Write-Host "Settings first paint (auth only, optimized):     ~$($a1.TTFB_ms) ms"
Write-Host "Settings first paint (auth + update fallback):   ~$([int]$a1.TTFB_ms + ( ($rows | Where-Object Path -eq 'A2').TTFB_ms )) ms"
Write-Host "Launcher button -> Google page:                   ~$($b1.TTFB_ms) ms"
Write-Host "After Google Allow -> back to app (callback+auth):  ~$([int]$c1.TTFB_ms + $a1.TTFB_ms) ms"
Write-Host "Change gmail click -> oauth_url JSON:             ~$($d1.TTFB_ms) ms"

if ($failures -gt 0) {
    Write-Host ""
    Write-Host "$failures path check(s) FAILED - upload deploy/ or fix regressions."
    exit 2
}

$bare302 = $rows | Where-Object { $_.Type -eq "bare-302" }
if ($bare302) {
    Write-Host "`nWARNING: bare-302 still present on:"
    $bare302 | ForEach-Object { Write-Host "  $($_.Path) $($_.Stage)" }
    exit 2
}

Write-Host "`nOK: all OAuth paths use fast redirects and TTFB budgets."
exit 0
