<?php
// ------------------------------------------------------------
// Renstar Panorama Viewer (static PHP + Pannellum)
// - Scans ./images and one level of subfolders
// - Root view shows folder thumbnails + image thumbnails (excludes welcome.jpg)
// - Clicking a folder switches to that folder's image gallery
// - Deep link: ?dir=Bridge  (no "Up" button shown for deep links)
// - UI nav adds &nav=1 so "Up" appears
// ------------------------------------------------------------

// Error visibility (tune for prod)
ini_set('display_errors', '1');
error_reporting(E_ALL);

// -------------------- Experience Config --------------------
$authorName      = filter_input(INPUT_GET, 'author', FILTER_SANITIZE_SPECIAL_CHARS) ?? 'Emil';  // Default Author Name -- Can be set by URL Param
$siteTitle       = "Renstar Panorama Viewer";                                                   // Default Site Title -- Update as Desired
$description     = "Panorama Photos for All Occasions!";                                        // Default Site Description -- Update as Desired
$defaultZoom     = 105;                                                                         // Default Zoom -- Lower = More Zoomed
$defaultSpin     = -2;                                                                          // Default Spin -- Higher = Faster

// -------------------- File System Config -------------------
$imageFolderName = "images";                                                                    // Default folder name where your panorama images are stored
$allowedExt      = ['jpg', 'jpeg', 'png'];                                                      // Default image types to include in the list of thumbnails
$welcomeFile     = 'welcome.jpg';                                                               // Default panorama image to load -- Hidden from root thumbnails

// ------------------------ Other Config ----------------------
$scheme          = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
$host            = $_SERVER['HTTP_HOST'];
$path            = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
$currentUrl      = $scheme . "://" . $host . $path;
$ogImage         = $currentUrl . '/360-og.jpg';
$imagesDirFs     = __DIR__ . "/$imageFolderName";
$imagesDirWeb    = $imageFolderName;
$defaultZoom     = max(50, min(120, (int)$defaultZoom));

// -------------------- Helpers --------------------
function isAllowedImage(string $file, array $allowedExt): bool {
	$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
	return in_array($ext, $allowedExt, true);
}

function cleanLabel(string $name): string {
	// Strip extension and make "some-file-name" -> "Some File Name"
	$base = preg_replace('/\.[a-z0-9]+$/i', '', $name);
	$base = str_replace(['_', '-'], ' ', $base);
	$base = preg_replace('/\s+/', ' ', trim($base));
	return ucwords(strtolower($base));
}

function slugify(string $s): string {
	$s = strtolower($s);
	$s = preg_replace('/\.[a-z0-9]+$/i', '', $s); // drop extension
	$s = preg_replace('/[^a-z0-9]+/i', '-', $s);
	$s = trim($s, '-');
	return $s !== '' ? $s : 'scene';
}

function listSubdirs(string $root): array {
	$dirs = [];
	if (!is_dir($root)) return $dirs;
	foreach (scandir($root) as $entry) {
		if ($entry[0] === '.') continue;
		$full = $root . DIRECTORY_SEPARATOR . $entry;
		if (is_dir($full)) $dirs[] = $entry;
	}
	natcasesort($dirs);
	return array_values($dirs);
}

function listImagesIn(string $dirFs, array $allowedExt): array {
	$imgs = [];
	if (!is_dir($dirFs)) return $imgs;
	foreach (scandir($dirFs) as $entry) {
		if ($entry[0] === '.') continue;
		if (is_file($dirFs . DIRECTORY_SEPARATOR . $entry) && isAllowedImage($entry, $allowedExt)) {
			$imgs[] = $entry;
		}
	}
	natcasesort($imgs);
	return array_values($imgs);
}

function findActualFileInsensitive(string $needle, array $files): ?string {
	$target = strtolower($needle);
	foreach ($files as $f) {
		if (strtolower($f) === $target) return $f;
	}
	return null;
}

// -------------------- Inputs --------------------
$requestedDir     = filter_input(INPUT_GET, 'dir',   FILTER_SANITIZE_SPECIAL_CHARS) ?? '';
$requestedSceneId = filter_input(INPUT_GET, 'scene', FILTER_SANITIZE_SPECIAL_CHARS) ?? '';
$requestedImage   = filter_input(INPUT_GET, 'image', FILTER_SANITIZE_SPECIAL_CHARS) ?? '';
$fromUiNav        = (filter_input(INPUT_GET, 'nav', FILTER_VALIDATE_INT) === 1);

