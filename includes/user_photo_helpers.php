<?php
/**
 * Shared photo-upload plumbing for the users admin page and the
 * self-service profile page. Both call save_cropped_photo() with the
 * data URL that Cropper.js produces from a 400x514 passport-ratio canvas.
 */

if (!defined('USER_PHOTO_DIR')) {
    define('USER_PHOTO_DIR', dirname(__DIR__) . '/uploads/user_photo');
}
if (!is_dir(USER_PHOTO_DIR)) @mkdir(USER_PHOTO_DIR, 0775, true);

if (!function_exists('save_cropped_photo')) {
    /**
     * Decode a `data:image/...;base64,...` URL into a file on disk and
     * return the stored path (relative to the repo root), or null on
     * failure. The caller is responsible for writing the path into
     * `users.photo_path` and unlinking the previous file if the save
     * succeeded.
     */
    function save_cropped_photo(string $dataUrl, int $userId): ?string
    {
        if ($dataUrl === '' || !str_starts_with($dataUrl, 'data:image/')) return null;
        $pos = strpos($dataUrl, 'base64,');
        if ($pos === false) return null;
        $bin = base64_decode(substr($dataUrl, $pos + 7), true);
        if ($bin === false || strlen($bin) < 100) return null;
        $isJpeg = str_starts_with($bin, "\xFF\xD8\xFF");
        $isPng  = str_starts_with($bin, "\x89PNG\r\n\x1a\n");
        if (!$isJpeg && !$isPng) return null;
        if (strlen($bin) > 2 * 1024 * 1024) return null;
        $ext  = $isPng ? 'png' : 'jpg';
        $name = $userId . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
        $dest = USER_PHOTO_DIR . '/' . $name;
        if (file_put_contents($dest, $bin) === false) return null;
        return 'uploads/user_photo/' . $name;
    }
}
