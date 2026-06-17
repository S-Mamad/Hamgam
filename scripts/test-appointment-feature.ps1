# E2E test for appointment + vacation conflict feature
$ErrorActionPreference = "Continue"
$base = "https://hamgam.zamanak24.ir"
$apiKey = "8d25918d-0d58-41be-80ba-ef5e8a4a29c9"
$userId = "23489442"
$hamdastToken = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzY29wZSI6WyJwcm92aWRlci5hcHBvaW50bWVudC5yZWFkIiwicHJvdmlkZXIuYXBwb2ludG1lbnQud2ViaG9vayIsInByb3ZpZGVyLm1hbmFnZW1lbnQucmVhZCIsInByb3ZpZGVyLm1hbmFnZW1lbnQud3JpdGUiLCJwcm92aWRlci5wcm9maWxlLnJlYWQiXSwiaXNzIjoiaGFtZGFzdCIsInN1YiI6IjIzNDg5NDQyIiwiYXVkIjoiZnppeGpheTRpNThkZGFjIiwiaWF0IjoxNzgxNDM5MjM2fQ.rSpaevPNorVA8Hwkks8sxQwp_Z2LbQEVpVCfFOXSKLc"
$centerId = "e5d0fa25-a8e1-40db-a957-97aa0af1c0ee"
$channelId = "cd72a37d-f3b1-4e54-9ab7-2fde0e368e66"
$resourceId = "91ct49E2u4547MM7KFflNZ72EpI"
$bookId = "bc9437f4-67d5-11f1-8fe5-b6c09fdc72a4"
$pass = 0; $fail = 0

function Report([string]$name, [bool]$ok, [string]$detail = "") {
    $script:global:status = if ($ok) { "PASS" } else { "FAIL" }
    if ($ok) { $script:pass++ } else { $script:fail++ }
    Write-Host "[$status] $name"
    if ($detail) { Write-Host "  $detail" }
}

function PostJson([string]$url, [string]$json) {
    $tmp = Join-Path $env:TEMP ("hamgam-" + [guid]::NewGuid().ToString() + ".json")
    [IO.File]::WriteAllText($tmp, $json, (New-Object Text.UTF8Encoding $false))
    $raw = curl.exe -s --max-time 60 -X POST $url -H "Content-Type: application/json" --data-binary "@$tmp"
    Remove-Item $tmp -Force
    return $raw
}

Write-Host "=== Hamgam appointment feature E2E ===`n"

# 1) Production health
$h = curl.exe -s --max-time 15 "$base/php/hamgam/health.php"
Report "health" ($h -match '"status":"ok"') $h

# 2) New test endpoint deployed?
$statusUrl = "$base/php/tools/test-appointment-feature.php?user_id=$userId&key=$apiKey"
$statusRaw = curl.exe -s --max-time 60 $statusUrl
$statusOk = $statusRaw -match '"ok"\s*:\s*true' -and $statusRaw -notmatch '404 Not Found'
Report "test-appointment-feature deployed" $statusOk $(if ($statusOk) { "server-side calendar scan ok" } else { "NOT DEPLOYED - upload deploy/php/tools/test-appointment-feature.php" })

# 3) Token scopes
$jwtParts = $hamdastToken.Split('.')
$scopeOk = $false
if ($jwtParts.Length -ge 2) {
    $payload = $jwtParts[1]
    $pad = 4 - ($payload.Length % 4)
    if ($pad -lt 4) { $payload += ('=' * $pad) }
    $json = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String($payload.Replace('-','+').Replace('_','/')))
    $scopes = ($json | ConvertFrom-Json).scope
    $scopeList = $scopes -join ', '
    $scopeOk = $scopes -contains 'provider.appointment.write'
    Report "token has appointment.write scope" $scopeOk $scopeList
} else {
    Report "token has appointment.write scope" $false "could not decode JWT"
}

# 4) Appointment GET
$apptRaw = curl.exe -s --max-time 20 -H "Authorization: Bearer $hamdastToken" "https://apigw.paziresh24.com/open-platform/v1/booking/appointments/$bookId"
$apptOk = $apptRaw -match '"id"\s*:\s*"' + [regex]::Escape($bookId)
Report "appointment exists (book_id)" $apptOk $(if ($apptOk) { "from in response" } else { $apptRaw })

