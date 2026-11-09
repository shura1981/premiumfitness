---
name: image-optimization
description: 'Creates scripts or endpoints to optimize product images. Generates square images in specific sizes (small, semi-medium, medium, big, bigger, seo) without suffixes. Use when asked to create an image optimization script or endpoint for Node.js (sharp/canvas) or PHP (imagemagick).'
license: Complete terms in LICENSE.txt
---

# Image Optimization Script & Endpoint Generator

Toolkit for creating scripts or endpoints that optimize product images by rendering them inside a square canvas (with padding) and resizing them to predefined dimensions. This skill applies to both Node.js (`sharp` and `canvassquare` or similar) and PHP (clase `Imagick` — extensión PHP de ImageMagick).

## When to Use This Skill

- User asks to create a script to optimize product images.
- User asks to create an endpoint for image processing or upload.
- User wants to process images in specific sizes (small, semi-medium, medium, big, bigger, seo).
- User mentions optimizing images for Node.js or PHP in the context of products.

## Core Rules and Constraints

- **NO Suffixes**: Never append suffixes like `-nutramerican-pharma-` or any other strings to the generated filenames. The name should just reflect the base name and the size (e.g., `{name}small.webp`, `{name}medium.jpg`, `{name}seo.png`).
- **Formats**: The scripts and endpoints must support converting the images to modern formats like `.webp` (recommended for performance), as well as `.jpg` and `.png`.
- **Square Canvas**: The base image must be generated or resized within a square canvas (e.g., 1200x1200px) with the main image centered. 
- **SEO Exception**: The `seo` image is typically rectangular (e.g., 1200x628), not square.
- **Sizes Required**:
  - `small`: 100px
  - `semi-medium`: 200px
  - `medium`: 400px
  - `big`: 800px
  - `bigger`: 1200px
  - `seo`: 1200x628px

## Variables / Inputs to Handle

The generated script or endpoint must receive the following variables/inputs:

1. **SKU / Product Code**: The unique identifier ofi the product.
2. **Directory Name**: El nombre del directorio sería el nombre de la imagen con el prefijo correspondiente (según solicite el usuario). Dependiendo del caso, esta carpeta será el destino donde se guardarán las imágenes.
3. **Image Data**:
   - For an **Endpoint**: The uploaded image file.
   - For a **Script**: The file path to the original image.
4. **Base URL**: The base URL of the system where images will be hosted.
5. **Database Field Name** *(For Endpoint only)*: The name of the table field that needs to be updated with the generated images JSON/URLs.

## Step-by-Step Workflows

### Generating a Script

1. **Inputs parsing**: Ensure the script accepts the SKU, directory name, image path, and base URL.
2. **Create Directories**: Check if the destination directory exists and create it if necessary.
3. **Image Processing**:
   - Create a square background (e.g., white or transparent based on format) and center the image with padding.
   - Resize to the 5 standard sizes + SEO size.
   - Use `sharp` and a canvas equivalent in Node.js, OR `magick` commands in PHP.
4. **Output**: The script must return or output a JSON object or array containing the resulting URLs of all generated images (concatenating the Base URL + directory path + filename).

### Generating an Endpoint

1. **Inputs parsing**: Ensure the endpoint handles HTTP requests containing the SKU, directory name, uploaded file, base URL, and the target DB field name.
2. **Directory and Upload Handling**: Save the uploaded file temporarily, then process it in the provided directory.
3. **Image Processing**: Same as the script workflow (square canvas + resizing into specific sizes).
4. **Database Update**: Include the logic or a clear placeholder to UPDATE the specified database field with the new image URLs (JSON object) using the SKU as the identifier.
5. **Output**: Return a success response containing the URLs of the processed images.

## Templates included in this Skill

- [Node.js Optimizer template](./templates/node-optimizer.js) - Use this as a starting point when asked for a script or endpoint using Node.js (with `sharp` and `canvas`).
- [PHP Optimizer template](./templates/php-optimizer.php) - Use this as a starting point when asked for a script or endpoint using PHP (con la clase `Imagick` — extensión PHP, **no `exec()`**).

## Gotchas

- **Suffixes**: I cannot stress this enough—**DO NOT include any dynamic or static suffixes** in the output files apart from their size designator and extension. Keep the names pristine.
- **Padding Details**: When placing the image in the square, ensure correct padding is calculated so it doesn't touch the canvas edges.
- **PHP Compat**: En PHP usar **siempre la clase `Imagick`** (extensión PHP), **nunca `exec()` ni `shell_exec()`**. La extensión usa su propia biblioteca de ImageMagick compilada con WebP, independiente del binario del sistema (`magick`/`convert`) que puede no tener WebP. Métodos clave: `resizeImage()`, `newImage()`, `compositeImage()`, `writeImage()`, `destroy()`.
- **Node.js Compat**: Use `sharp` combined with the `canvas` package (if `canvassquare` module structure is needed) or equivalent `sharp({ create: { ... } })` square composition.

## Troubleshooting

| Issue | Solution |
|-------|----------|
| WebP no funciona con `exec('magick ...')` en producción | El binario del sistema puede ser ImageMagick v6 sin WebP. Usar la clase PHP `Imagick` que tiene su propia biblioteca (v7+). |
| `exec()` con exit 127 | Binario `magick` no encontrado en PATH del servidor. Solución: usar clase `Imagick` en su lugar. |
| `exec()` con exit 1 | ImageMagick instalado pero sin el delegate del formato (e.g. WebP). Solución: usar clase `Imagick`. |
| Images not square in PHP | Usar `compositeImage` centrado calculando offsets: `floor((canvas - image) / 2)`. |
| Node.js sharp throws errors | Check that the input image path or buffer is valid before processing. |
| Database field not updated | Verify that the endpoint receives the correct table field name and SKU from the payload. |
