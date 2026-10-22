<?php
/**
 * 图形验证码（GD）
 */

require __DIR__ . '/bootstrap.php';

$length = 4;
$chars  = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
$code   = '';
for ($i = 0; $i < $length; $i++) {
    $code .= $chars[random_int(0, strlen($chars) - 1)];
}
$_SESSION['captcha'] = strtolower($code);

$w = 120;
$h = 40;
$img = imagecreatetruecolor($w, $h);

$bg    = imagecolorallocate($img, 245, 247, 250);
$textC = imagecolorallocate($img, random_int(20, 90), random_int(20, 90), random_int(20, 90));
imagefill($img, 0, 0, $bg);

// 干扰线
for ($i = 0; $i < 4; $i++) {
    $lineC = imagecolorallocate($img, random_int(120, 200), random_int(120, 200), random_int(120, 200));
    imageline($img, random_int(0, $w), random_int(0, $h), random_int(0, $w), random_int(0, $h), $lineC);
}
// 干扰点
for ($i = 0; $i < 60; $i++) {
    $dotC = imagecolorallocate($img, random_int(100, 220), random_int(100, 220), random_int(100, 220));
    imagesetpixel($img, random_int(0, $w - 1), random_int(0, $h - 1), $dotC);
}

for ($i = 0; $i < $length; $i++) {
    imagestring($img, 5, 15 + $i * 25 + random_int(-3, 3), random_int(8, 14), $code[$i], $textC);
}

header('Content-Type: image/png');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
imagepng($img);
imagedestroy($img);
