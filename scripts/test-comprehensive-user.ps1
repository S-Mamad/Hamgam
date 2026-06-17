# Comprehensive production test for user 23489442
$ErrorActionPreference = "Stop"
$base = "https://hamgam.zamanak24.ir"
$googleClientId = "580579526431-8e6gkge7e91s1aoha5p2s3kmk30h4n3g.apps.googleusercontent.com"
$googleClientSecret = "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"
$refreshToken = "1//0cEllj-LXNWLeCgYIARAAGAwSNwF-L9IrrOV5VGjrKcWxWITEkG_62M9KW9ca5AGe7gqH2eqsflvQ-YLhrDsNrrRPVzLduAaL8sg"
$hamdastToken = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzY29wZSI6WyJwcm92aWRlci5hcHBvaW50bWVudC5yZWFkIiwicHJvdmlkZXIuYXBwb2ludG1lbnQud2ViaG9vayIsInByb3ZpZGVyLm1hbmFnZW1lbnQucmVhZCIsInByb3ZpZGVyLm1hbmFnZW1lbnQud3JpdGUiLCJwcm92aWRlci5wcm9maWxlLnJlYWQiXSwiaXNzIjoiaGFtZGFzdCIsInN1YiI6IjIzNDg5NDQyIiwiYXVkIjoiZnppeGpheTRpNThkZGFjIiwiaWF0IjoxNzgxNDM5MjM2fQ.rSpaevPNorVA8Hwkks8sxQwp_Z2LbQEVpVCfFOXSKLc"
$centerId = "e5d0fa25-a8e1-40db-a957-97aa0af1c0ee"
$channelId = "cd72a37d-f3b1-4e54-9ab7-2fde0e368e66"
$resourceId = "91ct49E2u4547MM7KFflNZ72EpI"
$bookId = "bc9437f4-67d5-11f1-8fe5-b6c09fdc72a4"
$script:pass = 0
$script:fail = 0

function Report {
    param([string]$Name, [bool]$Ok, [string]$Detail = "")
    if ($Ok) { $script:pass++ } else { $script:fail++ }
    $status = if ($Ok) { "PASS" } else { "FAIL" }
    Write-Host "[$status] $Name"
    if ($Detail) { Write-Host "  $Detail" }
}

function PostVacation {
    param([int]$From, [int]$To)
    $body = "{`"from`":$From,`"to`":$To}"
    $tmp = Join-Path $env:TEMP ("vac-" + [guid]::NewGuid().ToString() + ".json")
    [IO.File]::WriteAllText($tmp, $body, (New-Object Text.UTF8Encoding $false))
    $raw = curl.exe -s -w "`n%{http_code}" -X POST "https://apigw.paziresh24.com/open-platform/v1/booking/vacations/$centerId" -H "Authorization: Bearer $hamdastToken" -H "Content-Type: application/json" --data-binary "@$tmp"
    Remove-Item $tmp -Force
    $lines = $raw -split "`n"
    return @{ code = [int]$lines[-1].Trim(); text = ($lines[0..($lines.Length - 2)] -join "`n") }
}

function DeleteVacation {
    param([int]$From, [int]$To)
    $body = "{`"from`":$From,`"to`":$To}"
    curl.exe -s -X DELETE "https://apigw.paziresh24.com/open-platform/v1/booking/vacations/$centerId" -H "Authorization: Bearer $hamdastToken" -H "Content-Type: application/json" -d $body | Out-Null
}

function TriggerWebhook {
    curl.exe -s -X POST "$base/php/webhook/google-calendar.php" -H "X-Goog-Channel-ID: $channelId" -H "X-Goog-Resource-ID: $resourceId" -H "X-Goog-Resource-State: exists" | Out-Null
}

Write-Host "=== Comprehensive Hamgam Test (user 23489442) ===`n"

# Google token
$tokenBody = "client_id=$googleClientId&client_secret=$googleClientSecret&refresh_token=$refreshToken&grant_type=refresh_token"
$tokenRaw = curl.exe -s -X POST "https://oauth2.googleapis.com/token" -H "Content-Type: application/x-www-form-urlencoded" -d $tokenBody
$tokenJson = $tokenRaw | ConvertFrom-Json
$ga = $tokenJson.access_token
Report "Google token refresh" ($null -ne $ga -and $ga -ne "")

