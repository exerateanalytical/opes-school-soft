<?php

/*
 * PIXEL DIFF — the instrument that turns "looks close" into a number.
 *
 * Everything before this compared two images by looking at them, which is
 * exactly the failure mode this whole harness exists to prevent: a page can
 * measure correct on the four numbers you happened to check and still be
 * visibly wrong everywhere you did not.
 *
 * This reports, for a reference and a build of the same size:
 *
 *   1. A single score - the share of pixels whose colour differs by more than
 *      a perceptual threshold. One number, comparable across runs, so
 *      "better than last time" is a fact rather than an opinion.
 *   2. A GRID breakdown. The page is cut into cells and each is scored
 *      independently, then ranked worst-first. This is the part that
 *      actually directs the work: it says "row 3, columns 5-7 are 61% off"
 *      instead of "the middle looks wrong".
 *   3. A heat map PNG - the reference dimmed, with deviating pixels painted
 *      by severity, so the shape of the error is visible at a glance.
 *
 * WHY A THRESHOLD, AND WHY THIS ONE. The references are AI renders: they
 * carry compression noise, soft edges and gradients that no CSS will ever
 * reproduce exactly. Counting every differing pixel would score a perfect
 * build at 90% wrong and the number would be useless. The threshold is a
 * Euclidean distance in RGB, and 40 is roughly "a person would notice"; the
 * default is deliberately printed with every run so a score is never quoted
 * without the tolerance it was measured at.
 *
 * ANTI-ALIASING is discounted separately: a pixel whose neighbours in the
 * OTHER image include a near-match is treated as an edge that landed half a
 * pixel out, not as a difference. Without that, every text run in the page
 * scores as solid error and the grid points at whichever cell has the most
 * words rather than at whichever cell is actually wrong.
 *
 * Usage:
 *   php diff.php <reference.png|path> <built.png> [out-heatmap.png]
 *                [--threshold=40] [--cols=12] [--rows=8] [--top=12]
 *                [--crop-left=270]   ignore the sidebar, score content only
 */

$root = dirname(__DIR__, 3);

/** @return array{0:string,1:string,2:?string,3:array<string,int>} */
function parseArgs(array $argv): array
{
    $positional = [];
    $opts = ['threshold' => 40, 'cols' => 12, 'rows' => 8, 'top' => 12, 'crop-left' => 0, 'crop-top' => 0];

    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--')) {
            [$k, $v] = array_pad(explode('=', substr($arg, 2), 2), 2, '1');
            $opts[$k] = (int) $v;
        } else {
            $positional[] = $arg;
        }
    }

    return [$positional[0] ?? '', $positional[1] ?? '', $positional[2] ?? null, $opts];
}

[$refArg, $builtPath, $heatPath, $opt] = parseArgs($argv);

$refPath = is_file($refArg) ? $refArg : $root.'/frontend images/'.$refArg;

foreach ([$refPath, $builtPath] as $p) {
    if (! is_file($p)) {
        fwrite(STDERR, "not found: {$p}\n");
        exit(1);
    }
}

$ref = imagecreatefrompng($refPath);
$built = imagecreatefrompng($builtPath);

$W = min(imagesx($ref), imagesx($built));
$H = min(imagesy($ref), imagesy($built));

if (imagesx($ref) !== imagesx($built) || imagesy($ref) !== imagesy($built)) {
    printf(
        "WARNING sizes differ: reference %dx%d, build %dx%d - comparing the shared %dx%d only.\n",
        imagesx($ref), imagesy($ref), imagesx($built), imagesy($built), $W, $H
    );
}

$x0 = max(0, $opt['crop-left']);
$y0 = max(0, $opt['crop-top']);
$threshold = $opt['threshold'];

/** Squared RGB distance - squared so the per-pixel loop needs no sqrt. */
$dist2 = static function (int $a, int $b): int {
    $dr = (($a >> 16) & 255) - (($b >> 16) & 255);
    $dg = (($a >> 8) & 255) - (($b >> 8) & 255);
    $db = ($a & 255) - ($b & 255);

    return $dr * $dr + $dg * $dg + $db * $db;
};

$limit = $threshold * $threshold;

/*
 * A pixel counts as different only if it ALSO fails to match anything in the
 * other image's 3x3 neighbourhood. That is the anti-aliasing discount: a
 * glyph edge or a border that landed one pixel over is a positioning
 * difference of one pixel, not a colour error, and counting it as the latter
 * drowns the signal in text.
 */
