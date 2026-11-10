<?php
/**
 * Public captcha image for the Employer Enquiry form.
 *
 * Generates a 6-character alphanumeric challenge, stores it in the
 * session under the key 'employer_enquiry_captcha', and streams back
 * a distorted PNG. The form (/employers/index.php) compares the
 * user's typed value against the session value on submit.
 *
 * Falls back to a plain PNG if GD isn't compiled with TTF support —
 * the challenge still stays in the session either way. The form
 * also shows a text-math challenge as a second line of defence, so
 * a broken image doesn't break the form.
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
$code = '';
for ($i = 0; $i < 6; $i++) {
    $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
}
$_SESSION['employer_enquiry_captcha'] = $code;

header('Content-Type: image/png');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Larger plate so the TTF glyphs below can be big without clipping —
// the form's .captcha-box img rule sizes it back down proportionally,
// so we're really after sharper, readable characters, not a bigger box.
$width  = 260;
$height = 90;

if (!function_exists('imagecreatetruecolor')) {
    // GD missing — stream a tiny 1x1 transparent PNG. The form's
    // math challenge still protects the submit.
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
    echo $png;
    exit;
}

$im = imagecreatetruecolor($width, $height);
$bg = imagecolorallocate($im, 238, 249, 248);              // c2c-wash
imagefilledrectangle($im, 0, 0, $width, $height, $bg);

// Speckle noise: tiny coloured dots scattered everywhere.
for ($i = 0; $i < 220; $i++) {
    $c = imagecolorallocate($im,
        random_int(140, 220), random_int(170, 220), random_int(180, 220));
    imagesetpixel($im, random_int(0, $width - 1), random_int(0, $height - 1), $c);
}
// Three wavy lines across the plate in C2C-teal tones.
for ($i = 0; $i < 3; $i++) {
    $c = imagecolorallocate($im,
        random_int(15, 90), random_int(120, 180), random_int(130, 190));
    $y1 = random_int(10, $height - 10);
    $y2 = random_int(10, $height - 10);
    imageline($im, 0, $y1, $width, $y2, $c);
}

$ink = imagecolorallocate($im, 13, 55, 120); // c2c-navy

// Draw each character at a jittered position + angle. imagestring()
// is bitmap-only (no rotate), so we prefer imagettftext() when a
// TTF renderer is present. If not, imagestring() gives a usable
// (if less wavy) result.
$glyphW = (int) ($width / (strlen($code) + 1));
$ttfPath = __DIR__ . '/../vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf';
$hasTtf  = function_exists('imagettftext') && is_file($ttfPath);

// Glyphs sized to actually fill the plate — narrower horizontal
// stride (based on cell width, not label width) + a 56pt font so
// each character is tall and the cluster reads at a glance even
// when the browser scales the img down to its CSS height.
$cellW = (int) ($width / strlen($code));
for ($i = 0; $i < strlen($code); $i++) {
    $ch = $code[$i];
    $x  = $i * $cellW + 8 + random_int(-4, 4);
    if ($hasTtf) {
        $y     = (int) ($height * 0.78) + random_int(-4, 4);
        $angle = random_int(-14, 14);
        imagettftext($im, 56, $angle, (int) $x, $y, $ink, $ttfPath, $ch);
    } else {
        // GD built-in font 5 is the largest available in the
        // bitmap fallback — not huge, but readable.
        $y = (int) ($height / 2 - 12) + random_int(-4, 4);
        imagestring($im, 5, (int) $x, $y, $ch, $ink);
    }
}

imagepng($im);
imagedestroy($im);
