# Renstar Panorama Viewer

Renstar Panorama Viewer is a lightweight PHP + [Pannellum](https://pannellum.org/) front-end for displaying panoramic images (equirectangular JPG/PNG, 2:1 aspect ratio) on any basic web host.  

It works entirely in the browser — no database, no server-side processing beyond PHP directory scans — making it easy to drop into shared hosting or a static site environment that supports PHP.

---

## Features

- Auto-detects panoramas (`.jpg`, `.jpeg`, `.png`) in an `/images` folder.  
- Supports one level of subdirectories for organizing galleries.  
- `welcome.jpg` loads as the default panorama but is hidden from thumbnails.  
- Clean human-readable labels (`warehouse-dock.jpg` → `Warehouse Dock`).  
- Thumbnail navigation bar with images and folders.  
- Deep-linkable into folders or specific scenes.  
- Optional auto-spin on load.  
- Meta tags for Open Graph & Twitter Card previews.

---

## Installation

1. **Clone or download** this repo into your web root:

   ```bash
   git clone https://github.com/emilgh/Renstar-Panorama-Viewer.git
   ```

2. **Upload images**:  
   Place your stitched panoramas in the `images/` directory.  
   - Supported formats: `.jpg`, `.jpeg`, `.png`  
   - Images should be equirectangular, 2:1 ratio (e.g., 6000×3000).  
   - Optional subdirectories inside `images/` create separate galleries.

3. **Default panorama**:  
   Add a `welcome.jpg` in the root `images/` folder to load by default (this image will not appear in the root thumbnail list).

4. **Deploy**:  
   - Copy the project to any PHP-enabled web server (Apache, Nginx with PHP, Pair, etc.).  
   - Access it in the browser (e.g., `https://example.com/360/`).

---

## URL Parameters

The viewer accepts several query string parameters:

- `?dir=Subfolder`  
  Opens a specific subdirectory (e.g., `?dir=Bridge`).  

- `?image=filename.jpg`  
  Loads a specific image in the current folder.  

- `?scene=scene-id`  
  Loads a specific scene by its internal slug ID (used in deep links).  

- `?nav=1`  
  Indicates navigation came from within the UI (shows an **Up** button when browsing subfolders). Omit for deep links if you don’t want the **Up** option.  

- `?author=Your+Name`  
  Sets the author name displayed in metadata / config.  

---

## Configuration Variables

Edit these at the top of `index.php`:

- `$authorName` — default author name (`Emil`). Can also be overridden via `?author=`.  
- `$siteTitle` — title text for the viewer.  
- `$description` — description for `<meta>` tags.  
- `$defaultZoom` — initial HFOV (horizontal field of view). Lower = zoomed in, higher = zoomed out (typical range: 80–110).  
- `$defaultSpin` — auto-rotation speed on load. Negative = rotate left, positive = rotate right (typical values: -0.5 to -2).  
- `$imageFolderName` — name of the folder where images live (default `images`).  
- `$allowedExt` — allowed image file extensions.  
- `$welcomeFile` — filename for the default panorama to load first (hidden from thumbnails).  

---

## Example URLs

- Root viewer:  
  `https://example.com/360/`

- Open “Bridge” folder:  
  `https://example.com/360/?dir=Bridge`

- Deep link to a specific file:  
  `https://example.com/360/?dir=Bridge&image=warehouse-dock.jpg`

- Deep link to a scene ID:  
  `https://example.com/360/?scene=bridge-warehouse-dock`

- Custom author name:  
  `https://example.com/360/?author=Bridge+Global+Logistics`

---

## Screenshot

![Renstar Panorama Viewer Screenshot](screenshot.png)

---

## License

MIT — use freely, modify, and redistribute.
