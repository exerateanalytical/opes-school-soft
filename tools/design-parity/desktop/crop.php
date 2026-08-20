<?php
/* Crop a region of a reference (or a capture) and upscale it, so small detail
   - icon strokes, badge shapes - can actually be inspected rather than
   guessed at from a full-page view. */
[$src, $x, $y, $w, $h] = [$argv[1], (int)$argv[2], (int)$argv[3], (int)$argv[4], (int)$argv[5]];
$scale = (float)($argv[6] ?? 3);
$dst = $argv[7] ?? 'crop.png';
$im = imagecreatefrompng($src);
$out = imagecreatetruecolor((int)($w*$scale), (int)($h*$scale));
imagecopyresampled($out, $im, 0,0, $x,$y, (int)($w*$scale), (int)($h*$scale), $w, $h);
imagepng($out, $dst);
echo "wrote {$dst} (".(int)($w*$scale).'x'.(int)($h*$scale).")\n";
