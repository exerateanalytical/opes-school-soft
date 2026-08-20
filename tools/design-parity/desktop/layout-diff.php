<?php

/*
 * LAYOUT DIFF — compares WHERE THE BOXES ARE, independently of what is in them.
 *
 * WHY THIS EXISTS ALONGSIDE diff.php. A pixel diff of a real page against a
 * mockup is dominated by text: the reference says "2,458 students" and the
 * build says "933", so the numerals differ, the labels differ, the names in
 * every table differ. Those pixels are DATA, they will never match, and they
 * bury the differences that actually matter. A build can be structurally
 * perfect and still score badly, which makes the number useless for directing
 * work.
 *
 * Geometry has no such excuse. If the reference puts a card row at y 118..241
 * and the build puts it at y 298..421, that is a defect with no "but the data
 * is different" defence. This finds every panel in both images and reports the
 * deltas.
 *
 * HOW PANELS ARE FOUND. The design is white cards on a warm ivory ground, and
 * the two are separable by HUE rather than brightness: ivory runs ~5 higher in
 * red than in blue, card white is neutral. Classifying on that difference
 * survives the render's compression noise, where a brightness threshold
 * wanders. Rows of ground that span the full content width are gutters; the
 * bands between them are rows. The same scan run vertically inside a row gives
 * that row's columns.
 *
 * Usage:
 *   php layout-diff.php <reference.png|path> <built.png> [--left=270] [--right=1535]
 */

$root = dirname(__DIR__, 3);

$positional = [];
$opt = ['left' => 270, 'right' => 0, 'probe-left' => 300, 'probe-right' => 320];

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--')) {
        [$k, $v] = array_pad(explode('=', substr($arg, 2), 2), 2, '1');
        $opt[$k] = (int) $v;
    } else {
        $positional[] = $arg;
    }
}

$refPath = is_file($positional[0] ?? '') ? $positional[0] : $root.'/frontend images/'.($positional[0] ?? '');
$builtPath = $positional[1] ?? '';

foreach ([$refPath, $builtPath] as $p) {
    if (! is_file($p)) {
        fwrite(STDERR, "not found: {$p}\n");
        exit(1);
    }
}

function isGround(array $p): bool
{
    // Warm ivory: red measurably above blue. Card white is neutral.
    return $p[0] >= 244 && $p[0] - $p[2] >= 3 && $p[1] >= 243;
}

function rgbAt(GdImage $im, int $x, int $y): array
{
    $c = imagecolorat($im, $x, $y);

    return [($c >> 16) & 255, ($c >> 8) & 255, $c & 255];
}

/**
 * Row bands: the vertical extents between full-width gutters.
 *
 * @return list<array{top: int, bottom: int, height: int}>
 */
function rowBands(GdImage $im, int $x0, int $x1): array
{
    $H = imagesy($im);
    $bands = [];
    $start = null;

    for ($y = 0; $y < $H; $y++) {
        $allGround = true;

        for ($x = $x0; $x <= $x1; $x++) {
            if (! isGround(rgbAt($im, $x, $y))) {
                $allGround = false;

                break;
            }
        }

        if ($allGround) {
            if ($start !== null) {
                $bands[] = ['top' => $start, 'bottom' => $y - 1, 'height' => $y - $start];
                $start = null;
            }
        } else {
            $start ??= $y;
        }
    }

    if ($start !== null) {
        $bands[] = ['top' => $start, 'bottom' => $H - 1, 'height' => $H - $start];
    }

    // Hairlines and shadow bleed are not rows.
    return array_values(array_filter($bands, static fn (array $b): bool => $b['height'] >= 24));
}

/**
 * Column tracks inside one row band.
 *
 * @return list<array{left: int, right: int, width: int}>
 */
function columnTracks(GdImage $im, array $band, int $x0, int $x1): array
{
    // Sample a thin strip near the band's vertical middle: card interiors are
    // reliably non-ground there, while the very top and bottom edges carry
    // rounded corners that read as ground and split every card in two.
    $y0 = $band['top'] + (int) ($band['height'] * 0.45);
    $y1 = min($band['bottom'], $y0 + 6);

    $tracks = [];
    $start = null;

    for ($x = $x0; $x <= $x1; $x++) {
        $allGround = true;

        for ($y = $y0; $y <= $y1; $y++) {
            if (! isGround(rgbAt($im, $x, $y))) {
                $allGround = false;

                break;
            }
        }

        if ($allGround) {
            if ($start !== null) {
                $tracks[] = ['left' => $start, 'right' => $x - 1, 'width' => $x - $start];
                $start = null;
            }
        } else {
            $start ??= $x;
        }
    }

    if ($start !== null) {
        $tracks[] = ['left' => $start, 'right' => $x1, 'width' => $x1 - $start + 1];
    }

    return array_values(array_filter($tracks, static fn (array $t): bool => $t['width'] >= 40));
}

