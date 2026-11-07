import fs from 'node:fs/promises';
import path from 'node:path';
import sharp from 'sharp';
import type { Request, Response } from 'express';

export type ImageFormat = 'webp' | 'jpg' | 'png';

export interface ImageSizes {
  small: number;
  semiMedium: number;
  medium: number;
  big: number;
  bigger: number;
}

export interface OptimizeImageParams {
  sku: string;
  directoryName: string;
  imagePath: string;
  baseUrl: string;
  format?: ImageFormat;
  padding?: number;
}

export interface GeneratedImageUrls {
  small: string;
  semiMedium: string;
  medium: string;
  big: string;
  bigger: string;
  seo: string;
}

const DEFAULT_SIZES: ImageSizes = {
  small: 100,
  semiMedium: 200,
  medium: 400,
  big: 800,
  bigger: 1200,
};

/**
 * Renders an image centered on a square background with padding using Sharp
 */
export async function renderSquare(
  inputPath: string,
  destPath: string,
  size: number = 1200,
  format: ImageFormat = 'webp',
  padding: number = 50
): Promise<Buffer> {
  const innerSize = Math.max(1, size - padding * 2);

  // Resize original maintaining aspect ratio within inner container
  const resizedBuffer = await sharp(inputPath)
    .resize(innerSize, innerSize, {
      fit: 'inside',
      withoutEnlargement: false,
    })
    .toBuffer();

  // Create square background with white or transparent fill
  const background =
    format === 'png'
      ? { r: 0, g: 0, b: 0, alpha: 0 }
      : { r: 255, g: 255, b: 255, alpha: 1 };

  let pipeline = sharp({
    create: {
      width: size,
      height: size,
      channels: 4,
      background,
    },
  }).composite([
    {
      input: resizedBuffer,
      gravity: 'centre',
    },
  ]);

  if (format === 'webp') {
    pipeline = pipeline.webp({ quality: 85 });
  } else if (format === 'png') {
    pipeline = pipeline.png({ quality: 85, compressionLevel: 8 });
  } else {
    pipeline = pipeline.jpeg({ quality: 85 });
  }

  const outputBuffer = await pipeline.toBuffer();
  await fs.writeFile(destPath, outputBuffer);
  return outputBuffer;
}

/**
 * Creates rectangular SEO image (1200x628)
 */
export async function renderSeo(
  inputPath: string,
  destPath: string,
  format: ImageFormat = 'webp',
  padding: number = 50
): Promise<Buffer> {
  const width = 1200;
  const height = 628;
  const innerWidth = width - padding * 2;
  const innerHeight = height - padding * 2;

  const resizedBuffer = await sharp(inputPath)
    .resize(innerWidth, innerHeight, {
      fit: 'inside',
      withoutEnlargement: false,
    })
    .toBuffer();

  const background =
    format === 'png'
      ? { r: 0, g: 0, b: 0, alpha: 0 }
      : { r: 255, g: 255, b: 255, alpha: 1 };

  let pipeline = sharp({
    create: {
      width,
      height,
      channels: 4,
      background,
    },
  }).composite([
    {
      input: resizedBuffer,
      gravity: 'centre',
    },
  ]);

  if (format === 'webp') {
    pipeline = pipeline.webp({ quality: 85 });
  } else if (format === 'png') {
    pipeline = pipeline.png({ quality: 85 });
  } else {
    pipeline = pipeline.jpeg({ quality: 85 });
  }

  const outputBuffer = await pipeline.toBuffer();
  await fs.writeFile(destPath, outputBuffer);
  return outputBuffer;
}

/**
 * Optimizes product image into 5 square sizes + 1 SEO rectangle without filename suffixes
 */
export async function optimizeImage({
  directoryName,
  imagePath,
  baseUrl,
  format = 'webp',
  padding = 50,
}: OptimizeImageParams): Promise<GeneratedImageUrls> {
  await fs.mkdir(directoryName, { recursive: true });

  const baseName = path.parse(imagePath).name;
  const folderBasename = path.basename(directoryName);

  // 1. Generate base 1200x1200 square image
  const biggerPath = path.join(directoryName, `${baseName}bigger.${format}`);
  await renderSquare(imagePath, biggerPath, 1200, format, padding);

  // 2. Generate SEO image (1200x628)
  const seoPath = path.join(directoryName, `${baseName}seo.${format}`);
  await renderSeo(imagePath, seoPath, format, padding);

  const urls: GeneratedImageUrls = {
    bigger: `${baseUrl}/${folderBasename}/${baseName}bigger.${format}`,
    seo: `${baseUrl}/${folderBasename}/${baseName}seo.${format}`,
    small: '',
    semiMedium: '',
    medium: '',
    big: '',
  };

  // 3. Generate downscaled square variants from 1200x1200
  const variants: (keyof ImageSizes)[] = ['small', 'semiMedium', 'medium', 'big'];

  for (const variant of variants) {
    const targetSize = DEFAULT_SIZES[variant];
    const destPath = path.join(directoryName, `${baseName}${variant}.${format}`);

    let pipeline = sharp(biggerPath).resize(targetSize, targetSize);

    if (format === 'webp') {
      pipeline = pipeline.webp({ quality: 80 });
    } else if (format === 'png') {
      pipeline = pipeline.png({ quality: 80 });
    } else {
      pipeline = pipeline.jpeg({ quality: 80 });
    }

    await pipeline.toFile(destPath);
    urls[variant] = `${baseUrl}/${folderBasename}/${baseName}${variant}.${format}`;
  }

  return urls;
}

/**
 * Example Express Route Handler for Image Upload & Optimization
 */
export async function handleImageUploadRoute(req: Request, res: Response): Promise<void> {
  try {
    const { sku, directoryName, baseUrl, dbField } = req.body;
    const file = req.file;

    if (!file) {
      res.status(400).json({ error: 'No se subió ningún archivo de imagen' });
      return;
    }

    if (!sku || !directoryName || !baseUrl) {
      res.status(400).json({ error: 'Faltan parámetros requeridos: sku, directoryName, baseUrl' });
      return;
    }

    const urls = await optimizeImage({
      sku,
      directoryName,
      imagePath: file.path,
      baseUrl,
      format: 'webp',
    });

    // Opcional: actualizar campo en base de datos si dbField fue provisto
    // await db.query(`UPDATE products SET ${dbField} = ? WHERE sku = ?`, [JSON.stringify(urls), sku]);

    res.status(200).json({
      success: true,
      sku,
      urls,
    });
  } catch (error) {
    const message = error instanceof Error ? error.message : 'Error al procesar imágenes';
    res.status(500).json({ success: false, error: message });
  }
}
