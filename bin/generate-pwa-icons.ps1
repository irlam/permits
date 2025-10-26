Add-Type -AssemblyName System.Drawing

function New-Icon {
    param(
        [string]$Path,
        [int]$Size,
        [string]$Text,
        [int]$FontSize
    )

    $bitmap = New-Object System.Drawing.Bitmap $Size, $Size
    $graphics = [System.Drawing.Graphics]::FromImage($bitmap)
    $graphics.SmoothingMode = 'HighQuality'
    $background = [System.Drawing.Color]::FromArgb(255, 14, 165, 233)
    $graphics.Clear($background)

    $brush = New-Object System.Drawing.SolidBrush ([System.Drawing.Color]::White)
    $font = New-Object System.Drawing.Font('Segoe UI Semibold', $FontSize, [System.Drawing.FontStyle]::Bold, [System.Drawing.GraphicsUnit]::Pixel)
    $rect = New-Object System.Drawing.RectangleF(0, 0, $Size, $Size)
    $format = New-Object System.Drawing.StringFormat
    $format.Alignment = 'Center'
    $format.LineAlignment = 'Center'

    $graphics.DrawString($Text, $font, $brush, $rect, $format)

    $graphics.Dispose()
    $font.Dispose()
    $brush.Dispose()

    $bitmap.Save($Path, [System.Drawing.Imaging.ImageFormat]::Png)
    $bitmap.Dispose()
}

$base = "c:\Users\irlam\Desktop\safety tracker\permits"
$pwa  = Join-Path $base 'assets\\pwa'

New-Icon -Path (Join-Path $base 'icon-192.png') -Size 192 -Text 'P' -FontSize 90
New-Icon -Path (Join-Path $base 'icon-512.png') -Size 512 -Text 'P' -FontSize 240
New-Icon -Path (Join-Path $pwa 'icon-192.png') -Size 192 -Text 'P' -FontSize 90
New-Icon -Path (Join-Path $pwa 'icon-512.png') -Size 512 -Text 'P' -FontSize 240
New-Icon -Path (Join-Path $pwa 'icon-32.png')  -Size 32  -Text 'P' -FontSize 18
