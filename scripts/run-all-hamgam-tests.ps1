# Run all Hamgam scenario tests (local + optional live server smoke test)
$ErrorActionPreference = "Stop"
$root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$fail = 0

function Run-Step {
    param(
        [string]$Name,
        [scriptblock]$Action
    )

    Write-Host ""
    Write-Host "=== $Name ==="
    try {
        & $Action
        if ($LASTEXITCODE -ne 0) {
            Write-Host "[FAIL] $Name (exit $LASTEXITCODE)"
            $script:fail++
            return
        }
        Write-Host "[PASS] $Name"
    } catch {
        Write-Host "[FAIL] $Name"
        Write-Host $_.Exception.Message
        $script:fail++
    }
}

Push-Location $root

Run-Step "UI: delete backfill button" {
    node --test scripts/test-delete-backfill-ui.mjs
}

Run-Step "UI: vacation centers (23 tests)" {
    node --test scripts/test-vacation-centers-ui.mjs
}

Run-Step "PHP: auth bootstrap" {
    php php/tools/test-auth-bootstrap.php
}

Run-Step "PHP: OAuth / launcher button flow" {
    php php/tools/test-oauth-flow.php
    php php/tools/test-button-flow.php
}

Run-Step "PHP: vacation center scenarios" {
    php php/tools/test-vacation-centers-scenarios.php
}

Run-Step "PHP: appointment + center settings" {
    php php/tools/test-appointment-settings.php
}

Run-Step "PHP: update connected state" {
    php php/tools/test-update-connected.php
}

Run-Step "PHP: import delete targets" {
    php php/tools/test-import-delete-targets.php
}

Run-Step "PHP: backfill delete fix" {
    php php/tools/test-backfill-delete-fix.php
}

Run-Step "PHP: full test runner" {
    php php/tools/run-tests.php
}

if ($env:HAMGAM_RUN_LIVE_TESTS -eq "1") {
    Run-Step "Live server smoke test" {
        powershell -ExecutionPolicy Bypass -File (Join-Path $root "scripts\test-server.ps1")
    }
} else {
    Write-Host ""
    Write-Host "Tip: set HAMGAM_RUN_LIVE_TESTS=1 to also hit hamgam.zamanak24.ir"
}

Pop-Location

Write-Host ""
if ($fail -eq 0) {
    Write-Host "All Hamgam test suites PASSED."
    exit 0
}

Write-Host "$fail test suite(s) FAILED."
exit 1
