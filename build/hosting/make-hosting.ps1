# Builds the AUSHVAN hosting package: the production application (no dev packages, screens built) as one zip
# to upload to a cPanel / shared-hosting account, plus an .env template and the post-upload commands.
#
#   powershell -ExecutionPolicy Bypass -File build\hosting\make-hosting.ps1 [-Version 0.9.0]
#
# Output: build\hosting\out\aushvan-hosting-<version>.zip   (unzip into the subdomain's folder; see docs/HOSTING_CPANEL.md)
param([string]$Version = '0.9.0')
$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$stage = Join-Path $PSScriptRoot 'stage'
$out = Join-Path $PSScriptRoot 'out'
function Step($text) { Write-Host "==> $text" -ForegroundColor Cyan }

if (Test-Path $stage) { Remove-Item $stage -Recurse -Force }
New-Item -ItemType Directory -Force $stage | Out-Null
$app = Join-Path $stage 'aushvan'
New-Item -ItemType Directory -Force $app | Out-Null

Step 'Copying the application'
foreach ($item in @('app', 'bootstrap', 'config', 'database', 'public', 'resources', 'routes', 'storage', 'artisan', 'composer.json', 'composer.lock', 'package.json', 'package-lock.json', 'vite.config.ts', 'tsconfig.json')) {
    $src = Join-Path $root $item
    if (Test-Path $src) { Copy-Item $src (Join-Path $app $item) -Recurse -Force }
}
# No data from this PC goes to the server.
Remove-Item (Join-Path $app 'database\*.sqlite*') -Force -ErrorAction SilentlyContinue
Remove-Item (Join-Path $app 'bootstrap\cache\*.php') -Force -ErrorAction SilentlyContinue
Get-ChildItem (Join-Path $app 'storage\framework\cache\data'), (Join-Path $app 'storage\framework\sessions'), (Join-Path $app 'storage\framework\views'), (Join-Path $app 'storage\logs') -ErrorAction SilentlyContinue |
    Where-Object { $_.Name -ne '.gitignore' } | Remove-Item -Recurse -Force
Remove-Item (Join-Path $app 'storage\app\exports'), (Join-Path $app 'storage\app\mpdf'), (Join-Path $app 'storage\app\backups') -Recurse -Force -ErrorAction SilentlyContinue
Get-ChildItem (Join-Path $app 'storage\app\private'), (Join-Path $app 'storage\app\public') -ErrorAction SilentlyContinue |
    Where-Object { $_.Name -ne '.gitignore' } | Remove-Item -Recurse -Force

Push-Location $app
try {
    Step 'Installing production PHP dependencies'
    & composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-scripts --quiet
    if ($LASTEXITCODE -ne 0) { throw 'composer install failed' }
    Step 'Building the screens'
    Copy-Item (Join-Path $root 'node_modules') (Join-Path $app 'node_modules') -Recurse -Force
    & npm run build --silent
    if ($LASTEXITCODE -ne 0) { throw 'npm run build failed' }
    Remove-Item (Join-Path $app 'node_modules') -Recurse -Force
} finally { Pop-Location }
# Source of the screens is not needed on the server; the built files are in public/build.
Remove-Item (Join-Path $app 'resources\js'), (Join-Path $app 'resources\css'), (Join-Path $app 'package.json'), (Join-Path $app 'package-lock.json'), (Join-Path $app 'vite.config.ts'), (Join-Path $app 'tsconfig.json') -Recurse -Force -ErrorAction SilentlyContinue
# The PDF export uses three font families only (D-060); the rest of mPDF's fonts stay out, as in the desktop build.
Get-ChildItem (Join-Path $app 'vendor\mpdf\mpdf\ttfonts') -File |
    Where-Object { $_.Name -notmatch '^(FreeSans|FreeSerif|DejaVuSans)' -and $_.Name -notin @('GNUFreeFontinfo.txt', 'DejaVuinfo.txt') } | Remove-Item -Force

Step 'Writing the .env template and the post-upload commands'
@(
    '# AUSHVAN hosting settings. Copy to .env next to artisan and fill in the four MYSQL lines and the two domain lines.',
    'APP_NAME="AUSHVAN"',
    'APP_ENV=production',
    'APP_KEY=',
    'APP_DEBUG=false',
    'APP_URL=https://aushvan.example.com',
    'APP_LOCALE=en',
    "APP_VERSION=$Version",
    'LOG_CHANNEL=daily',
    'LOG_LEVEL=warning',
    'DB_CONNECTION=mysql',
    'DB_HOST=localhost',
    'DB_PORT=3306',
    'DB_DATABASE=cpaneluser_aushvan',
    'DB_USERNAME=cpaneluser_aushvan',
    'DB_PASSWORD=',
    'SESSION_DRIVER=database',
    'SESSION_LIFETIME=720',
    'SESSION_SECURE_COOKIE=true',
    'CACHE_STORE=database',
    'QUEUE_CONNECTION=sync',
    'FILESYSTEM_DISK=local',
    '# The site address as the browser sees it (no https://), so the phones and the PC stay signed in.',
    'SANCTUM_STATEFUL_DOMAINS=aushvan.example.com',
    '# Where the nightly backup zips are kept on the server (outside public_html).',
    'BACKUP_LOCAL_DIR=../aushvan-backups'
) -join "`n" | ForEach-Object { [IO.File]::WriteAllText((Join-Path $app '.env.hosting'), $_ + "`n") } # LF only, no BOM

@(
    '#!/bin/sh',
    '# Run once after uploading (cPanel Terminal, inside the aushvan folder), and again after every update.',
    'set -e',
    'PHP=${PHP:-php}',
    'if [ ! -f .env ]; then cp .env.hosting .env; echo "Copied .env.hosting to .env: fill in the database and domain lines, then run this again."; exit 1; fi',
    'grep -q "^APP_KEY=base64" .env || $PHP artisan key:generate --force --no-interaction',
    '$PHP artisan migrate --force --no-interaction',
    '$PHP artisan config:cache',
    '$PHP artisan route:cache',
    '$PHP artisan view:cache',
    'echo "AUSHVAN is ready. Open the site in a browser to set up the shop."'
) -join "`n" | ForEach-Object { [IO.File]::WriteAllText((Join-Path $app 'post-upload.sh'), $_ + "`n") } # LF only, so sh reads it

New-Item -ItemType Directory -Force $out | Out-Null
$zip = Join-Path $out "aushvan-hosting-$Version.zip"
if (Test-Path $zip) { Remove-Item $zip -Force }
# Windows' tar makes a zip with forward-slash entry names; Compress-Archive writes backslashes, which Linux unzips into one flat folder.
& tar.exe -a -c -f $zip -C $stage aushvan
if ($LASTEXITCODE -ne 0) { throw 'zip failed' }
Step "Hosting package: $zip ($([math]::Round((Get-Item $zip).Length / 1MB)) MB). Follow docs/HOSTING_CPANEL.md."
