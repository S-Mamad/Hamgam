$body = @{
    access_token = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzY29wZSI6WyJwcm92aWRlci5hcHBvaW50bWVudC5yZWFkIiwicHJvdmlkZXIuYXBwb2ludG1lbnQud2ViaG9vayIsInByb3ZpZGVyLmFwcG9pbnRtZW50LndyaXRlIiwicHJvdmlkZXIubWFuYWdlbWVudC53cml0ZSIsInByb3ZpZGVyLnByb2ZpbGUucmVhZCJdLCJpc3MiOiJoYW1kYXN0Iiwic3ViIjoiMjM0ODk0NDIiLCJhdWQiOiJmeml4amF5NGk1OGRkYWMiLCJpYXQiOjE3ODE5MzU1NTR9.113An0QoturGJXJJtNveT2-SgIKYjzUnihGSy3l3S7o"
    mobile = "09351925900"
    code = "789766"
} | ConvertTo-Json

try {
    $r = Invoke-WebRequest -Uri "https://hamgam.zamanak24.ir/php/integrations/verify-otp.php?provider=drdr" `
        -Method POST `
        -Headers @{
            Authorization = "Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzY29wZSI6WyJwcm92aWRlci5hcHBvaW50bWVudC5yZWFkIiwicHJvdmlkZXIuYXBwb2ludG1lbnQud2ViaG9vayIsInByb3ZpZGVyLmFwcG9pbnRtZW50LndyaXRlIiwicHJvdmlkZXIubWFuYWdlbWVudC53cml0ZSIsInByb3ZpZGVyLnByb2ZpbGUucmVhZCJdLCJpc3MiOiJoYW1kYXN0Iiwic3ViIjoiMjM0ODk0NDIiLCJhdWQiOiJmeml4amF5NGk1OGRkYWMiLCJpYXQiOjE3ODE5MzU1NTR9.113An0QoturGJXJJtNveT2-SgIKYjzUnihGSy3l3S7o"
            "Content-Type" = "application/json"
        } `
        -Body $body
    Write-Output "STATUS $($r.StatusCode)"
    Write-Output $r.Content
} catch {
    if ($_.Exception.Response) {
        $reader = New-Object System.IO.StreamReader($_.Exception.Response.GetResponseStream())
        Write-Output "STATUS $([int]$_.Exception.Response.StatusCode)"
        Write-Output $reader.ReadToEnd()
    } else {
        Write-Output $_.Exception.Message
    }
}
