<?php
/*
 * Reference prober for the 1x desktop mockups in "frontend images/".
 *
 * Everything here SEARCHES the image. No coordinate is ever typed in by eye:
 * runs are found by scanning, cards by flood fill, colours by histogram. The
 * reference is 1536x1024 at 1x, so every number printed is already a CSS px.
 *
 * Usage:  php probe.php <file.png> <command> [args]
 *   palette                 top colours by area
 *   row <y> [x0] [x1]       colour runs along a horizontal line
 *   col <x> [y0] [y1]       colour runs along a vertical line
 *   cards [minW] [minH]     flood-filled light panels, as bounding boxes
 *   at <x> <y>              the exact colour of one pixel
 *   box <x0> <y0> <x1> <y1> ink extents + cap height inside a region
 */

$root = dirname(__DIR__, 3);
$file = $argv[1] ?? 'super admin dashbaord.png';
$cmd  = $argv[2] ?? 'palette';

// An absolute path (or one that already resolves) is taken as-is, so the
// SAME instrument can measure a capture of what we built - comparing the
// reference's numbers against our own is the whole point.
$path = is_file($file) ? $file : $root.'/frontend images/'.$file;
$im = imagecreatefrompng($path);
$W = imagesx($im);
$H = imagesy($im);

function rgb(GdImage $im, int $x, int $y): array {
    $c = imagecolorat($im, $x, $y);
    return [($c >> 16) & 255, ($c >> 8) & 255, $c & 255];
}
function hex(array $p): string { return sprintf('#%02X%02X%02X', $p[0], $p[1], $p[2]); }
function lum(array $p): float { return 0.299*$p[0] + 0.587*$p[1] + 0.114*$p[2]; }
function near(array $a, array $b, int $tol): bool {
    return abs($a[0]-$b[0]) <= $tol && abs($a[1]-$b[1]) <= $tol && abs($a[2]-$b[2]) <= $tol;
}

switch ($cmd) {

case 'palette': {
    $hist = [];
    for ($y = 0; $y < $H; $y++) {
        for ($x = 0; $x < $W; $x++) {
            $k = hex(rgb($im, $x, $y));
            $hist[$k] = ($hist[$k] ?? 0) + 1;
        }
    }
    arsort($hist);
    $total = $W * $H;
    echo "TOP COLOURS  ({$W}x{$H})\n";
    foreach (array_slice($hist, 0, (int)($argv[3] ?? 24), true) as $k => $n) {
        printf("  %s  %8d px  %5.2f%%\n", $k, $n, 100*$n/$total);
    }
    break;
}

case 'row': case 'col': {
    $fixed = (int)($argv[3] ?? 0);
    $isRow = $cmd === 'row';
    $from = (int)($argv[4] ?? 0);
    $to   = (int)($argv[5] ?? ($isRow ? $W-1 : $H-1));
    $runs = [];
    $prev = null; $start = $from;
    for ($i = $from; $i <= $to; $i++) {
        $p = $isRow ? rgb($im, $i, $fixed) : rgb($im, $fixed, $i);
        if ($prev === null) { $prev = $p; $start = $i; continue; }
        if (! near($p, $prev, 6)) {
            $runs[] = [$start, $i-1, $prev];
            $prev = $p; $start = $i;
        }
    }
    $runs[] = [$start, $to, $prev];
    printf("%s %d runs (tol 6)\n", strtoupper($cmd), $fixed);
    foreach ($runs as [$a, $b, $p]) {
        printf("  %4d..%-4d  len %-4d  %s\n", $a, $b, $b-$a+1, hex($p));
    }
    break;
}

case 'at': {
    $x = (int)$argv[3]; $y = (int)$argv[4];
    printf("(%d,%d) = %s\n", $x, $y, hex(rgb($im, $x, $y)));
    break;
}

/*
 * Light panels: flood fill every near-white region. The dashboard's cards are
 * white on an ivory ground, so "white region bigger than N" IS the card set -
 * found, not assumed.
 */
case 'cards': {
    $minW = (int)($argv[3] ?? 60);
    $minH = (int)($argv[4] ?? 40);
    $seen = [];
    $out = [];
    $isCard = static fn (array $p): bool => $p[0] > 246 && $p[1] > 246 && $p[2] > 244;

    for ($y = 0; $y < $H; $y += 2) {
        for ($x = 0; $x < $W; $x += 2) {
            $k = $y*$W + $x;
            if (isset($seen[$k]) || ! $isCard(rgb($im, $x, $y))) continue;
            $stack = [[$x,$y]];
            $minX=$x; $maxX=$x; $minY=$y; $maxY=$y; $n=0;
            while ($stack !== []) {
                [$cx,$cy] = array_pop($stack);
                if ($cx<0||$cy<0||$cx>=$W||$cy>=$H) continue;
                $ck = $cy*$W+$cx;
                if (isset($seen[$ck]) || ! $isCard(rgb($im,$cx,$cy))) continue;
                $seen[$ck]=true; $n++;
                $minX=min($minX,$cx); $maxX=max($maxX,$cx);
                $minY=min($minY,$cy); $maxY=max($maxY,$cy);
                $stack[]=[$cx+1,$cy]; $stack[]=[$cx-1,$cy];
                $stack[]=[$cx,$cy+1]; $stack[]=[$cx,$cy-1];
            }
            $w=$maxX-$minX+1; $h=$maxY-$minY+1;
            if ($w>=$minW && $h>=$minH) $out[] = compact('minX','minY','w','h','n');
        }
    }
    usort($out, static fn($a,$b) => $a['minY'] <=> $b['minY'] ?: $a['minX'] <=> $b['minX']);
    printf("LIGHT PANELS (%d)\n", count($out));
    foreach ($out as $c) {
        printf("  x %4d  y %4d   %4d x %-4d   (right %4d, bottom %4d)\n",
            $c['minX'], $c['minY'], $c['w'], $c['h'], $c['minX']+$c['w']-1, $c['minY']+$c['h']-1);
    }
    break;
}

/* Ink extents inside a box: exact left/right/top/bottom of the dark pixels,
   plus cap height -> implied font-size at the stated 0.72 ratio. */
case 'box': {
    [$x0,$y0,$x1,$y1] = [(int)$argv[3],(int)$argv[4],(int)$argv[5],(int)$argv[6]];
    $maxLum = (float)($argv[7] ?? 150);
    $top=null;$bottom=null;$left=null;$right=null;
    for ($y=$y0;$y<=$y1;$y++) for ($x=$x0;$x<=$x1;$x++) {
        if (lum(rgb($im,$x,$y)) < $maxLum) {
            $top ??= $y; $bottom = $y;
            $left = $left === null ? $x : min($left,$x);
            $right = $right === null ? $x : max($right,$x);
        }
    }
    if ($top === null) { echo "no ink\n"; break; }
    $cap = $bottom-$top+1;
    printf("ink x %d..%d (w %d)   y %d..%d (h %d)   cap %d -> font ~%.1f\n",
        $left,$right,$right-$left+1,$top,$bottom,$cap,$cap,$cap/0.72);
    break;
}
}

