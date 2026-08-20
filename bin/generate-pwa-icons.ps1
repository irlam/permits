Add-Type -AssemblyName System.Drawing

# The checked-in root PWA icons are canonical raster exports of /favicon.svg.
# Keep the service-worker copies in sync without recreating the legacy blue P.
$base = "c:\Users\irlam\Desktop\safety tracker\permits"
$pwa  = Join-Path $base 'assets\pwa'

$icon192 = Join-Path $base 'icon-192.png'
$icon512 = Join-Path $base 'icon-512.png'

if (-not (Test-Path $icon192) -or -not (Test-Path $icon512)) {
    throw 'Canonical icon-192.png and icon-512.png are missing. Regenerate them from favicon.svg before running this helper.'
}

New-Item -ItemType Directory -Force -Path $pwa | Out-Null
Copy-Item -Force $icon192 (Join-Path $pwa 'icon-192.png')
Copy-Item -Force $icon512 (Join-Path $pwa 'icon-512.png')

$source = [System.Drawing.Image]::FromFile($icon192)
try {
    $small = New-Object System.Drawing.Bitmap 32, 32
    try {
        $graphics = [System.Drawing.Graphics]::FromImage($small)
        try {
            $graphics.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
            $graphics.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::HighQuality
            $graphics.PixelOffsetMode = [System.Drawing.Drawing2D.PixelOffsetMode]::HighQuality
            $graphics.DrawImage($source, 0, 0, 32, 32)
        }
        finally {
            $graphics.Dispose()
        }
        $small.Save((Join-Path $pwa 'icon-32.png'), [System.Drawing.Imaging.ImageFormat]::Png)
    }
    finally {
        $small.Dispose()
    }
}
finally {
    $source.Dispose()
}
