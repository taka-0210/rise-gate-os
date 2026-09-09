$ErrorActionPreference = 'Stop'
$logPath = $null
$setupExit = 1
$zone = [TimeZoneInfo]::FindSystemTimeZoneById('Tokyo Standard Time')
function Write-SetupLog([string]$Message) {
    Write-Host $Message
    if ($script:logPath) {
        $stamp = [TimeZoneInfo]::ConvertTimeFromUtc([DateTime]::UtcNow, $script:zone).ToString('yyyy-MM-dd HH:mm:ss')
        Add-Content -LiteralPath $script:logPath -Value ("[$stamp JST] " + $Message) -Encoding UTF8
    }
}
try {
    foreach ($base in @($env:LOCALAPPDATA, $env:TEMP)) {
        if (!$base) { continue }
        try {
            $logs = Join-Path $base 'RiseGateDev\logs'
            [IO.Directory]::CreateDirectory($logs) | Out-Null
            $stamp = [TimeZoneInfo]::ConvertTimeFromUtc([DateTime]::UtcNow, $zone).ToString('yyyyMMdd-HHmmss')
            $candidate = Join-Path $logs ("setup-$stamp-$PID.log")
            [IO.File]::WriteAllText($candidate, '', (New-Object Text.UTF8Encoding($true)))
            $logPath = $candidate
            break
        } catch {}
    }
    if (!$logPath) { throw 'ログを保存できません。PCの保存領域と書き込み権限を確認してください。' }
    Write-SetupLog '開発用セットアップを開始します。完了までこの画面を閉じないでください。'
    Write-SetupLog ('ログ: ' + $logPath)
    $installer = Join-Path $PSScriptRoot 'install.ps1'
    if (!(Test-Path -LiteralPath $installer)) {
        throw 'install.ps1がありません。ZIPを「すべて展開」し、展開したフォルダから実行してください。'
    }
    & $installer *>&1 | ForEach-Object { Write-SetupLog ([string]$_) }
    $setupExit = 0
    Write-SetupLog 'セットアップ処理が完了しました。ブラウザの接続コードを確認してください。'
} catch {
    $setupExit = 1
    $failure = $_
    # The install script never prints generated connection codes or configuration contents.
    Write-Host 'セットアップが停止しました。以下のエラーを確認してください。' -ForegroundColor Red
    try {
        Write-SetupLog $failure.Exception.Message
        Write-SetupLog $failure.InvocationInfo.PositionMessage
        Write-SetupLog $failure.ScriptStackTrace
    } catch {
        Write-Host $failure.Exception.Message
        Write-Host 'エラーをログへ保存できませんでした。この画面の内容を確認してください。'
    }
} finally {
    if ($logPath) { Write-Host ('ログの保存先: ' + $logPath) }
}
exit $setupExit
