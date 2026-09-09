Add-Type -AssemblyName System.Windows.Forms
$picker = New-Object System.Windows.Forms.FolderBrowserDialog
$picker.Description = '3ペインで開発するフォルダを選択してください'
$picker.ShowNewFolderButton = $true
if ($picker.ShowDialog() -eq [System.Windows.Forms.DialogResult]::OK) {
    [Console]::OutputEncoding = New-Object System.Text.UTF8Encoding($false)
    [Console]::Write($picker.SelectedPath)
}
