<?php

/*
 * Find design elements by SEARCHING for them, then measure what was found.
 *
 * An earlier version took seed coordinates by eye, missed every disc, and
 * flood-filled the page background instead - reporting a 426x825 "icon". Hard
 * coordinates are a guess wearing a number's clothing. This scans the whole
 * image for the thing being measured and reports where it actually is.
 *
 * All output is CSS pixels; the references are 2x.
 */

$file = $argv[1] ?? 'parent-dashboard.png';
$im = imagecreatefrompng(__DIR__.'/../../mobile/'.$file);
$W = imagesx($im);
$H = imagesy($im);

/** @return array{0:int,1:int,2:int} */
function px(GdImage $im, int $x, int $y): array
{
    $c = imagecolorat($im, $x, $y);

    return [($c >> 16) & 255, ($c >> 8) & 255, $c & 255];
}

/**
 * Every connected blob of "interesting" colour, as label => bounding boxes.
 *
 * Saturated fills only: the discs, chips and badges the design uses. Text and
 * photographs are excluded by requiring a strong, consistent hue.
 */
function blobs(GdImage $im, callable $matches, int $minSide = 16): array
{
    $W = imagesx($im);
    $H = imagesy($im);
    $seen = [];
    $out = [];

    for ($y = 0; $y < $H; $y += 2) {
        for ($x = 0; $x < $W; $x += 2) {
            $k = $y * $W + $x;

            if (isset($seen[$k]) || ! $matches(px($im, $x, $y))) {
                continue;
            }

            $stack = [[$x, $y]];
            $minX = $x;
            $maxX = $x;
            $minY = $y;
            $maxY = $y;
            $n = 0;

            while ($stack !== []) {
                [$cx, $cy] = array_pop($stack);
                $ck = $cy * $W + $cx;

                if (isset($seen[$ck]) || $cx < 0 || $cy < 0 || $cx >= $W || $cy >= $H) {
                    continue;
                }

                if (! $matches(px($im, $cx, $cy))) {
                    continue;
                }

                $seen[$ck] = true;
                $n++;
                $minX = min($minX, $cx);
                $maxX = max($maxX, $cx);
                $minY = min($minY, $cy);
                $maxY = max($maxY, $cy);

                $stack[] = [$cx + 1, $cy];
                $stack[] = [$cx - 1, $cy];
                $stack[] = [$cx, $cy + 1];
                $stack[] = [$cx, $cy - 1];
            }

            $w = $maxX - $minX + 1;
            $h = $maxY - $minY + 1;

            if ($w >= $minSide && $h >= $minSide) {
                $out[] = ['x' => $minX, 'y' => $minY, 'w' => $w, 'h' => $h, 'n' => $n];
            }
        }
    }

    return $out;
}

echo "== {$file}  ".($W / 2).'x'.round($H / 2)." CSS ==\n\n";

$tests = [
    'gold disc' => fn (array $p): bool => $p[0] > 185 && $p[1] > 130 && $p[1] < 200 && $p[2] < 100,
    'blue disc' => fn (array $p): bool => $p[2] > 150 && $p[2] - $p[0] > 60 && $p[1] < 160,
    'purple disc' => fn (array $p): bool => $p[2] > 150 && $p[0] > 90 && $p[0] - $p[1] > 40 && $p[2] - $p[1] > 50,
    'deep green disc' => fn (array $p): bool => $p[0] < 45 && $p[1] > 45 && $p[1] < 90 && $p[2] < 60,
    'red text/dot' => fn (array $p): bool => $p[0] > 180 && $p[1] < 90 && $p[2] < 90,
    'mint chip' => fn (array $p): bool => $p[0] > 195 && $p[0] < 235 && $p[1] > 225 && $p[2] > 195 && $p[1] - $p[0] > 12,
];

foreach ($tests as $label => $fn) {
    $found = blobs($im, $fn);

    usort($found, static fn (array $a, array $b): int => $a['y'] <=> $b['y'] ?: $a['x'] <=> $b['x']);

    printf("%s  (%d found)\n", strtoupper($label), count($found));

    foreach (array_slice($found, 0, 8) as $b) {
        printf(
            "   %6.1f x %-6.1f at (%6.1f, %6.1f)\n",
            $b['w'] / 2, $b['h'] / 2, $b['x'] / 2, $b['y'] / 2
        );
    }

    echo "\n";
}
