$ErrorActionPreference = 'Stop'
$protocol = 'HKCU:\Software\Classes\risegate-dev'
$commandKey = $protocol + '\shell\open\command'
if (Test-Path -LiteralPath $commandKey) {
    $command = (Get-Item -LiteralPath $commandKey).GetValue('')
    $launcher = Join-Path $PSScriptRoot 'launch.ps1'
    if ($command.Contains('"' + $launcher + '"')) {
        Remove-Item -LiteralPath $protocol -Recurse
    }
}
Write-Host 'この開発用ツールの起動登録を解除しました。Windows再起動後にRiseGateDevフォルダとショートカットを削除してください。開発したアプリのフォルダは削除しないでください。'