# Calendar list 30 days
$now = Get-Date
$timeMin = $now.ToUniversalTime().ToString("yyyy-MM-ddTHH:mm:ssZ")
$timeMax = $now.AddDays(30).ToUniversalTime().ToString("yyyy-MM-ddTHH:mm:ssZ")
$listUrl = "https://www.googleapis.com/calendar/v3/calendars/primary/events?singleEvents=true&orderBy=startTime&timeMin=$([uri]::EscapeDataString($timeMin))&timeMax=$([uri]::EscapeDataString($timeMax))&maxResults=100"
$listRaw = curl.exe -s -H "Authorization: Bearer $ga" $listUrl
$list = $listRaw | ConvertFrom-Json
$appts = @()
$personal = @()
foreach ($ev in $list.items) {
    $desc = [string]$ev.description
    $sum = [string]$ev.summary
    if ($desc -match 'hamgam_book_id:' -or $sum -match 'پذیرش') { $appts += $ev } else { $personal += $ev }
}
Report "Google calendar list (30d)" ($null -ne $list.items) "total=$($list.items.Count) appointments=$($appts.Count) personal=$($personal.Count)"

# Profile API
$profileRaw = curl.exe -s -H "Authorization: Bearer $hamdastToken" "https://apigw.paziresh24.com/open-platform/v1/user/information"
Report "Paziresh24 user profile" ($profileRaw -match '"id"\s*:') ($profileRaw.Substring(0, [Math]::Min(150, $profileRaw.Length)))

# Medical centers
$centersRaw = curl.exe -s -H "Authorization: Bearer $hamdastToken" "https://apigw.paziresh24.com/open-platform/v1/booking/medical-centers"
Report "Medical centers API" ($centersRaw -match $centerId) "center $centerId found"

# Appointment GET
$apptRaw = curl.exe -s -H "Authorization: Bearer $hamdastToken" "https://apigw.paziresh24.com/open-platform/v1/booking/appointments/$bookId"
Report "Appointment GET by book_id" ($apptRaw -match $bookId) ($apptRaw.Substring(0, [Math]::Min(200, $apptRaw.Length)))

# Find hamgam appointment in calendar
$foundAppt = $false
foreach ($ev in $appts) {
    if ([string]$ev.description -match $bookId) { $foundAppt = $true; break }
}
Report "Appointment event in Google Calendar" $foundAppt $(if ($foundAppt) { "book_id linked in calendar" } else { "not found - webhook may not have created it" })

# Token scopes
$jwtParts = $hamdastToken.Split('.')
$scopeOk = $false
$scopeList = ""
if ($jwtParts.Length -ge 2) {
    $payload = $jwtParts[1]
    $pad = 4 - ($payload.Length % 4)
    if ($pad -lt 4) { $payload += ('=' * $pad) }
    $json = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String($payload.Replace('-', '+').Replace('_', '/')))
    $scopes = ($json | ConvertFrom-Json).scope
    $scopeList = $scopes -join ', '
    $scopeOk = $scopes -contains 'provider.appointment.write'
}
Report "Token has appointment.write scope" $scopeOk $scopeList

# Health
$health = curl.exe -s "$base/php/hamgam/health.php"
Report "Server health" ($health -match '"status":"ok"') $health

# --- Vacation sync CRUD ---
$iranTz = [TimeZoneInfo]::FindSystemTimeZoneById("Iran Standard Time")
$testStart = (Get-Date).Date.AddDays(3).AddHours(14)
$testEnd = $testStart.AddHours(2)
$fromTs = [int][DateTimeOffset]::new($testStart, $iranTz.GetUtcOffset($testStart)).ToUnixTimeSeconds()
$toTs = [int][DateTimeOffset]::new($testEnd, $iranTz.GetUtcOffset($testEnd)).ToUnixTimeSeconds()
$stamp = Get-Date -Format "HHmmss"

