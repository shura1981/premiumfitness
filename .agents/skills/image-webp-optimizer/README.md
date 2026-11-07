# Image WebP Optimizer Skill

Una herramienta automatizada para optimizar imágenes web pesadas, redimensionándolas en múltiples resoluciones y convirtiéndolas a formato WebP moderno y ligero de alta calidad. Creado especialmente para facilitar el uso nativo de las imágenes responsivas con la etiqueta HTML `<picture>`.

## Características
- Compresión y conversión automática hacia `.webp` sin pérdida perceptible de calidad.
- Generación de múltiples resoluciones simultáneamente (Ej: `-sm` y `-xl`).
- Soporta anchos 100% personalizables mediante argumentos desde la terminal.
- Multiplataforma: Soporte para Linux, macOS (vía Bash) y Windows (vía PowerShell).
- Organización automática: Crea carpetas con el nombre de la imagen y aloja dentro sus diferentes versiones.

## Prerrequisitos

Para el correcto funcionamiento, debes contar con **ImageMagick** (v7 o superior) y el comando `magick` disponible en el sistema.

- **macOS (Homebrew)**: `brew install imagemagick`
- **Linux (Ubuntu/Debian)**: `sudo apt install imagemagick`
- **Windows**: Descarga el instalador directamente desde [imagemagick.org](https://imagemagick.org/script/download.php) (Asegúrate de agregar ImageMagick al `%PATH%` de Windows al momento de instalar).

---

## Cómo utilizarlo

Dependiendo de tu entorno de desarrollo, utiliza el script designado para tu sistema operativo. El argumento principal siempre será la(s) ruta(s) de la imagen o imágenes que quieras procesar:

###  macOS
```bash
bash .github/skills/image-webp-optimizer/scripts/optimize-mac.sh assets/images/hero.png
```

### 🐧 Linux
```bash
bash .github/skills/image-webp-optimizer/scripts/optimize-linux.sh assets/images/hero.png
```

### 🪟 Windows (PowerShell)
```powershell
.\.github\skills\image-webp-optimizer\scripts\optimize-windows.ps1 assets\images\hero.png
```

---

## Opciones Avanzadas (Tamaños Dinámicos y Libres)

Por defecto, los scripts generan dos medidas: 
- `-sm` a 600 píxeles. 
- `-xl` a 1200 píxeles. 

Sin embargo, la herramienta es **100% libre**. No necesitas encasillarte en resoluciones estándar o acrónimos convencionales. Puedes crear tantas variaciones como necesites con nombres completamente inventados y anchos exactos.

Para anular los valores por defecto y determinar las medidas que tú quieras, introduce tus configuraciones bajo el siguiente formato **`nuevo_nombre:píxeles_de_ancho`**:

**Ejemplo pidiendo un formato "mini" (200px) y uno "gigante" (2500px) en macOS / Linux:**
```bash
bash .github/skills/image-webp-optimizer/scripts/optimize-linux.sh \
  --sizes "mini:200 gigante:2500" \
  assets/images/foto1.jpg assets/images/foto2.png
```

**Ejemplo en PowerShell (Windows):**
```powershell
.\.github\skills\image-webp-optimizer\scripts\optimize-windows.ps1 \
  -Sizes "mini:200","gigante:2500" \
  assets/images/foto1.jpg assets/images/foto2.png
```

¡Y listo! Al finalizar el proceso, gracias a esta configuración manual, verás que junto a la imagen original se han generado versiones personalizadas como `foto1-mini.webp` a 200px y `foto1-gigante.webp` a 2500px.
