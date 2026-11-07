#!/bin/bash
# Script to optimize images for web (WebP) with custom responsive sizes.

SIZES=("sm:600" "xl:1200")
IMAGES=()

# Parse arguments
while [[ "$#" -gt 0 ]]; do
  case $1 in
    --help)
      echo "Usage: bash optimize.sh [OPTIONS] <image1> <image2> ..."
      echo ""
      echo "Optimizes images by resizing them and converting to WebP format."
      echo "It creates a directory for each image and saves the dimensions inside."
      echo ""
      echo "Options:"
      echo "  --sizes \"<suffix1>:<width1> <suffix2>:<width2>...\""
      echo "          Override default sizes. e.g. --sizes \"sm:600 md:900 xl:1200\""
      echo "  --blur  Generate an additional 'blur' mockup variant (20px width) for progressive loading."
      echo "  --help  Show this help message"
      echo ""
      echo "Example:"
      echo "  bash optimize.sh assets/img1.png"
      echo "  bash optimize.sh --sizes \"sm:600 md:900 lg:1200\" assets/img1.png"
      exit 0
      ;;
    --sizes)
      IFS=' ' read -r -a SIZES <<< "$2"
      shift
      ;;
    --blur)
      BLUR=true
      ;;
    *)
      IMAGES+=("$1")
      ;;
  esac
  shift
done

if [ ${#IMAGES[@]} -eq 0 ]; then
  echo "Error: No input images provided. Use --help for usage."
  exit 1
fi

# Check for ImageMagick dependency
if ! command -v magick &> /dev/null; then
    echo "Error: ImageMagick ('magick') could not be found. Please install it first."
    exit 1
fi

for img_file in "${IMAGES[@]}"; do
  if [ ! -f "$img_file" ]; then
    echo "Warning: File '$img_file' not found, skipping."
    continue
  fi

  filename=$(basename -- "$img_file")
  basename="${filename%.*}"
  dir=$(dirname -- "$img_file")
  target_dir="$dir/$basename"
  
  echo "Processing $img_file..."
  mkdir -p "$target_dir"
  
  for size_config in "${SIZES[@]}"; do
    suffix="${size_config%%:*}"
    width="${size_config##*:}"
    out_file="$target_dir/${basename}-${suffix}.webp"
    
    magick "$img_file" -resize "${width}x" -quality 85 "$out_file"
    echo " -> Created: $out_file (${width}px)"
  done

  if [ "$BLUR" = true ]; then
    out_file="$target_dir/${basename}-blur.webp"
    magick "$img_file" -resize "20x" -quality 50 "$out_file"
    echo " -> Created: $out_file (20px blur placeholder)"
  fi
done

echo "Optimization complete."