$ref = imagecreatefrompng($refPath);
$built = imagecreatefrompng($builtPath);

$x0 = $opt['left'];
$x1 = $opt['right'] > 0 ? $opt['right'] : min(imagesx($ref), imagesx($built)) - 1;

$refRows = rowBands($ref, $opt['probe-left'], $opt['probe-right']);
$builtRows = rowBands($built, $opt['probe-left'], $opt['probe-right']);

printf("\n=== LAYOUT DIFF ===\n");
printf("reference : %s\n", basename($refPath));
printf("build     : %s\n", basename($builtPath));
printf("content   : x %d..%d\n\n", $x0, $x1);

printf("ROW BANDS  (probe column x %d..%d)\n", $opt['probe-left'], $opt['probe-right']);
printf("  %-4s | %-22s | %-22s | %s\n", '#', 'reference  y / h', 'build      y / h', 'delta');
printf("  %s\n", str_repeat('-', 78));

$pairs = max(count($refRows), count($builtRows));
$worstTop = 0;
$worstHeight = 0;

for ($i = 0; $i < $pairs; $i++) {
    $r = $refRows[$i] ?? null;
    $b = $builtRows[$i] ?? null;

    $refCell = $r === null ? '—' : sprintf('%4d..%-4d  h %-4d', $r['top'], $r['bottom'], $r['height']);
    $builtCell = $b === null ? '—' : sprintf('%4d..%-4d  h %-4d', $b['top'], $b['bottom'], $b['height']);

    if ($r === null || $b === null) {
        printf("  %-4d | %-22s | %-22s | %s\n", $i, $refCell, $builtCell, 'MISSING ROW');

        continue;
    }

    $dTop = $b['top'] - $r['top'];
    $dHeight = $b['height'] - $r['height'];
    $worstTop = max($worstTop, abs($dTop));
    $worstHeight = max($worstHeight, abs($dHeight));

    printf(
        "  %-4d | %-22s | %-22s | top %+5d   height %+5d %s\n",
        $i, $refCell, $builtCell, $dTop, $dHeight,
        (abs($dTop) <= 2 && abs($dHeight) <= 2) ? 'OK' : ''
    );
}

printf("\n  worst top offset: %dpx    worst height error: %dpx\n", $worstTop, $worstHeight);

printf("\nCOLUMN TRACKS per row\n");

for ($i = 0; $i < min(count($refRows), count($builtRows)); $i++) {
    $refCols = columnTracks($ref, $refRows[$i], $x0, $x1);
    $builtCols = columnTracks($built, $builtRows[$i], $x0, $x1);

    printf(
        "  row %d — reference %d track(s), build %d track(s)%s\n",
        $i, count($refCols), count($builtCols),
        count($refCols) === count($builtCols) ? '' : '   <-- COUNT MISMATCH'
    );

    for ($c = 0; $c < max(count($refCols), count($builtCols)); $c++) {
        $rc = $refCols[$c] ?? null;
        $bc = $builtCols[$c] ?? null;

        if ($rc === null || $bc === null) {
            printf(
                "      %-2d  %-20s %-20s  EXTRA/MISSING\n", $c,
                $rc === null ? '—' : sprintf('x %4d..%-4d w %d', $rc['left'], $rc['right'], $rc['width']),
                $bc === null ? '—' : sprintf('x %4d..%-4d w %d', $bc['left'], $bc['right'], $bc['width'])
            );

            continue;
        }

        printf(
            "      %-2d  x %4d..%-4d w %-4d   x %4d..%-4d w %-4d   left %+4d  width %+4d %s\n",
            $c, $rc['left'], $rc['right'], $rc['width'], $bc['left'], $bc['right'], $bc['width'],
            $bc['left'] - $rc['left'], $bc['width'] - $rc['width'],
            (abs($bc['left'] - $rc['left']) <= 3 && abs($bc['width'] - $rc['width']) <= 3) ? 'OK' : ''
        );
    }
}

printf("\n");