/*
 * ---------------------------------------------------------------------------
 * Gutters.
 *
 * The mockup is a render, so no edge is a clean 1px line and a naive colour
 * match wanders. What IS reliable is the difference in HUE between the ivory
 * ground (warm: red channel runs ~5 above blue) and the card white (neutral:
 * channels equal). Classifying on that difference, then asking which columns
 * are ivory for the WHOLE of a row band, finds the gutters between cards
 * exactly - and the complement of the gutters is the cards.
 * ---------------------------------------------------------------------------
 */
function isGround(array $p): bool {
    return $p[0] >= 244 && $p[0] - $p[2] >= 3 && $p[1] >= 243;
}

switch ($cmd) {

case 'vgaps': {   // vertical gutters: columns that are ground across a row band
    [$y0,$y1] = [(int)$argv[3], (int)$argv[4]];
    $x0 = (int)($argv[5] ?? 0); $x1 = (int)($argv[6] ?? $W-1);
    $runs = []; $start = null;
    for ($x = $x0; $x <= $x1; $x++) {
        $all = true;
        for ($y = $y0; $y <= $y1; $y++) { if (! isGround(rgb($im,$x,$y))) { $all = false; break; } }
        if ($all) { $start ??= $x; }
        elseif ($start !== null) { $runs[] = [$start, $x-1]; $start = null; }
    }
    if ($start !== null) $runs[] = [$start, $x1];
    printf("GROUND COLUMNS in y %d..%d\n", $y0, $y1);
    $prevEnd = null;
    foreach ($runs as [$a,$b]) {
        if ($prevEnd !== null) printf("      -> panel x %4d..%-4d  width %d\n", $prevEnd+1, $a-1, $a-1-$prevEnd);
        printf("  gap %4d..%-4d  width %d\n", $a, $b, $b-$a+1);
        $prevEnd = $b;
    }
    break;
}

case 'hgaps': {   // horizontal gutters: rows that are ground across a column band
    [$x0,$x1] = [(int)$argv[3], (int)$argv[4]];
    $y0 = (int)($argv[5] ?? 0); $y1 = (int)($argv[6] ?? $H-1);
    $runs = []; $start = null;
    for ($y = $y0; $y <= $y1; $y++) {
        $all = true;
        for ($x = $x0; $x <= $x1; $x++) { if (! isGround(rgb($im,$x,$y))) { $all = false; break; } }
        if ($all) { $start ??= $y; }
        elseif ($start !== null) { $runs[] = [$start, $y-1]; $start = null; }
    }
    if ($start !== null) $runs[] = [$start, $y1];
    printf("GROUND ROWS in x %d..%d\n", $x0, $x1);
    $prevEnd = null;
    foreach ($runs as [$a,$b]) {
        if ($prevEnd !== null) printf("      -> panel y %4d..%-4d  height %d\n", $prevEnd+1, $a-1, $a-1-$prevEnd);
        printf("  gap %4d..%-4d  height %d\n", $a, $b, $b-$a+1);
        $prevEnd = $b;
    }
    break;
}
}

