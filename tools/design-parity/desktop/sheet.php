<?php
/*
 * Side-by-side parity sheet: reference on the left, what we built on the
 * right, at the SAME pixel size, with a scale bar every 100px so a drift can
 * be read off rather than argued about.
 *
 * Composited at 1:1 - never scaled to fit - because the whole point is to
 * compare pixel sizes, and a sheet that rescales either half destroys the
 * only measurement it was made to carry.
 */
[$refName, $builtPath, $out] = [$argv[1], $argv[2], $argv[3]];

$root = dirname(__DIR__, 3);
$ref = imagecreatefrompng($root.'/frontend images/'.$refName);
$built = imagecreatefrompng($builtPath);

$rw = imagesx($ref);   $rh = imagesy($ref);
$bw = imagesx($built); $bh = imagesy($built);

$gutter = 24;
$labelH = 26;
$W = $rw + $gutter + $bw;
$H = $labelH + max($rh, $bh);

$sheet = imagecreatetruecolor($W, $H);
imagefill($sheet, 0, 0, imagecolorallocate($sheet, 24, 24, 27));

imagecopy($sheet, $ref, 0, $labelH, 0, 0, $rw, $rh);
imagecopy($sheet, $built, $rw + $gutter, $labelH, 0, 0, $bw, $bh);

$white = imagecolorallocate($sheet, 245, 245, 245);
imagestring($sheet, 5, 6, 6, 'REFERENCE  '.$refName.'  '.$rw.'x'.$rh, $white);
imagestring($sheet, 5, $rw + $gutter + 6, 6, 'BUILT  '.$bw.'x'.$bh, $white);

// Horizontal rules every 100px across BOTH halves, so a row that sits 12px
// low on the right is visible as a row that crosses a different rule.
$rule = imagecolorallocatealpha($sheet, 255, 0, 128, 100);
for ($y = 100; $y < max($rh, $bh); $y += 100) {
    imageline($sheet, 0, $labelH + $y, $W, $labelH + $y, $rule);
    imagestring($sheet, 2, 2, $labelH + $y - 12, (string) $y, $rule);
}

imagepng($sheet, $out);
printf("wrote %s  (%dx%d)\n", $out, $W, $H);
