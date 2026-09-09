param(
    [Parameter(Mandatory=$true)][string]$InstallRoot,
    [string]$ArchivePath = ''
)
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
$destination = Join-Path ([IO.Path]::GetFullPath($InstallRoot)) 'codex'
$executable = Join-Path $destination 'bin\codex.exe'
if (Test-Path -LiteralPath $executable) { return }
if (!(Get-Command tar.exe -ErrorAction SilentlyContinue)) { throw 'Codexの展開にはWindowsのtar.exeが必要です。Windowsを更新して再実行してください。' }
[IO.Directory]::CreateDirectory($destination) | Out-Null
$downloaded = !$ArchivePath
if ($downloaded) {
    $ArchivePath = Join-Path $InstallRoot 'codex-0.153.0.tar.gz'
    Write-Host '公式配布元からCodexを準備しています...'
    Invoke-WebRequest 'https://github.com/openai/codex/releases/download/rust-v0.153.0/codex-package-x86_64-pc-windows-msvc.tar.gz' -UseBasicParsing -OutFile $ArchivePath -TimeoutSec 300
}
if ((Get-FileHash -LiteralPath $ArchivePath -Algorithm SHA256).Hash.ToLowerInvariant() -ne 'dba6a113d7ab279b30772fcdc5d7f352c3a28556a49a1ea311b4ac8603dec807') { throw 'Codexの整合性を確認できません。導入を中止しました。' }
& tar.exe -xzf $ArchivePath -C $destination
if ($LASTEXITCODE -ne 0 -or !(Test-Path -LiteralPath $executable)) { throw 'Codexを展開できませんでした。' }
if ($downloaded) { Remove-Item -LiteralPath $ArchivePath }
Write-Host 'Codexの準備が完了しました。3ペインの「Codexで開発」から接続できます。'