# Permit System branding is SVG-only.
#
# The canonical default logo, favicon and PWA artwork is /favicon.svg.
# Do not regenerate the retired blue-square/white-P PNG files.

$base = "c:\Users\irlam\Desktop\safety tracker\permits"
$favicon = Join-Path $base 'favicon.svg'

if (-not (Test-Path $favicon)) {
    throw 'favicon.svg is missing. Restore the canonical hard-hat/check logo before deployment.'
}

Write-Host 'No raster icon generation is required.'
Write-Host ('Canonical Permit System artwork: ' + $favicon)
