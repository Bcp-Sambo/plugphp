<?php

/**
 * Upload
 *
 * The shared, secure image-upload validator referenced by the blog,
 * projects, and contact-form SKILL.md files. Implements the PRD §8 upload
 * requirements as a structural control, so modules never hand-roll file
 * handling:
 *
 *   1. Whitelist by REAL sniffed MIME type (finfo) — never the client-sent
 *      Content-Type or the filename extension, both of which are forgeable.
 *   2. Re-encode through GD — the saved file is freshly rendered pixels, so
 *      anything smuggled inside the original bytes (PHP in EXIF, image/script
 *      polyglots) does not survive.
 *   3. Random filename with a server-chosen extension — the user never
 *      controls the stored name or type.
 *   4. Stored under public/uploads/<subdir>/ with the execute bit stripped
 *      (0644). A hardening .htaccess in public/uploads/ additionally blocks
 *      script execution on Apache/cPanel hosts.
 *
 * AI AGENTS: route ALL image uploads through Upload::image(). Never move an
 * uploaded file into a public directory yourself, and never trust
 * $_FILES[...]['type'] or the original filename.
 */
final class Upload
{
    /** Sniffed MIME type => the extension we will store it as. */
    private const ALLOWED = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];

    private const MAX_BYTES = 5 * 1024 * 1024; // 5 MB

    /**
     * Validate, re-encode, and store one uploaded image.
     *
     * @param array  $file   One entry from $_FILES (e.g. $_FILES['featured_image']).
     * @param string $subdir Destination subfolder under public/uploads (e.g. 'projects').
     * @return string        Public web path to the stored file, e.g. "/uploads/projects/ab12….jpg".
     *
     * @throws RuntimeException on any validation or processing failure — the
     *         caller should catch this and surface a friendly message.
     */
    public static function image(array $file, string $subdir): string
    {
        if (!extension_loaded('gd')) {
            throw new RuntimeException('Image uploads require the GD extension, which is not installed.');
        }

        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('No file was uploaded, or the upload failed.');
        }

        $tmp = $file['tmp_name'] ?? '';
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            // is_uploaded_file guards against being tricked into reading an
            // arbitrary server path (e.g. /etc/passwd) as if it were an upload.
            throw new RuntimeException('Invalid upload.');
        }

        if ((int) ($file['size'] ?? 0) > self::MAX_BYTES) {
            throw new RuntimeException('Image is too large (maximum 5 MB).');
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!is_string($mime) || !isset(self::ALLOWED[$mime])) {
            throw new RuntimeException('Unsupported image type. Allowed: JPEG, PNG, GIF, WebP.');
        }
        $ext = self::ALLOWED[$mime];

        $image = self::loadImage($tmp, $mime);
        if (!$image instanceof \GdImage) {
            throw new RuntimeException('Could not read the image.');
        }

        try {
            $dir = self::ensureDir($subdir);
            $filename = bin2hex(random_bytes(16)) . '.' . $ext;
            $destPath = $dir . '/' . $filename;

            self::saveImage($image, $destPath, $mime);
        } finally {
            imagedestroy($image);
        }

        @chmod($destPath, 0644); // never executable

        return '/uploads/' . self::safeSubdir($subdir) . '/' . $filename;
    }

    private static function loadImage(string $path, string $mime): \GdImage|false
    {
        return match ($mime) {
            'image/jpeg' => imagecreatefromjpeg($path),
            'image/png'  => imagecreatefrompng($path),
            'image/gif'  => imagecreatefromgif($path),
            'image/webp' => imagecreatefromwebp($path),
            default      => false,
        };
    }

    private static function saveImage(\GdImage $image, string $destPath, string $mime): void
    {
        // Preserve transparency for the formats that support it.
        if ($mime === 'image/png' || $mime === 'image/webp') {
            imagealphablending($image, false);
            imagesavealpha($image, true);
        }

        $ok = match ($mime) {
            'image/jpeg' => imagejpeg($image, $destPath, 85),
            'image/png'  => imagepng($image, $destPath),
            'image/gif'  => imagegif($image, $destPath),
            'image/webp' => imagewebp($image, $destPath),
            default      => false,
        };

        if ($ok === false) {
            throw new RuntimeException('Could not save the processed image.');
        }
    }

    private static function ensureDir(string $subdir): string
    {
        $dir = self::uploadsRoot() . '/' . self::safeSubdir($subdir);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Upload directory could not be created (check permissions).');
        }
        return $dir;
    }

    /** Whitelist the subfolder name so it can never traverse outside uploads/. */
    private static function safeSubdir(string $subdir): string
    {
        if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $subdir)) {
            throw new InvalidArgumentException("Unsafe upload subdirectory: {$subdir}");
        }
        return $subdir;
    }

    private static function uploadsRoot(): string
    {
        return __DIR__ . '/../public/uploads';
    }
}
