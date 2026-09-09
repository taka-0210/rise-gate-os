param(
    [string]$InstallRoot = (Join-Path $env:LOCALAPPDATA 'RiseGateDev'),
    [string]$PhpPath = '',
    [switch]$NoShortcut,
    [switch]$NoRegistration,
    [switch]$SkipCodex,
    [switch]$NoLaunch
)
$ErrorActionPreference = 'Stop'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
if (![Environment]::Is64BitOperatingSystem) { throw 'Windows 64bit が必要です。' }
$InstallRoot = [IO.Path]::GetFullPath($InstallRoot)
[IO.Directory]::CreateDirectory($InstallRoot) | Out-Null
# Do not leave an old in-memory helper running after replacing its files.
$previousConfigPath = Join-Path $InstallRoot 'config.json'
if (Test-Path -LiteralPath $previousConfigPath) {
    $previousConfig = Get-Content -LiteralPath $previousConfigPath -Raw -Encoding UTF8 | ConvertFrom-Json
    $listeners = @(Get-NetTCPConnection -LocalAddress '127.0.0.1' -LocalPort ([int]$previousConfig.port) -State Listen -ErrorAction SilentlyContinue)
    foreach ($listener in $listeners) {
        $runningHelper = Get-CimInstance Win32_Process -Filter ('ProcessId=' + $listener.OwningProcess)
        $expectedHelper = (Join-Path $InstallRoot 'tool\helper.php')
        $commandLine = ([string]$runningHelper.CommandLine).Replace('/','\')
        $helperPattern = '(?:^|\s|")' + [regex]::Escape($expectedHelper) + '(?:"|\s|$)'
        $configPattern = '(?:^|\s|")' + [regex]::Escape($previousConfigPath) + '(?:"|\s|$)'
        if ($runningHelper.ExecutablePath -ne $previousConfig.php -or $commandLine -notmatch $helperPattern -or $commandLine -notmatch $configPattern) {
            throw '接続ポートを別のプログラムが使用しています。更新を中止しました。'
        }
        $children = @(Get-CimInstance Win32_Process -Filter ('ParentProcessId=' + $runningHelper.ProcessId) | Where-Object { $_.Name -ne 'conhost.exe' })
        if ($children.Count) {
            throw '開発アプリまたはCodexが接続中です。作業の完了を待ち、3ペイン左側の開発ツールで「接続を解除」を押してからセットアップを再実行してください。'
        }
        Write-Host '更新を反映するため、待機中の開発ツールを終了します。'
        Stop-Process -Id $runningHelper.ProcessId -ErrorAction Stop
        Wait-Process -Id $runningHelper.ProcessId -Timeout 10 -ErrorAction SilentlyContinue
    }
}
$runtime = Join-Path $InstallRoot 'php'
if (!$PhpPath) {
    $PhpPath = Join-Path $runtime 'php.exe'
    if (!(Test-Path -LiteralPath $PhpPath)) {
        $name = 'php-8.4.25-nts-Win32-vs17-x64.zip'
        $digest = '43a8f67ed2e5223fafb21293c85976361808855405278cef2cf3037c3ae2529c'
        $archive = Join-Path $InstallRoot $name
        Write-Host '公式配布元からPHPを準備しています...'
        try { Invoke-WebRequest ('https://windows.php.net/downloads/releases/' + $name) -UseBasicParsing -OutFile $archive }
        catch { Invoke-WebRequest ('https://windows.php.net/downloads/releases/archives/' + $name) -UseBasicParsing -OutFile $archive }
        if ((Get-FileHash -LiteralPath $archive -Algorithm SHA256).Hash.ToLowerInvariant() -ne $digest) { throw 'PHPの整合性を確認できませんでした。インストールを中止しました。' }
        Expand-Archive -LiteralPath $archive -DestinationPath $runtime -Force
        Remove-Item -LiteralPath $archive
    }
}
$PhpPath = (Resolve-Path -LiteralPath $PhpPath).Path
$env:PATH = [IO.Path]::GetDirectoryName($PhpPath) + ';' + $env:PATH
$ini = Join-Path $InstallRoot 'php.ini'
$extensionPath = (Join-Path ([IO.Path]::GetDirectoryName($PhpPath)) 'ext').Replace('\','/')
$iniText = @"
[PHP]
extension_dir="$extensionPath"
extension=mbstring
extension=pdo_sqlite
extension=sqlite3
extension=zip
date.timezone=Asia/Tokyo
memory_limit=256M
display_errors=0
log_errors=1
"@
[IO.File]::WriteAllText($ini, $iniText, (New-Object Text.UTF8Encoding($false)))
# Use a temporary PHP file: Windows PowerShell does not reliably preserve quotes in -r arguments.
$checkPath = Join-Path $InstallRoot 'check.php'
[IO.File]::WriteAllText($checkPath, '<?php exit(PHP_VERSION_ID >= 80200 && extension_loaded("pdo_sqlite") && extension_loaded("mbstring") && extension_loaded("zip") ? 0 : 1);', (New-Object Text.UTF8Encoding($false)))
$process = Start-Process -FilePath $PhpPath -ArgumentList @('-c', ('"' + $ini + '"'), ('"' + $checkPath + '"')) -WindowStyle Hidden -Wait -PassThru
if ($process.ExitCode -ne 0) {
    Write-Host 'PHPの実行に必要なMicrosoftランタイムを準備します。Windowsから許可を求められた場合は確認してください。'
    $redist = Join-Path $InstallRoot 'vc_redist.x64.exe'
    Invoke-WebRequest 'https://aka.ms/vc14/vc_redist.x64.exe' -UseBasicParsing -OutFile $redist
    $signature = Get-AuthenticodeSignature -LiteralPath $redist
    if ($signature.Status -ne 'Valid' -or $signature.SignerCertificate.Subject -notmatch 'O=Microsoft Corporation') { throw 'Microsoftランタイムの署名を確認できません。導入を中止しました。' }
    $redistProcess = Start-Process -FilePath $redist -ArgumentList @('/install','/passive','/norestart') -WindowStyle Hidden -Wait -PassThru
    if ($redistProcess.ExitCode -notin @(0,3010,1638)) { throw 'Microsoftランタイムの導入が完了しませんでした。管理者に確認してください。' }
    $process = Start-Process -FilePath $PhpPath -ArgumentList @('-c', ('"' + $ini + '"'), ('"' + $checkPath + '"')) -WindowStyle Hidden -Wait -PassThru
    if ($process.ExitCode -ne 0) { throw 'PHPを実行できません。再起動後にセットアップを再実行してください。' }
}
Remove-Item -LiteralPath $checkPath
$tool = Join-Path $InstallRoot 'tool'
[IO.Directory]::CreateDirectory($tool) | Out-Null
foreach ($name in @('helper.php','CodexSession.php','Workspace.php','app-router.php','choose-folder.ps1','launch.ps1','unregister.ps1','templates')) {
    Copy-Item -LiteralPath (Join-Path $PSScriptRoot $name) -Destination $tool -Recurse -Force
}
$configPath = Join-Path $InstallRoot 'config.json'
if (!(Test-Path -LiteralPath $configPath)) {
    $random = New-Object byte[] 32
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    $rng.GetBytes($random)
    $rng.Dispose()
    $token = -join ($random | ForEach-Object { $_.ToString('x2') })
    $settingsPath = Join-Path $PSScriptRoot 'origins.json'
    $parsedOrigins = if (Test-Path -LiteralPath $settingsPath) { Get-Content -LiteralPath $settingsPath -Raw -Encoding UTF8 | ConvertFrom-Json } else { @('https://os.rise-gate.com','http://localhost','http://127.0.0.1') }
    # Strip PowerShell 5.1 pipeline metadata before ConvertTo-Json.
    # Otherwise arrays from ConvertFrom-Json can become {"value":[...],"Count":...}.
    [string[]]$origins = @($parsedOrigins | ForEach-Object { [string]$_ })
    foreach ($origin in $origins) {
        $uri = [Uri]$origin
        if ($uri.Scheme -ne 'https' -and !($uri.Scheme -eq 'http' -and $uri.IsLoopback)) { throw '接続元の設定が不正です。' }
    }
    $configuration = @{ token=$token; origins=$origins; port=41739; php=$PhpPath } | ConvertTo-Json
    [IO.File]::WriteAllText($configPath, $configuration, (New-Object Text.UTF8Encoding($false)))
}
if (!$SkipCodex) {
    $existingCodex = Get-Command codex.exe -ErrorAction SilentlyContinue
    $extensionCodex = @(Get-ChildItem -Path (Join-Path $env:USERPROFILE '.vscode\extensions\openai.chatgpt-*\bin\windows-x86_64\codex.exe') -ErrorAction SilentlyContinue)
    if (!$existingCodex -and !$extensionCodex.Count) {
        & (Join-Path $PSScriptRoot 'install-codex.ps1') -InstallRoot $InstallRoot
    }
}
if (!$NoShortcut) {
    $shell = New-Object -ComObject WScript.Shell
    $shortcut = $shell.CreateShortcut((Join-Path ([Environment]::GetFolderPath('Desktop')) 'RISE GATE 開発用ツール.lnk'))
    $shortcut.TargetPath = Join-Path $PSHOME 'powershell.exe'
    $shortcut.Arguments = '-NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File "' + (Join-Path $tool 'launch.ps1') + '"'
    $shortcut.WindowStyle = 7
    $shortcut.Save()
}
if (!$NoRegistration) {
    $protocol = 'HKCU:\Software\Classes\risegate-dev'
    New-Item -Path ($protocol + '\shell\open\command') -Force | Out-Null
    Set-Item -LiteralPath $protocol -Value 'URL:RISE GATE Development'
    New-ItemProperty -LiteralPath $protocol -Name 'URL Protocol' -Value '' -PropertyType String -Force | Out-Null
    # Do not pass URL parameters to the shell; the protocol only starts our fixed launcher.
    $launchCommand = '"' + (Join-Path $PSHOME 'powershell.exe') + '" -NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File "' + (Join-Path $tool 'launch.ps1') + '"'
    Set-Item -LiteralPath ($protocol + '\shell\open\command') -Value $launchCommand
}
Write-Host 'セットアップ完了。3ペインから接続して保存フォルダを選択してください。'
if (!$NoLaunch) { & (Join-Path $tool 'launch.ps1') }