$neighbourMatches = static function (GdImage $other, int $x, int $y, int $rgb, int $limit, int $W, int $H) use ($dist2): bool {
    for ($dy = -1; $dy <= 1; $dy++) {
        for ($dx = -1; $dx <= 1; $dx++) {
            $nx = $x + $dx;
            $ny = $y + $dy;

            if ($nx < 0 || $ny < 0 || $nx >= $W || $ny >= $H) {
                continue;
            }

            if ($dist2(imagecolorat($other, $nx, $ny), $rgb) <= $limit) {
                return true;
            }
        }
    }

    return false;
};

$cols = max(1, $opt['cols']);
$rows = max(1, $opt['rows']);
$cellW = (int) ceil(($W - $x0) / $cols);
$cellH = (int) ceil(($H - $y0) / $rows);

$cellDiff = array_fill(0, $rows, array_fill(0, $cols, 0));
$cellTotal = array_fill(0, $rows, array_fill(0, $cols, 0));

$heat = imagecreatetruecolor($W, $H);
$diffPixels = 0;
$totalPixels = 0;

for ($y = $y0; $y < $H; $y++) {
    for ($x = $x0; $x < $W; $x++) {
        $a = imagecolorat($ref, $x, $y);
        $b = imagecolorat($built, $x, $y);
        $d = $dist2($a, $b);

        $totalPixels++;
        $cy = min($rows - 1, (int) (($y - $y0) / $cellH));
        $cx = min($cols - 1, (int) (($x - $x0) / $cellW));
        $cellTotal[$cy][$cx]++;

        // The reference, dimmed, is the ground the heat sits on.
        $grey = (int) (0.299 * (($a >> 16) & 255) + 0.587 * (($a >> 8) & 255) + 0.114 * ($a & 255));
        $grey = (int) (255 - (255 - $grey) * 0.25);

        if ($d <= $limit
            || $neighbourMatches($built, $x, $y, $a, $limit, $W, $H)
            || $neighbourMatches($ref, $x, $y, $b, $limit, $W, $H)) {
            imagesetpixel($heat, $x, $y, imagecolorallocate($heat, $grey, $grey, $grey));

            continue;
        }

        $diffPixels++;
        $cellDiff[$cy][$cx]++;

        // Severity: yellow for a near miss through to red for a total one.
        $sev = min(1.0, sqrt($d) / 160);
        imagesetpixel($heat, $x, $y, imagecolorallocate($heat, 255, (int) (210 * (1 - $sev)), 0));
    }
}

if ($heatPath !== null) {
    // Cell grid drawn over the heat, so a rank in the table below can be
    // found in the picture without counting squares.
    $line = imagecolorallocatealpha($heat, 0, 120, 255, 90);

    for ($c = 1; $c < $cols; $c++) {
        imageline($heat, $x0 + $c * $cellW, $y0, $x0 + $c * $cellW, $H, $line);
    }

    for ($r = 1; $r < $rows; $r++) {
        imageline($heat, $x0, $y0 + $r * $cellH, $W, $y0 + $r * $cellH, $line);
    }

    imagepng($heat, $heatPath);
}

$score = $totalPixels === 0 ? 0.0 : $diffPixels / $totalPixels * 100;

printf("\n=== PIXEL DIFF ===\n");
printf("reference : %s\n", basename($refPath));
printf("build     : %s\n", basename($builtPath));
printf("region    : x %d..%d, y %d..%d   (%d px)\n", $x0, $W - 1, $y0, $H - 1, $totalPixels);
printf("threshold : %d  (RGB distance; anti-aliasing discounted)\n", $threshold);
printf("\nSCORE     : %.2f%% of pixels differ\n", $score);

if ($heatPath !== null) {
    printf("heat map  : %s\n", $heatPath);
}

$ranked = [];

for ($r = 0; $r < $rows; $r++) {
    for ($c = 0; $c < $cols; $c++) {
        if ($cellTotal[$r][$c] === 0) {
            continue;
        }

        $ranked[] = [
            'row' => $r,
            'col' => $c,
            'pct' => $cellDiff[$r][$c] / $cellTotal[$r][$c] * 100,
            'x' => $x0 + $c * $cellW,
            'y' => $y0 + $r * $cellH,
        ];
    }
}

usort($ranked, static fn (array $a, array $b): int => $b['pct'] <=> $a['pct']);

printf("\nWORST CELLS (grid %dx%d, cell %dx%d px)\n", $cols, $rows, $cellW, $cellH);
printf("  %-5s %-5s %-8s  %s\n", 'row', 'col', 'diff', 'top-left (x, y)');

foreach (array_slice($ranked, 0, $opt['top']) as $cell) {
    printf("  %-5d %-5d %6.1f%%   (%d, %d)\n", $cell['row'], $cell['col'], $cell['pct'], $cell['x'], $cell['y']);
}

printf("\n");