$subdirs          = listSubdirs($imagesDirFs);
$inSubdir         = false;
$currentDirName   = '';
$currentDirFs     = $imagesDirFs;
$currentDirWeb    = $imagesDirWeb;

$requestedDirRaw = $requestedDir;
$resolvedDir = null;
foreach ($subdirs as $d) {
	if (strcasecmp($requestedDirRaw, $d) === 0) { $resolvedDir = $d; break; }
}

if ($requestedDirRaw !== '' && $resolvedDir !== null) {
	$inSubdir       = true;
	$currentDirName = $resolvedDir; // use actual casing
	$currentDirFs   = $imagesDirFs . DIRECTORY_SEPARATOR . $resolvedDir;
	$currentDirWeb  = $imagesDirWeb . '/' . rawurlencode($resolvedDir);
}

// -------------------- GATHER CONTENT --------------------
// Root mode: folders + images at root (exclude welcome.jpg from thumbnails)
// Subdir mode: images within that folder
$rootImages   = [];
$rootFolders  = [];
$folderImages = [];

$emptySubdir = false;

if ($inSubdir) {
	$folderImages = listImagesIn($currentDirFs, $allowedExt);
	if (empty($folderImages)) {
		$emptySubdir = true; // mark it; don't set $hasScenes here
	}
} else {
	$rootFolders = $subdirs; // show folders as thumbnails
	$rootImages  = listImagesIn($imagesDirFs, $allowedExt);
}


// -------------------- BUILD SCENES --------------------
$scenes = [];
$sceneOrder = [];
$sceneIdByFilename = []; // "file.jpg" -> sceneId
$dedupe = [];

// Add scenes helper
$addScene = function(string $webPath, string $idBase) use (&$scenes, &$sceneOrder, &$dedupe, $defaultZoom) {
	$sceneId = slugify($idBase);
	if (isset($dedupe[$sceneId])) {
		$dedupe[$sceneId]++;
		$sceneId .= '-' . $dedupe[$sceneId];
	} else {
		$dedupe[$sceneId] = 1;
	}
	$scenes[$sceneId] = [
		'type'     => 'equirectangular',
		'panorama' => $webPath,
		'hfov'     => $defaultZoom,
		'pitch'    => 0,
		'yaw'      => 0
	];
	$sceneOrder[] = $sceneId;
	return $sceneId;
};

// In ROOT mode, we can include:
// - welcome.jpg (as initial scene; will not appear in thumbnails)
// - all other root images (as scenes + thumbnails)
// - we do NOT include subfolder images as scenes; those are browsed after clicking folder
$firstSceneId = null;

$welcomeActual = null;
if (!$inSubdir) {
	// If welcome file exists (any case), add it first as the initial scene
	$welcomeActual = findActualFileInsensitive($welcomeFile, $rootImages);
	if ($welcomeActual !== null) {
		$welcomePath = $imagesDirWeb . '/' . rawurlencode($welcomeActual);
		$firstSceneId = $addScene($welcomePath, $welcomeActual);
	}

	// Add other root images (skip the actual welcome file we found)
	foreach ($rootImages as $img) {
		if ($welcomeActual !== null && strcasecmp($img, $welcomeActual) === 0) continue;
		$web = $imagesDirWeb . '/' . rawurlencode($img);
		$sid = $addScene($web, $img);
		$sceneIdByFilename[$img] = $sid;
	}

	if ($firstSceneId === null && !empty($sceneOrder)) {
		$firstSceneId = $sceneOrder[0];
	}

	if ($requestedImage !== '' && isset($sceneIdByFilename[$requestedImage])) {
		$firstSceneId = $sceneIdByFilename[$requestedImage];
	}
	if ($requestedSceneId !== '' && isset($scenes[$requestedSceneId])) {
		$firstSceneId = $requestedSceneId;
	}
} else {
	// SUBDIR mode: only include images from the folder as scenes
	foreach ($folderImages as $img) {
		$web = $currentDirWeb . '/' . rawurlencode($img);
		// Use id base as "dir/filename" to avoid collisions
		$sid = $addScene($web, $currentDirName . '-' . $img);
		$sceneIdByFilename[$img] = $sid;
	}
	// First scene: requested image/scene or first image
	if ($requestedImage !== '' && isset($sceneIdByFilename[$requestedImage])) {
		$firstSceneId = $sceneIdByFilename[$requestedImage];
	} elseif ($requestedSceneId !== '' && isset($scenes[$requestedSceneId])) {
		$firstSceneId = $requestedSceneId;
	} elseif (!empty($sceneOrder)) {
		$firstSceneId = $sceneOrder[0];
	}
}

