<?php
/*
 * Contact sheet of reference images, so a large set can be triaged in a few
 * looks instead of one open per file. Each cell is labelled with its index so
 * a follow-up crop can name the file it came from.
 */
$root = dirname(__DIR__, 3);
$dir = $root.'/frontend images';
$files = array_values(array_filter(glob($dir.'/*.png') ?: [], static fn (string $f): bool
    => str_starts_with(basename($f), $argv[1] ?? '')));

sort($files);
$offset = (int) ($argv[2] ?? 0);
$count = (int) ($argv[3] ?? 16);
$files = array_slice($files, $offset, $count);

$cols = 4;
$cw = 384; $ch = 256; $label = 18;
$rows = (int) ceil(count($files) / $cols);

$sheet = imagecreatetruecolor($cols * $cw, $rows * ($ch + $label));
imagefill($sheet, 0, 0, imagecolorallocate($sheet, 20, 20, 22));
$white = imagecolorallocate($sheet, 240, 240, 240);

foreach ($files as $i => $file) {
    $im = @imagecreatefrompng($file);

    if ($im === false) {
        continue;
    }

    $x = ($i % $cols) * $cw;
    $y = (int) ($i / $cols) * ($ch + $label);

    imagestring($sheet, 3, $x + 4, $y + 3, ($offset + $i).'  '.substr(basename($file, '.png'), 0, 56), $white);
    imagecopyresampled($sheet, $im, $x + 2, $y + $label, 0, 0, $cw - 4, $ch - 4, imagesx($im), imagesy($im));
    imagedestroy($im);
}

imagepng($sheet, $argv[4] ?? 'contact.png');
printf("wrote %s  (%d files, offset %d)\n", $argv[4] ?? 'contact.png', count($files), $offset);
