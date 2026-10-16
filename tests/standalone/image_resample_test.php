<?php
// ImageWorker: redimensiona só com imagecopyresampled (o imagescale BICUBIC/BICUBIC_FIXED/WEIGHTED4 retorna
// false na libgd do Void; adr/revisao-pr106-r2.md). Original de 4000 px em jpg, png (com alpha) e webp:
// process() gera as 3 larguras (320/800/1920) ok, com altura proporcional, e o WebP mantém o alpha.
require __DIR__ . '/lib.php';
t_temp_db();
$owner = t_seed_owner();
require_once CL_ROOT . '/src/PhotoStorage.php';
require_once CL_ROOT . '/src/PhotoRepository.php';
require_once CL_ROOT . '/src/ImageWorker.php';
$tmp = sys_get_temp_dir() . '/cl-resample-' . bin2hex(random_bytes(3));
mkdir($tmp, 0700);
PhotoStorage::$originals = $tmp . '/storage/fotos/originais';
PhotoStorage::$public = $tmp . '/public/uploads/fotos';
$hid = (int) $owner['house_id'];

echo 'GD ' . (gd_info()['GD Version'] ?? '?') . "\n";
$W = 4000;
$H = 3000;
foreach (['jpg', 'png', 'webp'] as $i => $fmt) {
    $img = imagecreatetruecolor($W, $H);
    imagealphablending($img, false);
    imagesavealpha($img, true);
    imagefilledrectangle($img, 0, 0, $W - 1, $H - 1, imagecolorallocate($img, 30, 120, 200));
    // canto superior esquerdo transparente (só conta no png/webp)
    imagefilledrectangle($img, 0, 0, 999, 749, imagecolorallocatealpha($img, 0, 0, 0, 127));
    $src = "$tmp/orig.$fmt";
    $ok = match ($fmt) {
        'jpg' => imagejpeg($img, $src, 90),
        'png' => imagepng($img, $src),
        'webp' => imagewebp($img, $src, 90),
    };
    imagedestroy($img);
    t_ok($ok, "$fmt: original 4000 px gerado");
    $row = PhotoStorage::enqueueOriginal($hid, $src, 0, $i);
    t_ok(is_array($row), "$fmt: enfileirado");
    $ret = null;
    $err = '';
    try {
        $ret = ImageWorker::process((int) $row['id']);
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
    t_eq(true, $ret, "$fmt: process ok $err");
    foreach (ImageWorker::SIZES as $width) {
        $f = PhotoStorage::variantFile((string) PhotoRepository::findById((int) $row["id"])["uuid"], $width);
        $info = is_file($f) ? @getimagesize($f) : false;
        t_eq([$width, intdiv($H * $width, $W), IMAGETYPE_WEBP], $info ? [$info[0], $info[1], $info[2]] : null, "$fmt: $width px WebP ok");
        if ($fmt !== 'jpg' && $info) {
            $out = imagecreatefromwebp($f);
            $a = (imagecolorat($out, 1, 1) >> 24) & 0x7F;
            $b = (imagecolorat($out, $width - 2, intdiv($H * $width, $W) - 2) >> 24) & 0x7F;
            t_ok($a > 100 && $b === 0, "$fmt: $width px preserva alpha (canto $a, fundo $b)");
            imagedestroy($out);
        }
    }
}
exec('rm -rf ' . escapeshellarg($tmp));
t_done();
