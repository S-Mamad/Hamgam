# Test live server after upload
$base = "https://hamgam.zamanak24.ir"
$fail = 0

function Test-Endpoint($name, $url, $method = "GET", $body = $null) {
    $tmp = Join-Path $env:TEMP "hamgam-test-$name.txt"
    $codeFile = Join-Path $env:TEMP "hamgam-test-$name-code.txt"
    $bodyFile = Join-Path $env:TEMP "hamgam-test-$name-body.json"

    if ($method -eq "GET") {
        curl.exe -s -o $tmp -w "%{http_code}" $url | Out-File -Encoding ascii $codeFile
    } elseif ($body -ne $null) {
        $utf8NoBom = New-Object System.Text.UTF8Encoding $false
        [System.IO.File]::WriteAllText($bodyFile, $body, $utf8NoBom)
        curl.exe -s -o $tmp -w "%{http_code}" -X POST $url -H "Content-Type: application/json" --data-binary "@$bodyFile" | Out-File -Encoding ascii $codeFile
        Remove-Item $bodyFile -ErrorAction SilentlyContinue
    } else {
        curl.exe -s -o $tmp -w "%{http_code}" -X POST $url | Out-File -Encoding ascii $codeFile
    }

    $text = Get-Content -Raw $tmp
    $code = (Get-Content -Raw $codeFile).Trim()
    Remove-Item $tmp, $codeFile -ErrorAction SilentlyContinue

    $isJson = $false
    try { $null = $text | ConvertFrom-Json; $isJson = $true } catch {}

    $ok = $isJson -and ($text -notmatch "<br\s*/?>|<!DOCTYPE")
    if (-not $ok) { $script:fail++ }

    $status = if ($ok) { "PASS" } else { "FAIL" }
    Write-Host "[$status] $name (HTTP $code)"
    if (-not $ok) {
        $preview = $text.Substring(0, [Math]::Min(180, $text.Length)).Replace("`n", " ")
        Write-Host "  $preview"
    } elseif ($name -eq "health") {
        Write-Host "  $($text.Trim())"
    } elseif ($name -eq "webhook-appointment") {
        Write-Host "  $($text.Trim())"
    }
}

# Use a connected doctor id from production DB (override: $env:HAMGAM_TEST_DOCTOR_USER_ID)
$doctorUserId = if ($env:HAMGAM_TEST_DOCTOR_USER_ID) { [int]$env:HAMGAM_TEST_DOCTOR_USER_ID } else { 11683704 }
$webhookPayload = "{`"event`":`"provider.appointment`",`"data`":{`"book_id`":`"test-book-id`",`"doctor_user_id`":$doctorUserId,`"book_date`":`"2026-06-14`",`"book_time`":`"11:00`",`"duration`":30,`"center_name`":`"Test Center`",`"patient_name`":`"Test`",`"patient_family`":`"User`",`"patient_cell`":`"09120000000`",`"patient_national_code`":`"1234567890`"}}"

Write-Host "=== Hamgam server test: $base ===`n"
Test-Endpoint "health" "$base/php/hamgam/health.php"
Test-Endpoint "auth" "$base/php/hamgam/auth.php" "POST" '{"hamdast_session_token":"eyJhbGci.test.sig"}'
Test-Endpoint "update" "$base/php/hamgam/update.php" "POST" '{"access_token":"eyJhbGci.test.sig"}'
Test-Endpoint "webhook-appointment" "$base/php/webhook/paziresh24-hamgam.php" "POST" $webhookPayload

Write-Host ""
if ($fail -eq 0) {
    Write-Host "All checks PASSED. Open app from Paziresh24 panel."
    exit 0
}

Write-Host "$fail check(s) FAILED - upload deploy/ folder then re-run this script."
exit 1
