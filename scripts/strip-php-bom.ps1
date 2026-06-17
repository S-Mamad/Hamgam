# حذف BOM از همه فایل‌های PHP قبل از آپلود روی هاست
$root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$utf8NoBom = New-Object System.Text.UTF8Encoding $false
$fixed = 0

Get-ChildItem -Path $root -Recurse -Filter *.php | ForEach-Object {
    $bytes = [System.IO.File]::ReadAllBytes($_.FullName)
    $hasBom = ($bytes.Length -ge 3 -and $bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF)
    if (-not $hasBom) { return }

    $text = [System.IO.File]::ReadAllText($_.FullName, [System.Text.Encoding]::UTF8)
    if ($text.StartsWith([char]0xFEFF)) {
        $text = $text.Substring(1)
    }
    [System.IO.File]::WriteAllText($_.FullName, $text, $utf8NoBom)
    Write-Host "Fixed BOM: $($_.FullName)"
    $fixed++
}

Write-Host "Done. Fixed $fixed file(s)."
