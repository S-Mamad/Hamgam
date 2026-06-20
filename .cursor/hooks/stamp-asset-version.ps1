$ErrorActionPreference = 'SilentlyContinue'

try {
    $raw = [Console]::In.ReadToEnd()
    if ($raw) {
        $payload = $raw | ConvertFrom-Json
        $path = @($payload.file_path, $payload.path, $payload.filePath) | Where-Object { $_ } | Select-Object -First 1
        if ($path -and $path -notmatch '(\\|/)(style\.css|script\.js)$') {
            exit 0
        }
    }
} catch {}

$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
& (Join-Path $root 'scripts\stamp-asset-version.ps1') -Root $root | Out-Null
exit 0