$eventObj = @{
    summary = "Hamgam sync verify $stamp"
    description = "Production test - safe to delete"
    start = @{ dateTime = $testStart.ToString("yyyy-MM-ddTHH:mm:ss"); timeZone = "Asia/Tehran" }
    end = @{ dateTime = $testEnd.ToString("yyyy-MM-ddTHH:mm:ss"); timeZone = "Asia/Tehran" }
} | ConvertTo-Json -Depth 5 -Compress
$tmp = Join-Path $env:TEMP "ev-$stamp.json"
[IO.File]::WriteAllText($tmp, $eventObj, (New-Object Text.UTF8Encoding $false))
$createRaw = curl.exe -s -w "`n%{http_code}" -X POST "https://www.googleapis.com/calendar/v3/calendars/primary/events" -H "Authorization: Bearer $ga" -H "Content-Type: application/json" --data-binary "@$tmp"
Remove-Item $tmp -Force
$createLines = $createRaw -split "`n"
$createCode = [int]$createLines[-1].Trim()
$createBody = ($createLines[0..($createLines.Length - 2)] -join "`n")
$created = $createBody | ConvertFrom-Json
$eventId = $created.id
Report "Create Google vacation test event" ($createCode -lt 400) "id=$eventId at $($testStart.ToString('yyyy-MM-dd HH:mm'))"

Start-Sleep -Seconds 3
TriggerWebhook
Start-Sleep -Seconds 6

$vacResp = PostVacation -From $fromTs -To $toTs
$webhookSynced = ($vacResp.code -eq 409)
if (-not $webhookSynced -and $vacResp.code -ge 200 -and $vacResp.code -lt 300) {
    Report "Vacation sync via webhook (create)" $false "HTTP $($vacResp.code) - webhook did not register vacation"
} else {
    Report "Vacation sync via webhook (create)" $webhookSynced "HTTP $($vacResp.code) $($vacResp.text)"
}

# UPDATE: shift +1 hour
$newStart = $testStart.AddHours(1)
$newEnd = $testEnd.AddHours(1)
$newFromTs = [int][DateTimeOffset]::new($newStart, $iranTz.GetUtcOffset($newStart)).ToUnixTimeSeconds()
$newToTs = [int][DateTimeOffset]::new($newEnd, $iranTz.GetUtcOffset($newEnd)).ToUnixTimeSeconds()
$patchObj = @{
    start = @{ dateTime = $newStart.ToString("yyyy-MM-ddTHH:mm:ss"); timeZone = "Asia/Tehran" }
    end = @{ dateTime = $newEnd.ToString("yyyy-MM-ddTHH:mm:ss"); timeZone = "Asia/Tehran" }
} | ConvertTo-Json -Depth 5 -Compress
$tmp3 = Join-Path $env:TEMP "patch-$stamp.json"
[IO.File]::WriteAllText($tmp3, $patchObj, (New-Object Text.UTF8Encoding $false))
$patchRaw = curl.exe -s -w "`n%{http_code}" -X PATCH "https://www.googleapis.com/calendar/v3/calendars/primary/events/$([uri]::EscapeDataString($eventId))" -H "Authorization: Bearer $ga" -H "Content-Type: application/json" --data-binary "@$tmp3"
Remove-Item $tmp3 -Force
$patchCode = [int](($patchRaw -split "`n")[-1].Trim())
Report "Update Google event (+1h)" ($patchCode -lt 400) "HTTP $patchCode"

Start-Sleep -Seconds 2
TriggerWebhook
Start-Sleep -Seconds 6

$oldVac = PostVacation -From $fromTs -To $toTs
$oldSlotFree = ($oldVac.code -ge 200 -and $oldVac.code -lt 300)
if ($oldSlotFree) { DeleteVacation -From $fromTs -To $toTs }

$newVac = PostVacation -From $newFromTs -To $newToTs
$newSlotTaken = ($newVac.code -eq 409)

Report "Vacation update sync (old slot free)" $oldSlotFree "old HTTP $($oldVac.code)"
Report "Vacation update sync (new slot taken)" $newSlotTaken "new HTTP $($newVac.code)"

# DELETE event
curl.exe -s -X DELETE "https://www.googleapis.com/calendar/v3/calendars/primary/events/$([uri]::EscapeDataString($eventId))" -H "Authorization: Bearer $ga" | Out-Null
Start-Sleep -Seconds 2
TriggerWebhook
Start-Sleep -Seconds 6

$delCheck = PostVacation -From $newFromTs -To $newToTs
$vacDeleted = ($delCheck.code -ge 200 -and $delCheck.code -lt 300)
Report "Vacation delete sync (slot free after event delete)" $vacDeleted "HTTP $($delCheck.code)"
if ($vacDeleted) { DeleteVacation -From $newFromTs -To $newToTs }

Write-Host "`n=== Results: $pass passed, $fail failed ==="
if ($fail -gt 0) { exit 1 }
