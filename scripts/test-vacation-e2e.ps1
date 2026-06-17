# Vacation E2E test via curl.exe
$ErrorActionPreference = "Stop"
$base = "https://hamgam.zamanak24.ir"
$apiKey = "8d25918d-0d58-41be-80ba-ef5e8a4a29c9"
$userId = "23489442"
$channelId = "cd72a37d-f3b1-4e54-9ab7-2fde0e368e66"
$resourceId = "91ct49E2u4547MM7KFflNZ72EpI"
$refreshToken = "1//0cEllj-LXNWLeCgYIARAAGAwSNwF-L9IrrOV5VGjrKcWxWITEkG_62M9KW9ca5AGe7gqH2eqsflvQ-YLhrDsNrrRPVzLduAaL8sg"
$hamdastToken = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzY29wZSI6WyJwcm92aWRlci5hcHBvaW50bWVudC5yZWFkIiwicHJvdmlkZXIuYXBwb2ludG1lbnQud2ViaG9vayIsInByb3ZpZGVyLm1hbmFnZW1lbnQucmVhZCIsInByb3ZpZGVyLm1hbmFnZW1lbnQud3JpdGUiLCJwcm92aWRlci5wcm9maWxlLnJlYWQiXSwiaXNzIjoiaGFtZGFzdCIsInN1YiI6IjIzNDg5NDQyIiwiYXVkIjoiZnppeGpheTRpNThkZGFjIiwiaWF0IjoxNzgxNDM5MjM2fQ.rSpaevPNorVA8Hwkks8sxQwp_Z2LbQEVpVCfFOXSKLc"
$centerId = "e5d0fa25-a8e1-40db-a957-97aa0af1c0ee"
$googleClientId = "580579526431-8e6gkge7e91s1aoha5p2s3kmk30h4n3g.apps.googleusercontent.com"
$googleClientSecret = "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"

function Invoke-CurlJson {
    param(
        [string]$Method,
        [string]$Url,
        [string[]]$Headers = @(),
        [string]$Body = $null
    )
    $tmp = $null
    $args = @("-s", "-w", "`n%{http_code}", "-X", $Method, $Url)
    foreach ($h in $Headers) { $args += @("-H", $h) }
    if ($null -ne $Body) {
        $tmp = Join-Path $env:TEMP ("hamgam-vac-" + [guid]::NewGuid().ToString() + ".json")
        $utf8 = New-Object System.Text.UTF8Encoding $false
        [System.IO.File]::WriteAllText($tmp, $Body, $utf8)
        $args += @("-H", "Content-Type: application/json", "--data-binary", "@$tmp")
    }
    $raw = & curl.exe @args
    if ($tmp -and (Test-Path $tmp)) { Remove-Item $tmp -Force }
    $lines = $raw -split "`n"
    $code = [int]($lines[-1].Trim())
    $text = ($lines[0..($lines.Length - 2)] -join "`n").Trim()
    $json = $null
    try { $json = $text | ConvertFrom-Json } catch {}
    return @{ code = $code; text = $text; json = $json }
}

Write-Host "Step 1: Refresh Google token"
$tokenBody = "client_id=$googleClientId&client_secret=$googleClientSecret&refresh_token=$refreshToken&grant_type=refresh_token"
$tokenRaw = & curl.exe -s -w "`n%{http_code}" -X POST "https://oauth2.googleapis.com/token" -H "Content-Type: application/x-www-form-urlencoded" -d $tokenBody
$tokenLines = $tokenRaw -split "`n"
$tokenCode = [int]($tokenLines[-1].Trim())
$tokenText = ($tokenLines[0..($tokenLines.Length - 2)] -join "`n").Trim()
if ($tokenCode -ge 400) { Write-Host "FAIL token refresh: $tokenText"; exit 1 }
$tokenJson = $tokenText | ConvertFrom-Json
$googleAccess = $tokenJson.access_token
Write-Host "OK Google access token"

Write-Host "Step 2: List month calendar"
$monthStart = (Get-Date).Date.AddDays(-((Get-Date).Day - 1))
$monthEnd = $monthStart.AddMonths(1).AddSeconds(-1)
$timeMin = $monthStart.ToUniversalTime().ToString("yyyy-MM-ddTHH:mm:ssZ")
$timeMax = $monthEnd.ToUniversalTime().ToString("yyyy-MM-ddTHH:mm:ssZ")
$listUrl = "https://www.googleapis.com/calendar/v3/calendars/primary/events?singleEvents=true&orderBy=startTime&timeMin=$([uri]::EscapeDataString($timeMin))&timeMax=$([uri]::EscapeDataString($timeMax))&maxResults=50"
$listResp = Invoke-CurlJson -Method GET -Url $listUrl -Headers @("Authorization: Bearer $googleAccess")
$eventCount = 0
if ($listResp.json.items) { $eventCount = $listResp.json.items.Count }
Write-Host "OK month events: $eventCount"

