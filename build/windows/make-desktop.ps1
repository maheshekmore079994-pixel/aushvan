# Builds the AUSHVAN Windows desktop package: a folder with a bundled PHP, the application, and a launcher,
# then (if Inno Setup is installed) a one-file installer AUSHVAN-Setup-<version>.exe.
#
#   powershell -ExecutionPolicy Bypass -File build\windows\make-desktop.ps1 [-Version 0.9.0] [-PhpDir <folder with php.exe>]
#
# Needs: PHP 8.3+ with pdo_sqlite/mbstring/intl/bcmath/gd/fileinfo/openssl/curl/zip, Composer, Node, and the
# C# compiler that ships with Windows (.NET Framework 4). Output: build\windows\dist\AUSHVAN\ and build\windows\out\.
param(
    [string]$Version = '0.9.0',
    [string]$PhpDir = ''
)
$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$dist = Join-Path $PSScriptRoot 'dist\AUSHVAN'
$out = Join-Path $PSScriptRoot 'out'

function Step($text) { Write-Host "==> $text" -ForegroundColor Cyan }

# 1. Which PHP to bundle: the one on PATH unless told otherwise.
if ($PhpDir -eq '') {
    $phpExe = (Get-Command php.exe).Source
    $PhpDir = Split-Path $phpExe
}
if (-not (Test-Path (Join-Path $PhpDir 'php.exe'))) { throw "php.exe not found in $PhpDir" }
Step "PHP from $PhpDir"

# 2. Fresh dist folder.
if (Test-Path $dist) { Remove-Item $dist -Recurse -Force }
New-Item -ItemType Directory -Force (Join-Path $dist 'php\ext') | Out-Null
New-Item -ItemType Directory -Force (Join-Path $dist 'app') | Out-Null

# 3. PHP runtime: the binaries and only the extensions the application uses.
Step 'Copying the PHP runtime'
$phpFiles = @('php.exe', 'php8ts.dll', 'php8.dll', 'libcrypto-3-x64.dll', 'libssl-3-x64.dll', 'libsqlite3.dll', 'libsodium.dll', 'nghttp2.dll', 'libssh2.dll',
    'icudt77.dll', 'icuin77.dll', 'icuio77.dll', 'icuuc77.dll', 'brotlicommon.dll', 'brotlidec.dll', 'license.txt')
foreach ($f in $phpFiles) { $p = Join-Path $PhpDir $f; if (Test-Path $p) { Copy-Item $p (Join-Path $dist 'php') } }
# ICU library names carry the version; copy whatever icu*.dll exists.
Get-ChildItem (Join-Path $PhpDir 'icu*.dll') | ForEach-Object { Copy-Item $_.FullName (Join-Path $dist 'php') -Force }
$extensions = @('pdo_sqlite', 'sqlite3', 'mbstring', 'intl', 'bcmath', 'gd', 'fileinfo', 'openssl', 'curl', 'zip', 'sodium')
foreach ($e in $extensions) {
    $dll = Join-Path $PhpDir "ext\php_$e.dll"
    if (Test-Path $dll) { Copy-Item $dll (Join-Path $dist 'php\ext') }
}

$ini = @(
    '; AUSHVAN desktop PHP settings',
    'extension_dir = "ext"',
    'memory_limit = 256M',
    'upload_max_filesize = 10M',
    'post_max_size = 12M',
    'max_execution_time = 120',
    'date.timezone = Asia/Kolkata',
    'display_errors = Off',
    'log_errors = On',
    'error_log = "php_errors.log"',
    '; OPcache is left out: on Windows the built-in web server fails with it (ASLR), and one shop does not need it.',
    'opcache.enable = 0'
)
foreach ($e in $extensions) { if (Test-Path (Join-Path $dist "php\ext\php_$e.dll")) { $ini += "extension = $e" } }
$ini -join "`r`n" | Set-Content (Join-Path $dist 'php\php.ini') -Encoding ascii

# 4. The application: production dependencies and a fresh frontend build.
Step 'Installing production PHP dependencies'
$stage = Join-Path $PSScriptRoot 'stage'
if (Test-Path $stage) { Remove-Item $stage -Recurse -Force }
New-Item -ItemType Directory -Force $stage | Out-Null
$copy = @('app', 'bootstrap', 'config', 'database', 'public', 'resources', 'routes', 'storage', 'artisan', 'composer.json', 'composer.lock', 'package.json', 'package-lock.json', 'vite.config.ts', 'tsconfig.json')
foreach ($item in $copy) {
    $src = Join-Path $root $item
    if (Test-Path $src) { Copy-Item $src (Join-Path $stage $item) -Recurse -Force }
}
# No local data goes into the package.
Remove-Item (Join-Path $stage 'database\*.sqlite*') -Force -ErrorAction SilentlyContinue
Remove-Item (Join-Path $stage 'bootstrap\cache\*.php') -Force -ErrorAction SilentlyContinue
Get-ChildItem (Join-Path $stage 'storage\framework\cache\data'), (Join-Path $stage 'storage\framework\sessions'), (Join-Path $stage 'storage\framework\views'), (Join-Path $stage 'storage\logs') -ErrorAction SilentlyContinue |
    Where-Object { $_.Name -ne '.gitignore' } | Remove-Item -Recurse -Force
