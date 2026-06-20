$token = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzY29wZSI6WyJwcm92aWRlci5hcHBvaW50bWVudC5yZWFkIiwicHJvdmlkZXIuYXBwb2ludG1lbnQud2ViaG9vayIsInByb3ZpZGVyLmFwcG9pbnRtZW50LndyaXRlIiwicHJvdmlkZXIubWFuYWdlbWVudC53cml0ZSIsInByb3ZpZGVyLnByb2ZpbGUucmVhZCJdLCJpc3MiOiJoYW1kYXN0Iiwic3ViIjoiMjM0ODk0NDIiLCJhdWQiOiJmeml4amF5NGk1OGRkYWMiLCJpYXQiOjE3ODE5MzU1NTR9.113An0QoturGJXJJtNveT2-SgIKYjzUnihGSy3l3S7o"
$mobile = "09351925900"
$headers = @{
    Authorization = "Bearer $token"
    "Content-Type" = "application/json"
}

function Invoke-Hamgam($url, $payload) {
    try {
        $r = Invoke-WebRequest -Uri $url -Method POST -Headers $headers -Body ($payload | ConvertTo-Json)
        return @{ status = [int]$r.StatusCode; body = $r.Content }
    } catch {
        $reader = New-Object System.IO.StreamReader($_.Exception.Response.GetResponseStream())
        return @{ status = [int]$_.Exception.Response.StatusCode; body = $reader.ReadToEnd() }
    }
}

Write-Output "=== SEND OTP ==="
$send = Invoke-Hamgam "https://hamgam.zamanak24.ir/php/integrations/send-otp.php?provider=drdr" @{
    access_token = $token
    mobile = $mobile
}
Write-Output "HTTP $($send.status)"
Write-Output $send.body

Write-Output "`n=== VERIFY (fake code 123456) ==="
$verify = Invoke-Hamgam "https://hamgam.zamanak24.ir/php/integrations/verify-otp.php?provider=drdr" @{
    access_token = $token
    mobile = $mobile
    code = "123456"
}
Write-Output "HTTP $($verify.status)"
Write-Output $verify.body

Write-Output "`n=== VERIFY again same code ==="
$verify2 = Invoke-Hamgam "https://hamgam.zamanak24.ir/php/integrations/verify-otp.php?provider=drdr" @{
    access_token = $token
    mobile = $mobile
    code = "123456"
}
Write-Output "HTTP $($verify2.status)"
Write-Output $verify2.body