/*
 * Ink rows/cols for LIGHT text on the dark sidebar (the inverse of `box`).
 * Reports each contiguous band of rows that contains light pixels, which is
 * how the nav item pitch is found rather than guessed from a ruler.
 */
switch ($cmd) {

case 'lightrows': {
    [$x0,$x1,$y0,$y1] = [(int)$argv[3],(int)$argv[4],(int)$argv[5],(int)$argv[6]];
    $minLum = (float)($argv[7] ?? 110);
    $bands = []; $start = null;
    for ($y=$y0;$y<=$y1;$y++) {
        $ink = 0;
        for ($x=$x0;$x<=$x1;$x++) if (lum(rgb($im,$x,$y)) > $minLum) $ink++;
        if ($ink >= 2) { $start ??= $y; }
        elseif ($start !== null) { $bands[] = [$start,$y-1]; $start = null; }
    }
    if ($start !== null) $bands[] = [$start,$y1];
    printf("LIGHT BANDS x %d..%d (lum > %.0f)\n", $x0,$x1,$minLum);
    $prev = null;
    foreach ($bands as [$a,$b]) {
        printf("  y %4d..%-4d  h %-3d %s\n", $a,$b,$b-$a+1,
            $prev === null ? '' : sprintf(' pitch %d', $a-$prev));
        $prev = $a;
    }
    break;
}

case 'darkrows': {   // same, for dark ink on a light card
    [$x0,$x1,$y0,$y1] = [(int)$argv[3],(int)$argv[4],(int)$argv[5],(int)$argv[6]];
    $maxLum = (float)($argv[7] ?? 150);
    $bands = []; $start = null;
    for ($y=$y0;$y<=$y1;$y++) {
        $ink = 0;
        for ($x=$x0;$x<=$x1;$x++) if (lum(rgb($im,$x,$y)) < $maxLum) $ink++;
        if ($ink >= 2) { $start ??= $y; }
        elseif ($start !== null) { $bands[] = [$start,$y-1]; $start = null; }
    }
    if ($start !== null) $bands[] = [$start,$y1];
    printf("DARK BANDS x %d..%d (lum < %.0f)\n", $x0,$x1,$maxLum);
    $prev = null;
    foreach ($bands as [$a,$b]) {
        printf("  y %4d..%-4d  h %-3d cap->font ~%.1f%s\n", $a,$b,$b-$a+1, ($b-$a+1)/0.72,
            $prev === null ? '' : sprintf('   pitch %d', $a-$prev));
        $prev = $a;
    }
    break;
}
}

/*
 * ---------------------------------------------------------------------------
 * Corner radius.
 *
 * A rounded rectangle's top-left corner inset at the very top row IS its
 * radius, and the curve straightens after exactly r rows. Measuring BOTH and
 * reporting them together is the check: on a clean edge they agree, and when
 * they do not, the shape is not a circular corner (or the edge is too
 * antialiased to read, which is worth knowing before quoting a number).
 * ---------------------------------------------------------------------------
 */
switch ($cmd) {

case 'radius': {
    [$left, $top] = [(int) $argv[3], (int) $argv[4]];
    $probe = (int) ($argv[5] ?? 40);

    $insets = [];

    for ($y = $top; $y < $top + $probe; $y++) {
        for ($x = $left - 4; $x <= $left + $probe; $x++) {
            if (! isGround(rgb($im, $x, $y))) {
                $insets[$y] = $x - $left;

                break 1;
            }
        }
    }

    $first = $insets[$top] ?? null;
    $straightAt = null;

    foreach ($insets as $y => $inset) {
        if ($inset <= 0) {
            $straightAt = $y;

            break;
        }
    }

    printf("corner at (%d, %d)\n", $left, $top);
    printf("  inset on the top row      : %s px\n", $first === null ? '-' : $first);
    printf("  rows until the edge is flat: %s px\n", $straightAt === null ? '-' : $straightAt - $top);
    printf("  -> radius reads as ~%s px\n\n",
        ($first === null || $straightAt === null) ? '?' : round((($first) + ($straightAt - $top)) / 2, 1));

    foreach ($insets as $y => $inset) {
        printf("     y %4d  inset %+d\n", $y, $inset);

        if ($inset <= 0) {
            break;
        }
    }

    break;
}
}
