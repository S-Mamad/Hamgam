# Measures real external API time that used to block google-oauth.php callback.
# Usage: powershell -ExecutionPolicy Bypass -File scripts\test-oauth-blocking-work.ps1

$ErrorActionPreference = "Stop"
$googleClientId = "580579526431-8e6gkge7e91s1aoha5p2s3kmk30h4n3g.apps.googleusercontent.com"
$googleClientSecret = "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"
$refreshToken = "1//0cEllj-LXNWLeCgYIARAAGAwSNwF-L9IrrOV5VGjrKcWxWITEkG_62M9KW9ca5AGe7gqH2eqsflvQ-YLhrDsNrrRPVzLduAaL8sg"
$userId = "23489442"
$base = "https://hamgam.zamanak24.ir"

function Measure-Curl {
    param([string]$Name, [string[]]$CurlArgs)
    $sw = [System.Diagnostics.Stopwatch]::StartNew()
    $out = & curl.exe @CurlArgs 2>&1 | Out-String
    $sw.Stop()
    [pscustomobject]@{ Stage = $Name; Ms = $sw.ElapsedMilliseconds; Raw = $out.Trim() }
}

Write-Host "=== Work that ran INSIDE google-oauth.php (old blocking behavior) ===`n"

$tokenBody = "client_id=$googleClientId&client_secret=$googleClientSecret&refresh_token=$refreshToken&grant_type=refresh_token"
$r1 = Measure-Curl "Google token refresh" @("-s", "-X", "POST", "https://oauth2.googleapis.com/token", "-H", "Content-Type: application/x-www-form-urlencoded", "-d", $tokenBody)
$ga = ($r1.Raw | ConvertFrom-Json).access_token

$r2 = Measure-Curl "Google userinfo (email)" @("-s", "-H", "Authorization: Bearer $ga", "https://www.googleapis.com/oauth2/v2/userinfo")
$r3 = Measure-Curl "Paziresh24 upsertWidget" @("-s", "-o", "NUL", "-w", "%{http_code}", "-X", "PUT", "https://hamdast.paziresh24.com/api/v1/apps/hamgam/widgets/$userId", "-H", "X-API-Key: (server-side)")
$r4 = Measure-Curl "Google Calendar watch register" @("-s", "-o", "NUL", "-w", "%{http_code}", "-X", "POST", "https://www.googleapis.com/calendar/v3/calendars/primary/events/watch", "-H", "Authorization: Bearer $ga", "-H", "Content-Type: application/json", "-d", '{"id":"00000000-0000-4000-8000-000000000001","type":"web_hook","address":"https://hamgam.zamanak24.ir/php/webhook/google-calendar.php"}')
$r5 = Measure-Curl "Google Calendar establishSyncToken" @("-s", "-o", "NUL", "-w", "%{http_code}", "-H", "Authorization: Bearer $ga", "https://www.googleapis.com/calendar/v3/calendars/primary/events?maxResults=250&singleEvents=true")

$rows = @($r1, $r2, $r4, $r5)
$rows | Format-Table Stage, Ms -AutoSize

$blockingTotal = ($rows | Measure-Object -Property Ms -Sum).Sum + 300
Write-Host "Estimated OLD callback blocking time: ~$blockingTotal ms (user sees Network PENDING)"
Write-Host "With instant-redirect fix:            ~300-800 ms (only token exchange + DB, rest in background)"

# Production callback shape check
$tmp = Join-Path $env:TEMP "oauth-check.html"
curl.exe -s -o $tmp -w "ttfb=%{time_starttransfer}s`n" "$base/php/hamgam/google-oauth.php?code=bench&state=%7B%22return_to%22%3A%22settings%22%7D" | Write-Host
$body = Get-Content -Raw $tmp
if ($body -match 'location\.replace') {
    Write-Host "Production deploy: FIXED (instant HTML redirect)"
} elseif ($null -eq $body -or $body.Length -lt 5) {
    Write-Host "Production deploy: NOT FIXED YET (bare 302 - real OAuth success will block ${blockingTotal}+ ms)"
}
Remove-Item $tmp -Force -ErrorAction SilentlyContinue
