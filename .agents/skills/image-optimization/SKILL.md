---
name: image-optimization
description: 'Creates scripts or endpoints to optimize product images in Node.js and TypeScript. Generates square images in specific sizes (small, semi-medium, medium, big, bigger, seo) without suffixes using Sharp. Use when asked to create an image optimization script or Express endpoint in Node.js/TypeScript.'
license: Complete terms in LICENSE.txt
---

# Image Optimization Script & Endpoint Generator (Node.js + TypeScript)

Toolkit for creating scripts or endpoints in Node.js and TypeScript that optimize product images by rendering them inside a square canvas (with padding) and resizing them to predefined dimensions using `sharp`.

## When to Use This Skill

- User asks to create a Node.js/TypeScript script to optimize product images.
- User asks to create an Express endpoint for image processing or upload.
- User wants to process images into specific sizes (small, semi-medium, medium, big, bigger, seo).
- User mentions optimizing images in `.webp`, `.jpg`, or `.png`.

## Core Rules and Constraints

- **NO Suffixes**: Never append suffixes like `-nutramerican-pharma-` or any other brand strings to the generated filenames. The name should just reflect the base name and the size (e.g., `{name}small.webp`, `{name}medium.jpg`, `{name}seo.png`).
- **Formats**: Convert images to modern formats like `.webp` (recommended for performance), as well as `.jpg` and `.png`.
- **Square Canvas**: The base image must be generated or resized within a square canvas (e.g., 1200x1200px) with the main image centered and padding applied.
- **SEO Exception**: The `seo` image is rectangular (1200x628px), not square.
- **Sizes Required**:
  - `small`: 100px
  - `semiMedium`: 200px
  - `medium`: 400px
  - `big`: 800px
  - `bigger`: 1200px
  - `seo`: 1200x628px

## Variables / Inputs to Handle

The generated TypeScript script or Express endpoint must receive the following variables/inputs:

1. **SKU / Product Code**: The unique identifier of the product.
2. **Directory Name**: The target folder path where images will be saved.
3. **Image Data**:
   - For an **Endpoint**: The uploaded image file (via `multer` or buffer).
   - For a **Script**: The file path to the original image.
4. **Base URL**: The base URL of the system where images will be hosted.
5. **Database Field Name** *(For Endpoint only)*: The name of the table field to update with the generated URLs JSON.

## Step-by-Step Workflows

### Generating a TypeScript Script

1. **Inputs parsing**: Ensure the function or CLI accepts SKU, directory path, source image path, base URL, and format.
2. **Create Directories**: Check if destination directory exists using `fs.mkdir(dir, { recursive: true })`.
3. **Image Processing**:
   - Create a 1200x1200px square canvas using `sharp({ create: { ... } }).composite(...)`.
   - Scale down to the 4 smaller square sizes (`100px`, `200px`, `400px`, `800px`).
   - Create 1200x628px rectangular image for SEO.
4. **Output**: Return a typed `GeneratedImageUrls` object containing the resulting URLs.

### Generating an Express Endpoint

1. **Upload Middleware**: Use `multer` to handle multipart file uploads into a temp folder or memory buffer.
2. **Controller Logic**: Validate payload (`sku`, `directoryName`, `baseUrl`) and invoke `optimizeImage(...)`.
3. **Database Update**: Execute query (`UPDATE products SET ...`) with the JSON-serialized image URLs.
4. **Response**: Return HTTP 200 with JSON payload `{ success: true, sku, urls }`.

## Templates Included

- [Node.js / TypeScript Optimizer Template](./templates/node-optimizer.ts) - Complete typed implementation using `sharp` with both script function and Express route handler.

## Gotchas & Troubleshooting

| Issue | Solution |
|-------|----------|
| Filenames include extra brand suffixes | Strictly ensure output names are `${baseName}${size}.${format}` without any extra text. |
| Image touches canvas edges | Use `padding` parameter (default 50px) inside `renderSquare()` and `renderSeo()`. |
| Unsupported input format | Ensure `sharp` supports the input format (JPEG, PNG, WebP, AVIF, TIFF, SVG). |
| Multer temp files not deleted | Use `fs.unlink(file.path)` in a `finally` block after processing. |