// Defensive: if an unknown ?scene is passed, ignore it
if ($requestedSceneId !== '' && !isset($scenes[$requestedSceneId])) {
	$requestedSceneId = '';
}

// Final config
$hasScenes = !$emptySubdir && !empty($scenes) && $firstSceneId !== null;

$config = $hasScenes ? [
	'default' => [
		'firstScene'        => $firstSceneId,
		'autoLoad'          => true,
		'sceneFadeDuration' => 800,
		'author'            => $authorName,
		'autoRotate'        => $defaultSpin,
		'minHfov'           => 50,
		'maxHfov'           => 120,
		'compass'           => false
	],
	'scenes' => $scenes
] : [];
$configJson   = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

// -------------------- UI DATA --------------------
function folderThumbUrl(string $dirName): string {
	// Clicking a folder goes to ?dir=Name&nav=1 (UI nav = show Up button)
	return '?dir=' . rawurlencode($dirName) . '&nav=1';
}

function rootUrl(): string { return '?nav=1'; } // returning to root shows Up when you came from UI
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />

  <title><?= $siteTitle; ?></title>

  <meta name="description"         content="<?= $description; ?>">
  <meta name="viewport"            content="width=device-width, initial-scale=1" />

  <meta property="og:url"          content="<?= $currentUrl; ?>">
  <meta property="og:type"         content="website">
  <meta property="og:title"        content="<?= $siteTitle; ?>">
  <meta property="og:description"  content="<?= $description; ?>">
  <meta property="og:image"        content="<?= $ogImage; ?>">

  <meta name="twitter:card"        content="summary_large_image">
  <meta property="twitter:domain"  content="<?= $host; ?>">
  <meta property="twitter:url"     content="<?= $currentUrl; ?>">
  <meta name="twitter:title"       content="<?= $siteTitle; ?>">
  <meta name="twitter:description" content="<?= $description; ?>">
  <meta name="twitter:image"       content="<?= $ogImage; ?>">

  <link rel="canonical"            href="<?= $currentUrl; ?>">
  <link rel="preconnect"           href="https://cdn.jsdelivr.net" crossorigin>
  <link rel="dns-prefetch"         href="https://cdn.jsdelivr.net">

  <link rel="stylesheet"
	href="https://cdn.jsdelivr.net/npm/pannellum@2.5.6/build/pannellum.css"
	integrity="sha384-02yn80EH0cF+s23taAmuhEZ04p3CTbQvV0QZMr2reUxajpfvcLNKlzsPkZwx14mf"
	crossorigin="anonymous">

  <script
    src="https://cdn.jsdelivr.net/npm/pannellum@2.5.6/build/pannellum.js"
    integrity="sha384-S5+w/JlcNAOymqXGNrvzn2F++XsaHTJdex6KE5VbKryfFgqJiRUJOgOkUqaiOZTf"
    crossorigin="anonymous"></script>

  <style>
	:root {
		--bg: #0f0f10;
		--panel: #181a1b;
		--text: #e8e8e8;
		--accent: #00e5ff;
		--accent-dim: #79f1ff;
		--thumb-border: #2a2d2f;
		--thumb-active: #00e5ff;
	}

	html,
	body {
		height: 100%;
		margin: 0;
		background: var(--bg);
		color: var(--text);
		font-family: system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif;
	}

	header {
		padding: .75rem 1rem;
		background: var(--panel);
		border-bottom: 1px solid #222;
	}

	header h1 {
		margin: 0;
		font-size: 1rem;
		letter-spacing: .03em;
		font-weight: 600;
	}

	#panorama {
		width: 100%;
		height: calc(100vh - 170px);
		background: #000;
	}

	.bar {
		display: flex;
		gap: .5rem;
		align-items: center;
		padding: .5rem 1rem;
		background: var(--panel);
		border-top: 1px solid #222;
		overflow-x: auto;
	}

	.thumb,
	.thumb-folder {
		display: inline-flex;
		flex-direction: column;
		align-items: center;
		gap: .35rem;
		background: transparent;
		border: 1px solid var(--thumb-border);
		border-radius: .5rem;
		padding: .35rem .35rem .5rem;
		cursor: pointer;
		user-select: none;
		transition: transform .12s ease, border-color .12s ease;
		min-width: 170px;
		text-decoration: none;
		color: inherit;
	}

	.thumb:focus,
	.thumb-folder:focus {
		outline: none;
		border-color: var(--accent);
	}

	.thumb:hover,
	.thumb-folder:hover {
		transform: translateY(-2px);
		border-color: var(--accent-dim);
	}

	.thumb.active {
		border-color: var(--thumb-active);
		box-shadow: 0 0 0 1px var(--thumb-active) inset;
	}

	.thumb img,
	.thumb-folder img {
		width: 160px;
		height: 90px;
		object-fit: cover;
		object-position: center;
		display: block;
		border-radius: .35rem;
		background: #000;
	}

	.thumb span,
	.thumb-folder span {
		display: block;
		max-width: 160px;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
		font-size: .8rem;
		color: #cfd3d6;
	}

	.up {
		border-style: dashed;
	}

	.empty {
		padding: 2rem 1rem;
		text-align: center;
		color: #bbb;
	}

	@media (max-width: 600px) {
		#panorama {
			height: calc(100vh - 150px);
		}

		.thumb,
		.thumb-folder {
			min-width: 150px;
		}

		.thumb img,
		.thumb-folder img {
			width: 140px;
			height: 78px;
		}
	}
  </style>
