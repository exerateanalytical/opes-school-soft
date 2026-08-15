<?php

/*
 * Build a side-by-side sheet: reference design on the left, the built screen
 * on the right, at the reference's own pixel size, with a labelled gutter.
 *
 * The gutter is magenta on purpose. A neutral gutter blends into whichever
 * panel is lighter and makes it hard to see where one screen ends, which is
 * exactly the judgement the sheet exists to support.
 */

$scratch = 'C:/Users/PC/AppData/Local/Temp/claude/C--laragon-www-opeschool-cloud-mobile/'
    .'40799381-a682-4802-99ce-b08ff80a5985/scratchpad';

$slug = $argv[1] ?? 'parent-dashboard';
$refPath = __DIR__.'/../../mobile/'.$slug.'.png';
$shotPath = $scratch.'/shots/'.$slug.'.png';

if (! is_file($refPath) || ! is_file($shotPath)) {
    fwrite(STDERR, "missing input for {$slug}\n");

    exit(1);
}

$ref = imagecreatefrompng($refPath);
$shot = imagecreatefrompng($shotPath);

$rw = imagesx($ref);
$rh = imagesy($ref);
$sw = imagesx($shot);
$sh = imagesy($shot);

$h = max($rh, $sh);
$gutter = 24;
$label = 34;

$out = imagecreatetruecolor($rw + $gutter + $sw, $h + $label);
imagefilledrectangle($out, 0, 0, imagesx($out), imagesy($out), imagecolorallocate($out, 255, 0, 255));

$white = imagecolorallocate($out, 255, 255, 255);
imagestring($out, 5, 8, 10, 'REFERENCE  '.$slug, $white);
imagestring($out, 5, $rw + $gutter + 8, 10, 'BUILT', $white);

imagecopy($out, $ref, 0, $label, 0, 0, $rw, $rh);
imagecopy($out, $shot, $rw + $gutter, $label, 0, 0, $sw, $sh);

$dest = $scratch.'/compare/'.$slug.'.png';

if (! is_dir(dirname($dest))) {
    mkdir(dirname($dest), 0755, true);
}

imagepng($out, $dest);

printf("%s  ref %dx%d  built %dx%d  -> %s\n", $slug, $rw, $rh, $sw, $sh, $dest);
