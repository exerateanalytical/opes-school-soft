<?php

/*
 * Measure text sizes in a reference design by finding the ink extents of a
 * line inside a given box.
 *
 * Cap height is what is measured, because it is the only feature of a
 * rendered string that can be found reliably without knowing the font:
 * ascenders and descenders depend on which letters happen to be present.
 * For the Inter-like faces in these designs cap height is ~0.72 of the font
 * size, so font-size ~= cap / 0.72. That constant is the one assumption here
 * and it is stated rather than buried.
 */

$file = $argv[1] ?? 'parent-dashboard.png';
$im = imagecreatefrompng(__DIR__.'/../../mobile/'.$file);

/**
 * Vertical ink extents of dark pixels inside a device-pixel box.
 *
 * @return array{0:int,1:int}|null
 */
function ink(GdImage $im, int $x0, int $y0, int $x1, int $y1, int $threshold = 150): ?array
{
    $top = null;
    $bottom = null;

    for ($y = $y0; $y <= $y1; $y++) {
        $hit = false;

        for ($x = $x0; $x <= $x1; $x++) {
            $c = imagecolorat($im, $x, $y);
            $lum = (0.299 * (($c >> 16) & 255)) + (0.587 * (($c >> 8) & 255)) + (0.114 * ($c & 255));

            if ($lum < $threshold) {
                $hit = true;

                break;
            }
        }

        if ($hit) {
            $top ??= $y;
            $bottom = $y;
        }
    }

    return $top === null ? null : [$top, $bottom];
}

// label => [x0, y0, x1, y1] in DEVICE pixels (the PNG is 2x).
$targets = [
    'welcome heading' => [76, 596, 700, 650],
    'welcome subline' => [76, 660, 640, 700],
    'section "My Children"' => [76, 790, 330, 830],
    'child name' => [270, 940, 500, 980],
    'child form' => [270, 990, 420, 1020],
    'overview label' => [90, 1490, 340, 1520],
    'overview value' => [90, 1550, 320, 1600],
    'nav label' => [110, 1800, 280, 1830],
];

printf("%-24s %-18s %-9s %s\n", 'element', 'ink (device y)', 'cap CSS', 'implied font-size');

foreach ($targets as $label => [$x0, $y0, $x1, $y1]) {
    $e = ink($im, $x0, $y0, $x1, $y1);

    if ($e === null) {
        printf("%-24s %-18s\n", $label, 'no ink found');

        continue;
    }

    $capDevice = $e[1] - $e[0] + 1;
    $capCss = $capDevice / 2;

    printf(
        "%-24s %-18s %-9.1f ~%.1fpx\n",
        $label,
        $e[0].'..'.$e[1],
        $capCss,
        $capCss / 0.72
    );
}
