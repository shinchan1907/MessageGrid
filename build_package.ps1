# PowerShell script to package the WhatsApp CRM Extension for Windows developers with Linux-compatible forward slashes

$packageName = "whatsapp-integration-1.0.7.zip"
$tempBuildPath = Join-Path $PSScriptRoot "build_temp"

if (Test-Path $packageName) {
    Remove-Item $packageName -Force
}

if (Test-Path $tempBuildPath) {
    Remove-Item $tempBuildPath -Recurse -Force
}

New-Item -ItemType Directory -Path $tempBuildPath | Out-Null

Write-Host "Creating temporary build folder..." -ForegroundColor Cyan

# Copy root manifest, LICENSE and README
Copy-Item "manifest.json" -Destination (Join-Path $tempBuildPath "manifest.json")
Copy-Item "LICENSE" -Destination (Join-Path $tempBuildPath "LICENSE")
Copy-Item "README.md" -Destination (Join-Path $tempBuildPath "README.md")

# Copy scripts and files directories
if (Test-Path "scripts") {
    Copy-Item "scripts" -Destination $tempBuildPath -Recurse
}
if (Test-Path "files") {
    Copy-Item "files" -Destination $tempBuildPath -Recurse
}

Write-Host "Compiling installable ZIP package with Linux-compatible forward slashes..." -ForegroundColor Cyan

# Load .NET Assembly for Zip operations
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$zip = [System.IO.Compression.ZipFile]::Open($packageName, [System.IO.Compression.ZipArchiveMode]::Create)

$files = Get-ChildItem -Path $tempBuildPath -Recurse | Where-Object { !$_.PSIsContainer }

foreach ($file in $files) {
    $filePath = $file.FullName
    $relativePath = $filePath.Substring($tempBuildPath.Length + 1)
    
    # CRITICAL: Force Linux forward slashes for folder extraction inside Docker containers
    $entryName = $relativePath.Replace('\', '/')
    
    [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $filePath, $entryName)
}

$zip.Dispose()

# Clean up temp files
Remove-Item $tempBuildPath -Recurse -Force

if (Test-Path $packageName) {
    $file = Get-Item $packageName
    $sizeKb = [Math]::Round(($file.Length / 1KB), 2)
    Write-Host "========================================================" -ForegroundColor Green
    Write-Host "SUCCESS: Installable EspoCRM Extension Compiled (Linux-Compatible)!" -ForegroundColor Green
    Write-Host "Archive Generated: $($file.FullName)" -ForegroundColor Green
    Write-Host "Size: $sizeKb KB" -ForegroundColor Green
    Write-Host "========================================================" -ForegroundColor Green
    Write-Host "Upload this ZIP directly via Admin -> Extensions in EspoCRM." -ForegroundColor White
} else {
    Write-Host "Error compiling archive." -ForegroundColor Red
}
