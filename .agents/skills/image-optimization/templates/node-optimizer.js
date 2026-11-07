const fs = require('fs/promises');
const path = require('path');
const sharp = require('sharp');
const { createCanvas, loadImage } = require('canvas');

/**
 * Creates a square image with the provided image centered
 */
async function renderSquare(inputPath, destPath, size, format = 'jpg', padding = 50) {
    const canvas = createCanvas(size, size);
    const ctx = canvas.getContext('2d');
    
    ctx.fillStyle = format === 'png' ? 'rgba(0,0,0,0)' : '#FFFFFF';
    ctx.fillRect(0, 0, size, size);
    
    const image = await loadImage(inputPath);
    const scale = Math.min(size / image.naturalWidth, size / image.naturalHeight);
    
    const scaledWidth = image.naturalWidth * scale - padding;
    const scaledHeight = image.naturalHeight * scale - padding;
    
    ctx.drawImage(
        image, 
        (size - scaledWidth) / 2, 
        (size - scaledHeight) / 2, 
        scaledWidth, 
        scaledHeight
    );
    
    const buffer = canvas.toBuffer(`image/${format === 'jpg' ? 'jpeg' : 'png'}`, { quality: 0.9 });
    await fs.writeFile(destPath, buffer);
    return buffer;
}

/**
 * Creates a rectangular SEO image
 */
async function renderSeo(inputPath, destPath, format = 'jpg', padding = 50) {
    const width = 1200;
    const height = 628;
    const canvas = createCanvas(width, height);
    const ctx = canvas.getContext('2d');
    
    ctx.fillStyle = format === 'png' ? 'rgba(0,0,0,0)' : '#FFFFFF';
    ctx.fillRect(0, 0, width, height);
    
    const image = await loadImage(inputPath);
    const scale = Math.min(width / image.naturalWidth, height / image.naturalHeight);
    
    const scaledWidth = image.naturalWidth * scale - padding;
    const scaledHeight = image.naturalHeight * scale - padding;
    
    ctx.drawImage(
        image, 
        (width - scaledWidth) / 2, 
        (height - scaledHeight) / 2, 
        scaledWidth, 
        scaledHeight
    );
    
    const buffer = canvas.toBuffer(`image/${format === 'jpg' ? 'jpeg' : 'png'}`, { quality: 0.9 });
    await fs.writeFile(destPath, buffer);
}

/**
 * Optimizes an image based on specifications
 */
async function optimizeImage({ sku, directoryName, imagePath, baseUrl, format = 'jpg' }) {
    await fs.mkdir(directoryName, { recursive: true });
    
    const baseName = path.parse(imagePath).name; // Using image name + prefix if handled before
    
    const sizes = {
        small: 100,
        semiMedium: 200,
        medium: 400,
        big: 800,
        bigger: 1200
    };
    
    // Generate base 1200x1200 image using Canvas for proper padding
    const fullSizePath = path.join(directoryName, `${baseName}bigger.${format}`);
    await renderSquare(imagePath, fullSizePath, 1200, format, 50);
    
    // Generate SEO image
    const seoPath = path.join(directoryName, `${baseName}seo.${format}`);
    await renderSeo(imagePath, seoPath, format, 50);
    
    const urls = {
        bigger: `${baseUrl}/${path.basename(directoryName)}/${baseName}bigger.${format}`,
        seo: `${baseUrl}/${path.basename(directoryName)}/${baseName}seo.${format}`
    };
    
    // Generate downsized versions based on the high quality rendered square
    for (const [key, size] of Object.entries(sizes)) {
        if (key === 'bigger') continue; // Already generated
        
        const destPath = path.join(directoryName, `${baseName}${key}.${format}`);
        const sharpInstance = sharp(fullSizePath).resize(size);
        
        if (format === 'jpg' || format === 'jpeg') {
            await sharpInstance.jpeg({ quality: 80 }).toFile(destPath);
        } else if (format === 'png') {
            await sharpInstance.png({ quality: 80 }).toFile(destPath);
        } else {
            await sharpInstance.webp({ quality: 80 }).toFile(destPath);
        }
        
        urls[key] = `${baseUrl}/${path.basename(directoryName)}/${baseName}${key}.${format}`;
    }
    
    return urls;
}

module.exports = { optimizeImage };