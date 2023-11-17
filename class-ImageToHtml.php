<?php

/* 
Requires PHP 7.1+. PHP 8.0+ is recommended.

Usage example
$config = [
    'maxWidth' => 800,
    'maxHeight' => 600,
    'cacheLifetime' => 3600, // 1 hour
    // 'cacheDir' can also be specified if needed
];
$converter = new ImageToHtml($config);
$html = $converter->convertToHtml('path/to/your/image.jpg'); 
*/

class ImageToHtml
{
    const EVENT_SLUG = 'image_to_html_clean_up_cache';
    const CACHE_EXT = '.i2html';
    private $maxWidth;
    private $maxHeight;
    private $cacheDir;
    private $cacheLifetime;

    public function __construct($config = [])
    {
        $this->maxWidth = $config['maxWidth'] ?? null;
        $this->maxHeight = $config['maxHeight'] ?? null;
        $this->cacheDir = isset($config['cacheDir']) ? trailingslashit($config['cacheDir']) : wp_upload_dir()['basedir'] . '/imageToHtmlCache/';
        $this->cacheLifetime = $config['cacheLifetime'] ?? 24 * 60 * 60; // Default to 24 hours

        if (!file_exists($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        } else if(!is_writable($this->cacheDir)) {
            if (!chmod($this->cacheDir, 0755)) {
                throw new Exception("Cache directory is not writable");
            }
        }

        // Schedule a cron to clean up the cache
        $cron_args = [
            'cacheDir' => $this->cacheDir,
        ];
        if (!wp_next_scheduled(self::EVENT_SLUG, $cron_args)) {
            wp_schedule_event(time(), 'daily', self::EVENT_SLUG, $cron_args);
        }
        add_action(self::EVENT_SLUG, __CLASS__ . '::cleanUpCache');
    }

    public function convertToHtml($imagePath)
    {
        if (!file_exists($imagePath)) {
            throw new Exception("Image file does not exist");
        }

        [$effectiveWidth, $effectiveHeight] = $this->calculateEffectiveDimensions($imagePath);
        $cacheFile = $this->getCacheFileName($imagePath, $effectiveWidth, $effectiveHeight);

        if (file_exists($cacheFile)) {
            return file_get_contents($cacheFile);
        }

        $sourceImage = $this->loadImage($imagePath);
        $resizedImage = $this->createResizedImage($sourceImage, $effectiveWidth, $effectiveHeight);
        $html = $this->generateHtml($imagePath, $resizedImage);

        file_put_contents($cacheFile, $html);

        imagedestroy($sourceImage);
        imagedestroy($resizedImage);

        return $html;
    }

    private function calculateEffectiveDimensions($imagePath)
    {
        $sourceImage = $this->loadImage($imagePath);
        [$effectiveWidth, $effectiveHeight] = $this->getResizedDimensions($sourceImage);
        return [$effectiveWidth, $effectiveHeight];
    }

    private function loadImage($sourceImagePath)
    {
        $imageType = exif_imagetype($sourceImagePath);

        switch ($imageType) {
            case IMAGETYPE_JPEG:
                return imagecreatefromjpeg($sourceImagePath);
            case IMAGETYPE_PNG:
                return imagecreatefrompng($sourceImagePath);
            case IMAGETYPE_GIF:
                return imagecreatefromgif($sourceImagePath);
            default:
                throw new Exception("Unsupported image type");
        }
    }

    private function getResizedDimensions($sourceImage)
    {
        $origWidth = imagesx($sourceImage);
        $origHeight = imagesy($sourceImage);

        $newWidth = $this->maxWidth ?? $origWidth;
        $newHeight = $this->maxHeight ?? $origHeight;

        if ($this->maxWidth !== null && $this->maxHeight !== null) {
            $ratio = min($newWidth / $origWidth, $newHeight / $origHeight);
            $newWidth = $origWidth * $ratio;
            $newHeight = $origHeight * $ratio;
        }

        return [$newWidth, $newHeight];
    }

    private function getCacheFileName($imagePath, $effectiveWidth, $effectiveHeight)
    {
        $fileModificationTime = filemtime($imagePath);
        $fileSize = filesize($imagePath);
        $hash = hash('sha256', $imagePath . $fileModificationTime . $fileSize . $effectiveWidth . $effectiveHeight) . self::CACHE_EXT;
        $pattern = $this->cacheDir . '*-' . $hash; // Pattern to match any timestamp with the hash
        $matchingFiles = glob($pattern);

        if (!empty($matchingFiles)) {
            foreach ($matchingFiles as $file) {
                if (!self::isCacheFileExpired($file)) {
                    return $file; // Return the first matching file that is not expired
                }
            }
        }

        // If no file is found, return a path with a new timestamp
        $expirationTimestamp = time() + $this->cacheLifetime; // Current time + cache lifetime
        return $this->cacheDir . $expirationTimestamp . '-' . $hash;
    }

    private static function isCacheFileExpired($fileOrDir)
    {
        $fileName = basename($fileOrDir);
        // Returns 0 if there is no timestamp or if the timestamp is invalid
        $expirationTimestamp = (int) substr($fileName, 0, strpos($fileName, '-'));
        return $expirationTimestamp < time();
    }

    private function createResizedImage($sourceImage, $newWidth, $newHeight)
    {
        // Check if $sourceImage is a valid resource and of type 'gd'
        if (
            !is_resource($sourceImage) 
            || !in_array(get_resource_type($sourceImage), ['gd', 'GdImage'])
        ) {
            throw new Exception("Invalid image resource");
        }

        $newImage = imagecreatetruecolor($newWidth, $newHeight);
        if (!$newImage) {
            throw new Exception("Unable to create a new true color image");
        }

        if (!imagecopyresampled($newImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, imagesx($sourceImage), imagesy($sourceImage))) {
            throw new Exception("Failed to resample the image");
        }

        return $newImage;
    }

    private function generateHtml($imagePath, $img)
    {
        $fileName = $this->getSanitizedFileName($imagePath);
        $fileExtension = pathinfo($imagePath, PATHINFO_EXTENSION);
        $width = imagesx($img);
        $height = imagesy($img);
        $html = '<div class="image ' . $fileExtension . ' image-to-html" data-name="' . $fileName . '" data-ext="' . $fileExtension . '" style="font-size: 0; line-height: 0; width: ' . $width . 'px;">';

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgb = imagecolorat($img, $x, $y);
                $colors = imagecolorsforindex($img, $rgb);
                $html .= '<div style="background-color: rgb(' . $colors['red'] . ', ' . $colors['green'] . ', ' . $colors['blue'] . '); width: 1px; height: 1px; display: inline-block;"></div>';
            }
            $html .= '<div style="clear: both;"></div>';
        }

        $html .= '</div>';
        return $html;
    }

    private function getSanitizedFileName($imagePath)
    {
        $fileName = basename($imagePath);
        if (preg_match('/[^\x20-\x7f]/', $fileName)) {
            throw new Exception("Filename contains non-ASCII characters");
        }
        return preg_replace('/[^a-zA-Z0-9-_]/', '', $fileName);
    }

    public static function cleanUpCache($cacheDir)
    {
        $files = glob($cacheDir . '*-*' . self::CACHE_EXT);
        $currentTime = time();

        foreach ($files as $file) {
            if (self::isCacheFileExpired($file)) {
                unlink($file);
            }
        }
    }
}
