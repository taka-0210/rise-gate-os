$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing

# A background PHP helper has no visible owner window. Give the modal picker
# a temporary topmost owner so Windows cannot place it behind the browser.
$owner = New-Object System.Windows.Forms.Form
$owner.Name = 'RiseGateFolderPickerOwner'
$owner.Text = 'RISE GATE フォルダ選択'
$owner.TopMost = $true
$owner.ShowInTaskbar = $false
$owner.FormBorderStyle = [System.Windows.Forms.FormBorderStyle]::None
$owner.StartPosition = [System.Windows.Forms.FormStartPosition]::CenterScreen
$owner.Size = New-Object System.Drawing.Size(1, 1)
$owner.Opacity = 0
$picker = New-Object System.Windows.Forms.FolderBrowserDialog
try {
    $picker.Description = 'ドライブを開いて、3ペインで開発するフォルダを選択してください'
    $picker.RootFolder = [System.Environment+SpecialFolder]::MyComputer
    $picker.ShowNewFolderButton = $true
    $owner.Show()
    $owner.Activate()
    if ($picker.ShowDialog($owner) -eq [System.Windows.Forms.DialogResult]::OK) {
        [Console]::OutputEncoding = New-Object System.Text.UTF8Encoding($false)
        [Console]::Write($picker.SelectedPath)
    }
} finally {
    $picker.Dispose()
    $owner.Close()
    $owner.Dispose()
}