</head>
<body>
  <header>
	<h1><?= $siteTitle; ?><?= $inSubdir ? ' — ' . htmlspecialchars(cleanLabel($currentDirName)) : '' ?></h1>
  </header>

  <?php if (!$hasScenes): ?>
	<div class="empty">
	  <p>No panoramas found. Place stitched equirectangular JPG/PNG files in <code><?= htmlspecialchars($imagesDirWeb) ?></code>.</p>
	</div>
  <?php else: ?>
	<div id="panorama"></div>

	<!-- Thumbnail bar -->
	<div class="bar" id="thumbbar" role="list" aria-label="Panorama thumbnails">
	  <?php if (!$inSubdir): ?>
		<!-- Folder thumbnails at ROOT -->
<?php foreach ($rootFolders as $folder): ?>
		  <?php
			// Try to show the first image in the folder as the tile preview
			$folderFs  = $imagesDirFs . DIRECTORY_SEPARATOR . $folder;
			$firstImg  = listImagesIn($folderFs, $allowedExt)[0] ?? null;
		
			if ($firstImg) {
				$thumbSrc = $imagesDirWeb . '/' . rawurlencode($folder) . '/' . rawurlencode($firstImg);
			} else {
				// Fallback to a lightweight inline SVG if the folder has no images
				$thumbSrc = "data:image/svg+xml;charset=utf-8," . rawurlencode(
				  "<svg xmlns='http://www.w3.org/2000/svg' width='320' height='180'>
					 <rect width='320' height='180' fill='#0b0b0c'/>
					 <path d='M30 65h90l10 12h160a10 10 0 0 1 10 10v60a10 10 0 0 1-10 10H30a10 10 0 0 1-10-10V75a10 10 0 0 1 10-10z' fill='#2b7'/>
				   </svg>"
				);
			}
		  ?>
		  <a role="listitem" class="thumb-folder" href="<?= folderThumbUrl($folder) ?>" aria-label="Open folder <?= htmlspecialchars(cleanLabel($folder)) ?>">
			<img src="<?= htmlspecialchars($thumbSrc) ?>" alt="" loading="lazy" decoding="async" width="160" height="90">
			<span><?= htmlspecialchars(cleanLabel($folder)) ?></span>
		  </a>
		<?php endforeach; ?>

		<!-- Image thumbnails at ROOT (excluding welcome.jpg) -->
		<?php
		  // Build map from filename -> sceneId to know which is active
		  foreach ($sceneIdByFilename as $file => $sid):
			  if (isset($welcomeActual) && $welcomeActual !== null && strcasecmp($file, $welcomeActual) === 0) continue; // hide welcome from thumbnails
			  $web = $imagesDirWeb . '/' . rawurlencode($file);
			  $label = cleanLabel($file);
			  $active = ($sid === $firstSceneId) ? ' active' : '';
		?>
		  <button role="listitem" class="thumb<?= $active ?>" data-scene="<?= htmlspecialchars($sid) ?>" aria-label="Load <?= htmlspecialchars($label) ?>" <?= $active ? 'aria-current="true"' : '' ?>>
			<img src="<?= htmlspecialchars($web) ?>" alt="<?= htmlspecialchars($label) ?>" loading="lazy" decoding="async" width="160" height="90">
			<span><?= htmlspecialchars($label) ?></span>
		  </button>
		<?php endforeach; ?>

	  <?php else: ?>
		<!-- Subdir thumbnails -->