Write-Host "Step 3: Create test event"
$tomorrow = (Get-Date).Date.AddDays(1).AddHours(15)
$tomorrowEnd = $tomorrow.AddMinutes(90)
$stamp = Get-Date -Format "yyyy-MM-dd HH:mm"
$summary = "Hamgam vacation test $stamp"
$eventObj = @{
    summary = $summary
    description = "E2E test"
    start = @{ dateTime = $tomorrow.ToString("yyyy-MM-ddTHH:mm:ss"); timeZone = "Asia/Tehran" }
    end = @{ dateTime = $tomorrowEnd.ToString("yyyy-MM-ddTHH:mm:ss"); timeZone = "Asia/Tehran" }
}
$eventJson = $eventObj | ConvertTo-Json -Depth 5 -Compress
$createResp = Invoke-CurlJson -Method POST -Url "https://www.googleapis.com/calendar/v3/calendars/primary/events" -Headers @("Authorization: Bearer $googleAccess") -Body $eventJson
if ($createResp.code -ge 400) { Write-Host "FAIL create event: $($createResp.text)"; exit 1 }
$eventId = $createResp.json.id
Write-Host "OK created event $eventId at $($tomorrow.ToString('yyyy-MM-dd HH:mm'))"

Start-Sleep -Seconds 2

Write-Host "Step 4: Trigger production webhook"
$wh = & curl.exe -s -w "`n%{http_code}" -X POST "$base/php/webhook/google-calendar.php" -H "X-Goog-Channel-ID: $channelId" -H "X-Goog-Resource-ID: $resourceId" -H "X-Goog-Resource-State: exists"
$whLines = $wh -split "`n"
Write-Host "Webhook HTTP $($whLines[-1].Trim()) body $($whLines[0].Trim())"

Start-Sleep -Seconds 4

Write-Host "Step 5: Check server tracked vacations"
$statusUrl = '{0}/php/tools/test-vacation-e2e.php?action=status&user_id={1}&key={2}' -f $base, $userId, $apiKey
$statusResp = Invoke-CurlJson -Method GET -Url $statusUrl
Write-Host "Status HTTP $($statusResp.code)"

$isHtml = $statusResp.text -like '*DOCTYPE*' -or $statusResp.text -like '*html*'
if ($statusResp.code -eq 404 -or $isHtml) {
    Write-Host "Status endpoint not deployed - testing Paziresh24 API directly"
    $iranTz = [TimeZoneInfo]::FindSystemTimeZoneById("Iran Standard Time")
    $fromTs = [int][DateTimeOffset]::new($tomorrow, $iranTz.GetUtcOffset($tomorrow)).ToUnixTimeSeconds()
    $toTs = [int][DateTimeOffset]::new($tomorrowEnd, $iranTz.GetUtcOffset($tomorrowEnd)).ToUnixTimeSeconds()
    $vacBody = "{`"from`":$fromTs,`"to`":$toTs}"
    $vacResp = Invoke-CurlJson -Method POST -Url "https://apigw.paziresh24.com/open-platform/v1/booking/vacations/$centerId" -Headers @("Authorization: Bearer $hamdastToken") -Body $vacBody
    Write-Host "Vacation create HTTP $($vacResp.code) $($vacResp.text)"
    if ($vacResp.code -ge 200 -and $vacResp.code -lt 300) {
        $null = Invoke-CurlJson -Method DELETE -Url "https://apigw.paziresh24.com/open-platform/v1/booking/vacations/$centerId" -Headers @("Authorization: Bearer $hamdastToken") -Body $vacBody
        $null = Invoke-CurlJson -Method DELETE -Url "https://www.googleapis.com/calendar/v3/calendars/primary/events/$([uri]::EscapeDataString($eventId))" -Headers @("Authorization: Bearer $googleAccess")
        Write-Host "PARTIAL PASS: APIs work; deploy test-vacation-e2e.php for full webhook verification"
        exit 0
    }
    exit 1
}

$tracked = $false
if ($statusResp.json.tracked_vacations) {
    foreach ($row in $statusResp.json.tracked_vacations) {
        if ($row.google_event_id -eq $eventId) {
            $tracked = $true
            Write-Host "Tracked row: $($row | ConvertTo-Json -Compress)"
        }
    }
}

if ($tracked) {
    Write-Host "TEST PASSED"
    exit 0
}

Write-Host "TEST FAILED - event not tracked"
Write-Host $statusResp.text
exit 1
