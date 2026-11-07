<#
.SYNOPSIS
Optimizes images for web by resizing them and converting to WebP.

.DESCRIPTION
Optimizes images by resizing them to specified widths and converting them to WebP. 
It creates a directory for each image and saves the different dimension versions inside.
Requires ImageMagick ('magick') to be installed.

.PARAMETER Sizes
Custom sizes format: suffix:width. Defaults to sm:600 and xl:1200.

.PARAMETER ImagePaths
Paths to the images to optimize. Multiple images can be passed as arguments.

.EXAMPLE
.\optimize.ps1 assets\img1.png
.\optimize.ps1 -Sizes "sm:600", "md:900", "xl:1200" assets\img1.png
.\optimize.ps1 -Blur assets\img1.png
#>

param(
    [Parameter(Mandatory=$false, HelpMessage="Custom sizes format: suffix:width (e.g. sm:600 md:900)")]
    [string[]]$Sizes = @("sm:600", "xl:1200"),

    [Parameter(Mandatory=$false, HelpMessage="Generate a 20px blur placeholder variant")]
    [switch]$Blur,

    [Parameter(Position=0, ValueFromRemainingArguments=$true, Mandatory=$true, HelpMessage="Paths to the images to optimize")]
    [string[]]$ImagePaths
)

if (-Not (Get-Command "magick" -ErrorAction SilentlyContinue)) {
    Write-Error "Error: ImageMagick ('magick') could not be found. Please install it first."
    exit 1
}

foreach ($imgPath in $ImagePaths) {
    if (-Not (Test-Path $imgPath -PathType Leaf)) {
        Write-Warning "File '$imgPath' not found, skipping."
        continue
    }

    $file = Get-Item $imgPath
    $basename = $file.BaseName
    $dir = $file.DirectoryName
    $targetDir = Join-Path -Path $dir -ChildPath $basename

    Write-Host "Processing $imgPath..."

    if (-Not (Test-Path $targetDir)) {
        New-Item -ItemType Directory -Force -Path $targetDir | Out-Null
    }

    foreach ($sizeConfig in $Sizes) {
        $parts = $sizeConfig -split ":"
        $suffix = $parts[0]
        $width = $parts[1]
        
        $outFile = Join-Path -Path $targetDir -ChildPath "$basename-$suffix.webp"
        
        magick $imgPath -resize "${width}x" -quality 85 $outFile
        Write-Host " -> Created: $outFile (${width}px)"
    }

    if ($Blur) {
        $outFile = Join-Path -Path $targetDir -ChildPath "$basename-blur.webp"
        magick $imgPath -resize "20x" -quality 50 $outFile
        Write-Host " -> Created: $outFile (20px blur placeholder)"
    }
}

Write-Host "Optimization complete."
