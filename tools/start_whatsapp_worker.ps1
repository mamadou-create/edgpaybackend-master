param(
    [string]$Queue = 'whatsapp,default',
    [int]$Tries = 3,
    [int]$Timeout = 30,
    [switch]$StopWhenEmpty,
    [switch]$Once
)

$projectRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$artisanPath = Join-Path $projectRoot 'artisan'

if (-not (Test-Path $artisanPath)) {
    Write-Error "Fichier artisan introuvable: $artisanPath"
    exit 1
}

Push-Location $projectRoot

try {
    $arguments = @(
        $artisanPath,
        'queue:work',
        "--queue=$Queue",
        "--tries=$Tries",
        "--timeout=$Timeout"
    )

    if ($StopWhenEmpty) {
        $arguments += '--stop-when-empty'
    }

    if ($Once) {
        $arguments += '--once'
    }

    & php @arguments
    exit $LASTEXITCODE
}
finally {
    Pop-Location
}