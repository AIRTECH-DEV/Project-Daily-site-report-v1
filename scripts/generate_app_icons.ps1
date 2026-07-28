Add-Type -AssemblyName System.Drawing

$workspacePath = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$sourcePath = Join-Path $workspacePath 'assets\images\vakharia-airtech-logo.png'
$masterPath = Join-Path $workspacePath 'assets\images\pms-app-icon.png'
$resourcePath = Join-Path $workspacePath 'android\app\src\main\res'

$sourceImage = [System.Drawing.Bitmap]::FromFile($sourcePath)
$symbolRect = [System.Drawing.Rectangle]::new(28, 28, 236, 236)
$symbolImage = [System.Drawing.Bitmap]::new($symbolRect.Width, $symbolRect.Height, [System.Drawing.Imaging.PixelFormat]::Format32bppArgb)
$symbolGraphics = [System.Drawing.Graphics]::FromImage($symbolImage)
$symbolGraphics.DrawImage(
    $sourceImage,
    [System.Drawing.Rectangle]::new(0, 0, $symbolImage.Width, $symbolImage.Height),
    $symbolRect,
    [System.Drawing.GraphicsUnit]::Pixel
)
$symbolGraphics.Dispose()
$sourceImage.Dispose()

# Remove the pale wordmark background so the company symbol sits cleanly on
# Android adaptive-icon backgrounds and launcher masks.
for ($y = 0; $y -lt $symbolImage.Height; $y++) {
    for ($x = 0; $x -lt $symbolImage.Width; $x++) {
        $pixel = $symbolImage.GetPixel($x, $y)
        $isPaleBackground = $pixel.R -gt 226 -and $pixel.G -gt 226 -and $pixel.B -gt 220
        $isDarkWordmark = [Math]::Abs($pixel.R - $pixel.G) -lt 28 `
            -and [Math]::Abs($pixel.G - $pixel.B) -lt 28 `
            -and $pixel.R -lt 225
        if ($isPaleBackground -or $isDarkWordmark) {
            $symbolImage.SetPixel($x, $y, [System.Drawing.Color]::Transparent)
        }
    }
}

function New-IconBitmap {
    param(
        [int]$Size,
        [double]$SymbolScale,
        [bool]$TransparentBackground
    )

    $bitmap = [System.Drawing.Bitmap]::new($Size, $Size, [System.Drawing.Imaging.PixelFormat]::Format32bppArgb)
    $graphics = [System.Drawing.Graphics]::FromImage($bitmap)
    $graphics.CompositingQuality = [System.Drawing.Drawing2D.CompositingQuality]::HighQuality
    $graphics.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
    $graphics.PixelOffsetMode = [System.Drawing.Drawing2D.PixelOffsetMode]::HighQuality
    $graphics.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::HighQuality
    if ($TransparentBackground) {
        $graphics.Clear([System.Drawing.Color]::Transparent)
    } else {
        $graphics.Clear([System.Drawing.Color]::White)
    }

    $symbolSize = [int][Math]::Round($Size * $SymbolScale)
    $offset = [int][Math]::Round(($Size - $symbolSize) / 2)
    $graphics.DrawImage($symbolImage, $offset, $offset, $symbolSize, $symbolSize)
    $graphics.Dispose()
    return $bitmap
}

$master = New-IconBitmap -Size 1024 -SymbolScale 0.72 -TransparentBackground $false
$master.Save($masterPath, [System.Drawing.Imaging.ImageFormat]::Png)
$master.Dispose()

$legacySizes = @{
    'mipmap-mdpi' = 48
    'mipmap-hdpi' = 72
    'mipmap-xhdpi' = 96
    'mipmap-xxhdpi' = 144
    'mipmap-xxxhdpi' = 192
}

foreach ($entry in $legacySizes.GetEnumerator()) {
    $directory = Join-Path $resourcePath $entry.Key
    $icon = New-IconBitmap -Size $entry.Value -SymbolScale 0.72 -TransparentBackground $false
    $icon.Save((Join-Path $directory 'ic_launcher.png'), [System.Drawing.Imaging.ImageFormat]::Png)
    $icon.Save((Join-Path $directory 'ic_launcher_round.png'), [System.Drawing.Imaging.ImageFormat]::Png)
    $icon.Dispose()
}

$foregroundSizes = @{
    'mipmap-mdpi' = 108
    'mipmap-hdpi' = 162
    'mipmap-xhdpi' = 216
    'mipmap-xxhdpi' = 324
    'mipmap-xxxhdpi' = 432
}

foreach ($entry in $foregroundSizes.GetEnumerator()) {
    $directory = Join-Path $resourcePath $entry.Key
    $foreground = New-IconBitmap -Size $entry.Value -SymbolScale 0.60 -TransparentBackground $true
    $foreground.Save((Join-Path $directory 'ic_launcher_foreground.png'), [System.Drawing.Imaging.ImageFormat]::Png)
    $foreground.Dispose()
}

$symbolImage.Dispose()
Write-Output "Generated PMS app icons from $sourcePath"
