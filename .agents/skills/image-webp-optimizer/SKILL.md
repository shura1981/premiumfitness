---
name: image-webp-optimizer
description: Tool for optimizing web images by resizing and converting them to WebP format. Use when asked to optimize images, reduce image size, convert images to WebP, create responsive image sizes, implement advanced lazy loading, or generate blurry mockup placeholders with transition effects. Requires ImageMagick.
---

# Image WebP Optimizer

## When to Use This Skill
- When users ask to optimize images for a website or landing page.
- When you need to convert `.png` or `.jpg` assets to `.webp` format.
- When responsive image resolutions (e.g., mobile `-sm` and desktop `-xl`) are required.
- When structural updates to HTML require the `<picture>` tag for serving different image sizes efficiently.
- When requested to implement advanced lazy loading with blur transitions, or generate low-resolution mockup images for placeholders.

## Prerequisites
- `magick` (ImageMagick v7+) must be installed on the system. See the [Installation Guide](#installing-imagemagick) below if it is not already available.

## Installing ImageMagick

Before running any script, verify ImageMagick is installed:

```bash
magick --version
```

If the command is not found, follow the instructions for your operating system.

---

### Linux

**Ubuntu / Debian:**
```bash
sudo apt update && sudo apt install imagemagick
```

**Fedora / RHEL / CentOS:**
```bash
sudo dnf install ImageMagick
```

**Arch Linux / Manjaro:**
```bash
sudo pacman -S imagemagick
```

> **Note**: some distros ship ImageMagick v6 via their package manager. Verify v7+ with `magick --version`. If not, install from the [official binary](https://imagemagick.org/script/download.php#linux).

---

### macOS

**Homebrew** (recommended):
```bash
# Install Homebrew if not present:
/bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"

# Install ImageMagick:
brew install imagemagick
```

**MacPorts** (alternative):
```bash
sudo port install ImageMagick
```

---

### Windows

**Option 1 – Chocolatey** (recommended):
```powershell
# Install Chocolatey first (run as Administrator):
Set-ExecutionPolicy Bypass -Scope Process -Force
[System.Net.ServicePointManager]::SecurityProtocol = [System.Net.ServicePointManager]::SecurityProtocol -bor 3072
iex ((New-Object System.Net.WebClient).DownloadString('https://community.chocolatey.org/install.ps1'))

# Install ImageMagick:
choco install imagemagick
```

**Option 2 – winget** (built-in on Windows 10/11):
```powershell
winget install ImageMagick.ImageMagick
```

**Option 3 – Manual installer**: download from [imagemagick.org](https://imagemagick.org/script/download.php#windows). During setup, check **"Add application directory to your system path"**.

> After installing on Windows, open a new PowerShell window and run `magick --version` to confirm.

## Step-by-Step Workflows
1. Identify the target directory and the specific image files to optimize.
2. Determine if custom dimensions are needed (e.g., mobile `-sm`, tablet `-md`, desktop `-xl`). The scripts generate `-sm` (600px) and `-xl` (1200px) variants by default, but this can be overridden.
3. If the user explicitly requests advanced lazy loading with a blur transition, use the `--blur` (Bash) or `-Blur` (PowerShell) flag. This generates an extra 20px wide placeholder image (`-blur.webp`) using ImageMagick.
4. Run the provided optimization script (Bash or PowerShell depending on OS) passing the image file paths. Use the `--sizes` (Bash) or `-Sizes` (PowerShell) flag to customize outputs.
5. The script will create subdirectories for each image and place the resized `.webp` versions inside.
5. Update the HTML source code to use the new `<picture>` wrapper with multiple `<source>` tags corresponding to the generated sizes.
6. **Eliminate original format references** (only when updating HTML paths): after replacing paths, scan the entire HTML file and ensure no `<img src>`, `<source srcset>`, or any other attribute still references the original `.png`, `.jpg`, `.jpeg`, or other non-webp format for images that were optimized. The `<img src>` fallback inside a `<picture>` must point to the `-sm.webp` variant, not the original file.
7. **Apply lazy loading rules** (only when updating HTML paths): determine whether each `<img>` is above the fold (i.e., among the first visible images at page load). The first 1–2 images in the HTML `<body>` — typically hero, header logo, or opening banner — must NOT receive `loading="lazy"`. Every other image below the fold must include `loading="lazy"` to comply with Core Web Vitals (LCP optimization).
8. **Verify alt attributes** (only when updating HTML paths): every `<img>` that is added or modified must have a non-empty `alt` attribute. Decorative images with no meaningful content should use `alt=""` explicitly. Never omit the `alt` attribute.

## Available Scripts

Run the appropriate optimization script for your operating system:

### Linux
Run the [optimize-linux.sh](./scripts/optimize-linux.sh) script using Bash:

```bash
# Example usage (default sizes: sm and xl):
bash .github/skills/image-webp-optimizer/scripts/optimize-linux.sh assets/images/hero.png

# Example usage (custom sizes with blur placeholder):
bash .github/skills/image-webp-optimizer/scripts/optimize-linux.sh --sizes "sm:600 md:900 xl:1200" --blur assets/images/hero.png
```

### macOS
Run the [optimize-mac.sh](./scripts/optimize-mac.sh) script using Bash:

```bash
# Example usage (default sizes: sm and xl):
bash .github/skills/image-webp-optimizer/scripts/optimize-mac.sh assets/images/hero.png

# Example usage (custom sizes with blur placeholder):
bash .github/skills/image-webp-optimizer/scripts/optimize-mac.sh --sizes "sm:600 md:900 xl:1200" --blur assets/images/hero.png
```

### Windows
Run the [optimize-windows.ps1](./scripts/optimize-windows.ps1) script using PowerShell:

```powershell
# Example usage (default sizes):
.\.github\skills\image-webp-optimizer\scripts\optimize-windows.ps1 assets\images\hero.png

# Example usage (custom sizes with blur placeholder):
.\.github\skills\image-webp-optimizer\scripts\optimize-windows.ps1 -Sizes "sm:600","md:900","xl:1200" -Blur assets\images\hero.png
```

## HTML Update Rules

These rules apply **only when the skill updates HTML files** to replace image paths (e.g., `.png` → `.webp`). If the skill is used solely to convert image formats without touching HTML, skip this section.

### No residual original-format references

After updating image paths, do a final sweep of the HTML file and verify that **no optimized image still appears in its original format** (`.png`, `.jpg`, `.jpeg`, etc.) anywhere in the document — including `<img src>`, `<source srcset>`, inline `style` backgrounds, or any other attribute.

The `<img src>` fallback inside a `<picture>` element **must** point to the `-sm.webp` variant, never the original file.

```html
<!-- ✅ Correct: fallback uses -sm.webp -->
<picture>
  <source media="(min-width: 768px)" srcset="hero/hero-xl.webp" type="image/webp">
  <img src="hero/hero-sm.webp" alt="Hero image">
</picture>

<!-- ❌ Wrong: fallback still points to the original .png -->
<picture>
  <source media="(min-width: 768px)" srcset="hero/hero-xl.webp" type="image/webp">
  <img src="hero.png" alt="Hero image">
</picture>
```

After completing all replacements, grep the HTML file for the original filenames (e.g., `hero.png`, `banner.jpg`) to confirm no stale references remain.

### Lazy Loading (Core Web Vitals)

Improper lazy loading is a common LCP (Largest Contentful Paint) regression. Follow this rule strictly:

| Image position in HTML | `loading` attribute |
|------------------------|---------------------|
| First 1–2 `<img>` tags in `<body>` (hero, header logo, opening banner) | **Omit** — do NOT add `loading="lazy"` |
| All other images (below the fold) | **Add** `loading="lazy"` |

**How to identify above-fold images**: scan the HTML top-down and consider the first 1–2 `<img>` elements that appear before any large content sections. When in doubt, treat images inside `<header>`, hero sections, or any section that appears first in the DOM as above-fold.

```html
<!-- ✅ Above fold — no lazy -->
<img src="hero-sm.webp" alt="Hero image">

<!-- ✅ Below fold — lazy required -->
<img src="feature-sm.webp" alt="Feature icon" loading="lazy">
```

### Alt Attribute Verification

Every `<img>` that is inserted or modified must have an `alt` attribute:

- **Meaningful images** (product photos, icons, illustrations): use a descriptive text that conveys the purpose, e.g. `alt="Empaque de 900g de Radical Nutrition"`.
- **Decorative images** (backgrounds, separators with no informational value): use an empty `alt=""` explicitly — never omit it.
- **Never** leave `alt` missing on any `<img>` tag touched by this skill.

```html
<!-- ✅ Descriptive alt -->
<img src="bag-900-sm.webp" alt="Presentación de 900 gramos" loading="lazy">

<!-- ✅ Decorative — empty alt is valid -->
<img src="bg-texture-sm.webp" alt="" loading="lazy">

<!-- ❌ Missing alt — invalid -->
<img src="feature-sm.webp" loading="lazy">
```

### Advanced Lazy Loading (Blur Transition)

If the user explicitly requests the advanced lazy load blur transition, use the `-blur.webp` placeholder (generated via the `--blur` script flag) and implement the following HTML/CSS/JS pattern:

**1. HTML markup:**
Wrap your `<picture>` or `<img>` element in a placeholder div that uses the tiny `blur` image as its background.

```html
<div class="blurred-img" style="background-image: url('images/hero/hero-blur.webp')">
  <picture>
    <source media="(min-width: 768px)" srcset="images/hero/hero-xl.webp" type="image/webp">
    <img src="images/hero/hero-sm.webp" loading="lazy" alt="Hero image">
  </picture>
</div>
```

**2. CSS styling:**
Apply styles so the small background scales up (becoming naturally blurry) and pulse to indicate loading before finally fading into the full-resolution image.

```css
.blurred-img {
  background-repeat: no-repeat;
  background-size: cover;
  position: relative;
}

.blurred-img::before {
  content: "";
  position: absolute;
  inset: 0;
  opacity: 0;
  animation: pulse 2.5s infinite;
  background-color: var(--text-color, #fff);
}

@keyframes pulse {
  0% { opacity: 0; }
  50% { opacity: 0.1; }
  100% { opacity: 0; }
}

.blurred-img.loaded::before {
  animation: none;
  content: none;
}

.blurred-img img {
  opacity: 0;
  transition: opacity 250ms ease-in-out;
}

.blurred-img.loaded img {
  opacity: 1;
}
```

**3. JavaScript interaction:**
Add an event listener so that when the final image is fully downloaded, the `loaded` class is appended to smoothly fade in.

```javascript
const blurredImageDivs = document.querySelectorAll(".blurred-img");
blurredImageDivs.forEach(div => {
  const img = div.querySelector("img");
  
  function loaded() {
    div.classList.add("loaded");
  }

  if (img.complete) {
    loaded();
  } else {
    img.addEventListener("load", loaded);
  }
});
```