<?php if ($fromUiNav): ?>
		  <!-- Up button only when navigated via UI (not for deep links) -->
		  <a role="listitem" class="thumb-folder up" href="<?= rootUrl() ?>" aria-label="Up one level">
			<img
			  src="data:image/svg+xml;charset=utf-8,<?= rawurlencode('<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'320\' height=\'180\'><rect width=\'320\' height=\'180\' fill=\'#0b0b0c\'/><polygon points=\'160,40 80,120 120,120 120,160 200,160 200,120 240,120\' fill=\'#eee\'/></svg>') ?>"
			  alt=""
			  loading="lazy" decoding="async" width="160" height="90">
			<span>Up</span>
		  </a>
		<?php endif; ?>
		<?php foreach ($sceneIdByFilename as $file => $sid):
			  $web   = $currentDirWeb . '/' . rawurlencode($file);
			  $label = cleanLabel($file);
			  $active = ($sid === $firstSceneId) ? ' active' : '';
		?>
		  <button role="listitem" class="thumb<?= $active ?>" data-scene="<?= htmlspecialchars($sid) ?>" aria-label="Load <?= htmlspecialchars($label) ?>" <?= $active ? 'aria-current="true"' : '' ?>>
			<img src="<?= htmlspecialchars($web) ?>" alt="<?= htmlspecialchars($label) ?>" loading="lazy" decoding="async" width="160" height="90">
			<span><?= htmlspecialchars($label) ?></span>
		  </button>
		<?php endforeach; ?>
	  <?php endif; ?>
	</div>

	<script>
	  // Pannellum configuration generated by PHP
	  const CONFIG = <?= $configJson ?>;
	  const viewer = pannellum.viewer('panorama', { ...CONFIG });

	  // Stop spin on first interaction
		['mousedown','touchstart','wheel','keydown'].forEach(evt =>
		  viewer.getContainer().addEventListener(evt, () => viewer.stopAutoRotate(), { once:true })
		);

	  // Thumbnail interactions (only for image buttons; folder links are anchors)
	  const thumbbar = document.getElementById('thumbbar');
	  const thumbs = Array.from(thumbbar.querySelectorAll('.thumb'));

	  function setActiveThumb(id) {
		  thumbs.forEach(btn => {
			const on = btn.dataset.scene === id;
			btn.classList.toggle('active', on);
			if (on) btn.setAttribute('aria-current','true'); else btn.removeAttribute('aria-current');
		  });
		}

	  thumbs.forEach(btn => {
		btn.addEventListener('click', () => {
		  const id = btn.dataset.scene;
		  if (viewer.getScene() !== id) {
			viewer.loadScene(id);
			setActiveThumb(id);
			// Preserve dir if present; set scene param
			const url = new URL(window.location.href);
			url.searchParams.set('scene', id);
			history.replaceState(null, '', url.toString());
		  }
		});
	  });

	  viewer.on('scenechange', () => {
		viewer.stopAutoRotate();
		setActiveThumb(viewer.getScene());
	  });

	  window.addEventListener('resize', () => {
		try { viewer.resize(); } catch (e) {}
	  });
	</script>
  <?php endif; ?>
</body>
</html>
