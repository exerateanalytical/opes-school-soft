<?php

/*
 * Measure a reference design EXACTLY: solid shapes by connected-region bounds,
 * cards by their border runs, text by cap height.
 *
 * Everything is reported in CSS pixels (the PNGs are 2x), because that is the
 * unit the stylesheet is written in. Nothing here is estimated from eye.
 */

$file = $argv[1] ?? 'parent-dashboard.png';
$im = imagecreatefrompng(__DIR__.'/../../mobile/'.$file);
$W = imagesx($im);
$H = imagesy($im);

function rgb(GdImage $im, int $x, int $y): array
{
    $c = imagecolorat($im, $x, $y);

    return [($c >> 16) & 255, ($c >> 8) & 255, $c & 255];
}

function lum(array $p): float
{
    return 0.299 * $p[0] + 0.587 * $p[1] + 0.114 * $p[2];
}

/**
 * Bounding box of the connected region of colour around a seed point.
 *
 * Used for the solid icon discs, whose diameter is then exact rather than
 * inferred: a filled circle's bounding box IS its diameter.
 */
function region(GdImage $im, int $sx, int $sy, int $tol = 42): array
{
    $target = rgb($im, $sx, $sy);
    $W = imagesx($im);
    $H = imagesy($im);
    $seen = [];
    $stack = [[$sx, $sy]];
    $minX = $sx;
    $maxX = $sx;
    $minY = $sy;
    $maxY = $sy;

    while ($stack !== []) {
        [$x, $y] = array_pop($stack);
        $k = $y * $W + $x;

        if (isset($seen[$k]) || $x < 0 || $y < 0 || $x >= $W || $y >= $H) {
            continue;
        }

        $p = rgb($im, $x, $y);

        if (abs($p[0] - $target[0]) > $tol || abs($p[1] - $target[1]) > $tol || abs($p[2] - $target[2]) > $tol) {
            continue;
        }

        $seen[$k] = true;
        $minX = min($minX, $x);
        $maxX = max($maxX, $x);
        $minY = min($minY, $y);
        $maxY = max($maxY, $y);

        $stack[] = [$x + 1, $y];
        $stack[] = [$x - 1, $y];
        $stack[] = [$x, $y + 1];
        $stack[] = [$x, $y - 1];
    }

    return [$minX, $minY, $maxX, $maxY, $maxX - $minX + 1, $maxY - $minY + 1];
}

/**
 * Cap height of the tallest glyph run inside a box, and the implied font size.
 *
 * Cap height is measured rather than the full line box because ascenders and
 * descenders depend on which letters a string happens to contain. For the
 * Inter-like face in these designs cap height is ~0.72em; that ratio is the
 * single assumption and it is stated, not hidden.
 */
function capHeight(GdImage $im, int $x0, int $y0, int $x1, int $y1, float $maxLum = 140): array
{
    $top = null;
    $bottom = null;

    for ($y = $y0; $y <= $y1; $y++) {
        $ink = 0;

        for ($x = $x0; $x <= $x1; $x++) {
            if (lum(rgb($im, $x, $y)) < $maxLum) {
                $ink++;
            }
        }

        if ($ink >= 2) {
            $top ??= $y;
            $bottom = $y;
        }
    }

    if ($top === null) {
        return ['cap' => null, 'font' => null];
    }

    $capCss = ($bottom - $top + 1) / 2;

    return ['cap' => $capCss, 'font' => round($capCss / 0.72, 1), 'top' => $top, 'bottom' => $bottom];
}

echo "== {$file}  {$W}x{$H} device = ".($W / 2).'x'.round($H / 2)." CSS ==\n\n";

/* ---------------------------------------------------- solid icon discs -- */
echo "SOLID DISCS (bounding box = exact diameter)\n";

foreach ([
    'overview 1 (green)' => [130, 698],
    'overview 2 (gold)' => [320, 698],
    'overview 3 (blue)' => [514, 698],
    'overview 4 (purple)' => [716, 698],
    'unread bell (gold)' => [178, 1878],
] as $label => [$x, $y]) {
    if ($y >= $H) {
        printf("  %-22s outside image\n", $label);

        continue;
    }

    [$minX, $minY, $maxX, $maxY, $w, $h] = region($im, $x, $y);
    printf(
        "  %-22s %5.1f x %-5.1f CSS   at (%.1f, %.1f)  #%02X%02X%02X\n",
        $label, $w / 2, $h / 2, $minX / 2, $minY / 2, ...rgb($im, $x, $y)
    );
}

/* ------------------------------------------------------------- text -- */
echo "\nTEXT (cap height -> implied font-size, CSS px)\n";

foreach ([
    'welcome heading' => [76, 610, 700, 660],
    'welcome subline' => [76, 690, 620, 720],
    'section "My Children"' => [76, 780, 330, 820],
    'child name' => [268, 940, 500, 975],
    'child form' => [268, 985, 430, 1020],
    'overview label' => [88, 1486, 350, 1516],
    'overview value' => [88, 1546, 330, 1600],
    'overview caption' => [88, 1636, 350, 1668],
    'panel title' => [110, 2060, 400, 2095],
    'quick action label' => [96, 3078, 300, 3106],
] as $label => [$x0, $y0, $x1, $y1]) {
    if ($y1 >= $H) {
        printf("  %-22s outside image\n", $label);

        continue;
    }

    $m = capHeight($im, $x0, $y0, min($x1, $W - 1), $y1);
    printf(
        "  %-22s cap %-6s font ~%-6s (ink y %s..%s)\n",
        $label,
        $m['cap'] === null ? '-' : $m['cap'],
        $m['font'] === null ? '-' : $m['font'],
        $m['top'] ?? '-',
        $m['bottom'] ?? '-'
    );
}