Remove-Item (Join-Path $stage 'storage\app\exports'), (Join-Path $stage 'storage\app\mpdf'), (Join-Path $stage 'storage\app\backups') -Recurse -Force -ErrorAction SilentlyContinue
# Uploaded files of the development shop stay here too.
Get-ChildItem (Join-Path $stage 'storage\app\private'), (Join-Path $stage 'storage\app\public') -ErrorAction SilentlyContinue |
    Where-Object { $_.Name -ne '.gitignore' } | Remove-Item -Recurse -Force

Push-Location $stage
try {
    & composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-scripts --quiet
    if ($LASTEXITCODE -ne 0) { throw 'composer install failed' }
    Step 'Building the screens'
    Copy-Item (Join-Path $root 'node_modules') (Join-Path $stage 'node_modules') -Recurse -Force
    & npm run build --silent
    if ($LASTEXITCODE -ne 0) { throw 'npm run build failed' }
    Remove-Item (Join-Path $stage 'node_modules') -Recurse -Force
} finally { Pop-Location }

Step 'Assembling the app folder'
foreach ($item in @('app', 'bootstrap', 'config', 'database', 'public', 'resources', 'routes', 'storage', 'vendor', 'artisan', 'composer.json')) {
    Copy-Item (Join-Path $stage $item) (Join-Path $dist "app\$item") -Recurse -Force
}
Remove-Item (Join-Path $dist 'app\resources\js'), (Join-Path $dist 'app\resources\css') -Recurse -Force -ErrorAction SilentlyContinue
Remove-Item (Join-Path $dist 'app\database\*.sqlite*') -Force -ErrorAction SilentlyContinue
# mPDF ships 87 MB of fonts for every script; the PDF exports are limited to FreeSans, FreeSerif (Devanagari)
# and the DejaVu Sans families (ReportExporter::PDF_FONTS tells mPDF no other font exists), so the rest
# stays out of the package. Keep this pattern and PDF_FONTS in step; tests/Feature/ReportPdfFontsTest checks it.
Get-ChildItem (Join-Path $dist 'app\vendor\mpdf\mpdf\ttfonts') -File |
    Where-Object { $_.Name -notmatch '^(FreeSans|FreeSerif|DejaVuSans)' -and $_.Name -notin @('GNUFreeFontinfo.txt', 'DejaVuinfo.txt') } | Remove-Item -Force
@(
    'APP_NAME="AUSHVAN"',
    'APP_ENV=production',
    'APP_KEY=',
    'APP_DEBUG=false',
    'APP_URL=http://127.0.0.1:8765',
    'APP_LOCALE=en',
    "APP_VERSION=$Version",
    'LOG_CHANNEL=daily',
    'LOG_LEVEL=warning',
    'DB_CONNECTION=sqlite',
    'DB_DATABASE=',
    'SESSION_DRIVER=database',
    'SESSION_LIFETIME=720',
    'CACHE_STORE=database',
    'QUEUE_CONNECTION=sync',
    'FILESYSTEM_DISK=local',
    'DESKTOP_BUILD=true'
) -join "`r`n" | Set-Content (Join-Path $dist 'app\.env') -Encoding ascii

# 5. The launcher.
Step 'Compiling the launcher'
$csc = Join-Path $env:WINDIR 'Microsoft.NET\Framework64\v4.0.30319\csc.exe'
if (-not (Test-Path $csc)) { throw "C# compiler not found at $csc" }
$icon = Join-Path $PSScriptRoot 'aushvan.ico'
$iconArg = if (Test-Path $icon) { "/win32icon:`"$icon`"" } else { '' }
& $csc /nologo /target:winexe /optimize+ "/out:$(Join-Path $dist 'AUSHVAN.exe')" $iconArg /reference:System.Windows.Forms.dll /reference:System.Drawing.dll (Join-Path $PSScriptRoot 'launcher\Launcher.cs')
if ($LASTEXITCODE -ne 0) { throw 'launcher compile failed' }
Set-Content (Join-Path $dist 'VERSION.txt') $Version -Encoding ascii

$size = [math]::Round((Get-ChildItem $dist -Recurse | Measure-Object Length -Sum).Sum / 1MB)
Step "Package folder ready: $dist ($size MB)"

# 6. The installer, when Inno Setup is available.
$iscc = @("$env:ProgramFiles(x86)\Inno Setup 6\ISCC.exe", "$env:LOCALAPPDATA\Programs\Inno Setup 6\ISCC.exe", "${env:ProgramFiles(x86)}\Inno Setup 6\ISCC.exe") | Where-Object { Test-Path $_ } | Select-Object -First 1
if ($iscc) {
    Step 'Building the installer'
    New-Item -ItemType Directory -Force $out | Out-Null
    & $iscc /Q "/DAppVersion=$Version" "/DSourceDir=$dist" "/DOutDir=$out" (Join-Path $PSScriptRoot 'aushvan.iss')
    if ($LASTEXITCODE -ne 0) { throw 'Inno Setup failed' }
    Get-ChildItem $out -Filter '*.exe' | ForEach-Object { Step "Installer: $($_.FullName) ($([math]::Round($_.Length / 1MB)) MB)" }
} else {
    Step 'Inno Setup not found (winget install JRSoftware.InnoSetup): making a zip instead'
    New-Item -ItemType Directory -Force $out | Out-Null
    $zip = Join-Path $out "AUSHVAN-$Version-windows.zip"
    if (Test-Path $zip) { Remove-Item $zip -Force }
    Compress-Archive -Path $dist -DestinationPath $zip -CompressionLevel Optimal
    Step "Zip: $zip ($([math]::Round((Get-Item $zip).Length / 1MB)) MB) - unzip anywhere and run AUSHVAN\AUSHVAN.exe"
}
