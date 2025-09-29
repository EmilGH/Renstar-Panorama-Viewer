<?php
declare(strict_types=1);

/**
 * thumbs.php — safe thumbnail generator for images under ./images
 *
 * Usage:
 *   thumbs.php?src=images/bridge/example.jpg&w=160&h=90
 * Optional:
 *   &debug=1   -> returns plain-text error details on failure
 *
 * Security:
 * - Only serves files inside ./images
 * - Blocks traversal via realpath containment check
 * - Accepts only safe path characters
 * Performance:
 * - Caches generated JPEG thumbnails under ./cache/thumbs
 * - Prefers Imagick for large sources; falls back to GD
 */

// -------------------- Config --------------------
$imagesWebRoot = 'images';                  // web path prefix allowed in ?src=
$imagesFsRoot  = __DIR__ . '/images';       // filesystem root for allowed images
$minW = 40;  $maxW = 640;                   // clamp bounds
$minH = 40;  $maxH = 640;
$jpegQuality = 82;                          // output quality for generated JPEG thumbs

// -------------------- Inputs --------------------
$debug  = isset($_GET['debug']);
$srcRaw = $_GET['src'] ?? '';
$w = max($minW, min($maxW, (int)($_GET['w'] ?? 160)));
$h = max($minH, min($maxH, (int)($_GET['h'] ?? 90)));

$ORIG_MEMORY_LIMIT = ini_get('memory_limit');        // e.g., "256M", "128M", or "-1"
$ORIG_MAX_EXEC     = ini_get('max_execution_time');  // seconds

@ini_set('memory_limit', '1024M');      // adjust down if your host complains
@ini_set('max_execution_time', '30');   // avoid runaway resizes (tweak as needed)

register_shutdown_function(function() use ($ORIG_MEMORY_LIMIT, $ORIG_MAX_EXEC) {
	if ($ORIG_MEMORY_LIMIT !== false && $ORIG_MEMORY_LIMIT !== '') {
		@ini_set('memory_limit', $ORIG_MEMORY_LIMIT);
	}
	if ($ORIG_MAX_EXEC !== false && $ORIG_MAX_EXEC !== '') {
		@ini_set('max_execution_time', (string)$ORIG_MAX_EXEC);
	}
});

function fail(int $code, string $msg, bool $debug): void {
	http_response_code($code);
	if ($debug) {
		header('Content-Type: text/plain; charset=utf-8');
		echo $msg;
	}
	exit;
}

if ($srcRaw === '') fail(400, 'Missing src', $debug);

// -------------------- Normalize & Validate Path --------------------
// Allow optional leading slash (accept /images/... or images/...)
$srcRaw = ltrim($srcRaw, '/');

// Decode percent-encoded characters; callers may pass rawurlencoded segments
$srcDecoded = urldecode($srcRaw);

// Allow only safe characters and require it begins with images/
$pattern = '#^' . preg_quote($imagesWebRoot, '#') . '/[A-Za-z0-9._/\- ]+$#';
if (!preg_match($pattern, $srcDecoded)) {
	fail(400, "Bad src (pattern): $srcRaw", $debug);
}

// Normalize slashes
$srcDecoded = str_replace('\\', '/', $srcDecoded);

// Map to filesystem; drop the leading "images/" from the decoded path
$imagesRootReal = realpath($imagesFsRoot);
if ($imagesRootReal === false) {
	fail(500, "Server config error: images root not found", $debug);
}
$relPath  = substr($srcDecoded, strlen($imagesWebRoot) + 1);
$fullPath = realpath($imagesRootReal . '/' . $relPath);

// Containment & existence checks
if ($fullPath === false || strpos($fullPath, $imagesRootReal) !== 0) {
	fail(400, "Bad src (realpath): $srcDecoded", $debug);
}
if (!is_file($fullPath)) {
	fail(404, "Not found: $srcDecoded", $debug);
}

// -------------------- Caching --------------------
$cacheDir = __DIR__ . '/cache/thumbs';
@mkdir($cacheDir, 0755, true);
$cacheKey = md5($srcDecoded . "|$w|$h");
$cache    = "$cacheDir/$cacheKey.jpg";
$sourceMtime = @filemtime($fullPath);

// Rebuild cache if missing or source newer
if (!is_file($cache) || ($sourceMtime && @filemtime($cache) < $sourceMtime)) {
	[$ow, $oh] = @getimagesize($fullPath) ?: [0, 0];
	if ($ow <= 0 || $oh <= 0) fail(415, "Unsupported or unreadable image", $debug);

	// Target dimensions with aspect fit
	$ratio = min($w / $ow, $h / $oh);
	$tw = max(1, (int) floor($ow * $ratio));
	$th = max(1, (int) floor($oh * $ratio));

	$ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));

	// ---------- Prefer Imagick when available (handles huge images well)
	$done = false;
	if (extension_loaded('imagick')) {
		try {
			$img = new Imagick();
			$img->readImage($fullPath);
			// Reset orientation based on EXIF, if any
			if (method_exists($img, 'setImageOrientation')) {
				$img->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
			}
			// Efficient resize while preserving aspect
			$img->thumbnailImage($tw, $th, /*bestfit*/ true);
			$img->setImageFormat('jpeg');
			$img->setImageCompressionQuality($jpegQuality);
			$img->writeImage($cache);
			$img->destroy();
			$done = is_file($cache);
		} catch (Throwable $e) {
			// Fall through to GD
			$done = false;
		}
	}

	// ---------- GD fallback (may need more memory for very large sources)
	if (!$done) {
		if (!function_exists('imagecreatefromjpeg')) {
			fail(500, "GD not available (imagecreatefromjpeg missing)", $debug);
		}

		// Attempt to lift memory cap for large sources (ignore errors)
		$currentLimit = ini_get('memory_limit');
		if ($currentLimit && $currentLimit !== '-1') {
			@ini_set('memory_limit', '1024M'); // adjust to suit host limits
		}

		// Refuse absurdly large images to avoid OOM if Imagick isn't present
		$maxPixels = 12000 * 6000; // ~72 MP ceiling; tweak if needed
		if (($ow * $oh) > $maxPixels) {
			fail(413, "Source too large for GD ($ow×$oh). Install Imagick or reduce size.", $debug);
		}

		switch ($ext) {
			case 'png':  $im = @imagecreatefrompng($fullPath);  break;
			case 'jpeg':
			case 'jpg':  $im = @imagecreatefromjpeg($fullPath); break;
			case 'webp': $im = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($fullPath) : null; break;
			case 'avif': $im = function_exists('imagecreatefromavif') ? @imagecreatefromavif($fullPath) : null; break;
			default:     $im = null;
		}
		if (!$im) fail(415, "Unsupported format for GD: .$ext", $debug);

		$dst = imagecreatetruecolor($tw, $th);
		imagecopyresampled($dst, $im, 0, 0, 0, 0, $tw, $th, $ow, $oh);

		@imagejpeg($dst, $cache, $jpegQuality);
		imagedestroy($im);
		imagedestroy($dst);

		if (!is_file($cache)) fail(500, "Failed to write cache (GD)", $debug);
	}
}

// -------------------- Response --------------------
header('Cache-Control: public, max-age=31536000, immutable');
header('Content-Type: image/jpeg');

// Simple ETag for client revalidation
$etag = '"' . md5_file($cache) . '"';
header("ETag: $etag");
if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
	http_response_code(304);
	exit;
}

readfile($cache);
