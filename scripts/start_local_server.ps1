param(
    [int]$Port = 8000
)

$projectRoot = Split-Path -Parent $PSScriptRoot
$phpBinary = 'C:\xampp\php\php.exe'
$requestTemp = Join-Path $projectRoot 'storage\tmp'

if (-not (Test-Path -LiteralPath $phpBinary)) {
    throw "PHP was not found at $phpBinary"
}

New-Item -ItemType Directory -Path $requestTemp -Force | Out-Null

Write-Host "PMS running at http://127.0.0.1:$Port/"
& $phpBinary `
    -d "upload_tmp_dir=$requestTemp" `
    -d display_errors=0 `
    -d log_errors=1 `
    -S "127.0.0.1:$Port" `
    -t $projectRoot
