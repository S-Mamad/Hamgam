# Stamps style.css and script.js URLs in HTML with a content hash for cache busting.
param(
    [string]$Root = (Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)),
    [string]$DeployDir = ""
)

$utf8NoBom = New-Object System.Text.UTF8Encoding $false

function Get-AssetVersion([string]$CssPath, [string]$JsPath) {
    $content = ""
    if (Test-Path $CssPath) { $content += [System.IO.File]::ReadAllText($CssPath) }
    if (Test-Path $JsPath) { $content += [System.IO.File]::ReadAllText($JsPath) }
    if (-not $content) { return (Get-Date -Format "yyyyMMddHHmm") }

    $sha = [System.Security.Cryptography.SHA256]::Create()
    $bytes = [System.Text.Encoding]::UTF8.GetBytes($content)
    $hash = $sha.ComputeHash($bytes)
    return [BitConverter]::ToString($hash).Replace("-", "").Substring(0, 8).ToLower()
}

function Stamp-HtmlFile([string]$HtmlPath, [string]$CssPath, [string]$JsPath) {
    if (-not (Test-Path $HtmlPath)) { return $null }

    $version = Get-AssetVersion $CssPath $JsPath
    $html = [System.IO.File]::ReadAllText($HtmlPath)

    $html = $html -replace 'href="style\.css(?:\?v=[^"]*)?"', "href=`"style.css?v=$version`""
    $html = $html -replace 'src="script\.js(?:\?v=[^"]*)?"', "src=`"script.js?v=$version`""

    [System.IO.File]::WriteAllText($HtmlPath, $html, $utf8NoBom)
    Write-Host "Asset version v=$version -> $HtmlPath"
    return $version
}

$targets = @(
    @{
        Html = Join-Path $Root "index.html"
        Css  = Join-Path $Root "style.css"
        Js   = Join-Path $Root "script.js"
    },
    @{
        Html = Join-Path $Root "landing\index.html"
        Css  = Join-Path $Root "landing\style.css"
        Js   = Join-Path $Root "landing\script.js"
    }
)

foreach ($t in $targets) {
    Stamp-HtmlFile $t.Html $t.Css $t.Js | Out-Null
}

# deploy/ is build output — only stamp when explicitly requested after files are copied.
if ($DeployDir -and (Test-Path $DeployDir)) {
    Stamp-HtmlFile `
        (Join-Path $DeployDir "index.html") `
        (Join-Path $DeployDir "style.css") `
        (Join-Path $DeployDir "script.js") | Out-Null
}