# 5) Webhook create calendar event for appointment
$webhookPayload = @"
{"event":"provider.appointment","data":{"book_date":"2026-06-15","book_id":"$bookId","book_insert_at":"1781430301","book_time":"12:00","book_type":"Clinic WEB","center_id":"$centerId","center_name":"Test","doctor_user_id":$userId,"patient_name":"محمد","patient_family":"محمدی","patient_cell":"9351925900","patient_national_code":"4421760447","duration":15}}
"@
$whAppt = PostJson "$base/php/webhook/paziresh24-hamgam.php" $webhookPayload
$whApptOk = $whAppt -match '"ok"\s*:\s*true'
Report "appointment webhook" $whApptOk $whAppt

# 6) BOOK_CONFLICT then cancel appointment then vacation (API-level)
$fromTs = 1781512200; $toTs = 1781514000
$vacBody = "{`"from`":$fromTs,`"to`":$toTs}"
$tmp = Join-Path $env:TEMP 'v1.json'; [IO.File]::WriteAllText($tmp, $vacBody, (New-Object Text.UTF8Encoding $false))
$vacCode = curl.exe -s -o $env:TEMP\v1out.txt -w "%{http_code}" -X POST "https://apigw.paziresh24.com/open-platform/v1/booking/vacations/$centerId" -H "Authorization: Bearer $hamdastToken" -H "Content-Type: application/json" --data-binary "@$tmp"
$vacOut = Get-Content $env:TEMP\v1out.txt -Raw
Remove-Item $tmp, $env:TEMP\v1out.txt -Force -ErrorAction SilentlyContinue
$conflict = ($vacCode -eq '409') -or ($vacOut -match 'BOOK_CONFLICT')
Report "vacation conflict detected" $conflict "HTTP $vacCode $vacOut"

if ($conflict -and $scopeOk) {
    $delCode = curl.exe -s -o $env:TEMP\delout.txt -w "%{http_code}" -X DELETE "https://apigw.paziresh24.com/open-platform/v1/booking/appointments/$bookId" -H "Authorization: Bearer $hamdastToken"
    $delOut = Get-Content $env:TEMP\delout.txt -Raw
    Remove-Item $env:TEMP\delout.txt -Force -ErrorAction SilentlyContinue
    $delOk = $delCode -ge 200 -and $delCode -lt 300
    Report "cancel appointment API" $delOk "HTTP $delCode $delOut"

    $tmp2 = Join-Path $env:TEMP 'v2.json'; [IO.File]::WriteAllText($tmp2, $vacBody, (New-Object Text.UTF8Encoding $false))
    $vac2Code = curl.exe -s -o $env:TEMP\v2out.txt -w "%{http_code}" -X POST "https://apigw.paziresh24.com/open-platform/v1/booking/vacations/$centerId" -H "Authorization: Bearer $hamdastToken" -H "Content-Type: application/json" --data-binary "@$tmp2"
    $vac2Out = Get-Content $env:TEMP\v2out.txt -Raw
    Remove-Item $tmp2, $env:TEMP\v2out.txt -Force -ErrorAction SilentlyContinue
    $vac2Ok = $vac2Code -ge 200 -and $vac2Code -lt 300
    Report "vacation after cancel appointment" $vac2Ok "HTTP $vac2Code $vac2Out"
} elseif ($conflict -and -not $scopeOk) {
    Report "cancel appointment API" $false "skipped - missing provider.appointment.write (re-auth from Paziresh24 panel)"
    Report "vacation after cancel appointment" $false "skipped - needs appointment.write scope"
} else {
    Report "cancel appointment API" $false "skipped - no conflict"
    Report "vacation after cancel appointment" $false "skipped"
}

# 7) Trigger google webhook (uses server code)
$whCal = curl.exe -s --max-time 60 -X POST "$base/php/webhook/google-calendar.php" -H "X-Goog-Channel-ID: $channelId" -H "X-Goog-Resource-ID: $resourceId" -H "X-Goog-Resource-State: exists"
Report "google calendar webhook" ($whCal.Trim() -eq 'OK') $whCal

# 8) If test endpoint deployed, run conflict test on server
if ($statusOk) {
    $confUrl = "$base/php/tools/test-appointment-feature.php?user_id=$userId&key=$apiKey&action=conflict&confirm=1"
    $confRaw = curl.exe -s --max-time 120 $confUrl
    $confServerOk = $confRaw -match '"vacation_tracked"\s*:\s*true'
    Report "server conflict flow (new code)" $confServerOk $confRaw
} else {
    Report "server conflict flow (new code)" $false "deploy required"
}

Write-Host "`n=== Results: $pass passed, $fail failed ==="
if ($fail -gt 0) { exit 1 }
