#!/usr/bin/env bash
# Screenshot every captured portal screen at the reference designs' exact
# device size (426x922 CSS at 2x = 852x1844) and build a side-by-side sheet.
#
# The reference PNGs are 852x1846 = a 426x922 CSS viewport at 2x.
#
# Captured at 1x and upscaled when compositing, NOT with
# --force-device-scale-factor=2. That flag does not give a 426px CSS viewport:
# it lays the page out at roughly 392px and scales the raster up, so content
# that fits perfectly in the live browser appears clipped at the right edge in
# the shot. Measuring the live DOM said 426 while the screenshot said ~392,
# and the screenshot was the liar.
set -u

CHROME="/c/Program Files/Google/Chrome/Application/chrome.exe"
SCRATCH_WIN='C:\Users\PC\AppData\Local\Temp\claude\C--laragon-www-opeschool-cloud-mobile\40799381-a682-4802-99ce-b08ff80a5985\scratchpad'
SCRATCH="/c/Users/PC/AppData/Local/Temp/claude/C--laragon-www-opeschool-cloud-mobile/40799381-a682-4802-99ce-b08ff80a5985/scratchpad"
PHP="/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe"

mkdir -p "$SCRATCH/shots" "$SCRATCH/compare"

n=0
for f in /c/laragon/www/opeschool-cloud/public/__compare/*.html; do
    slug=$(basename "$f" .html)

    # Only sheets that have a reference to compare against.
    [ -f "/c/laragon/www/opeschool-cloud/mobile/${slug}.png" ] || continue

    "$CHROME" --headless=new --disable-gpu --hide-scrollbars \
        --window-size=426,922 \
        --user-data-dir="${SCRATCH_WIN}\chrome-profile" \
        --screenshot="${SCRATCH_WIN}\shots\\${slug}.png" \
        "http://localhost:8391/__compare/${slug}.html" >/dev/null 2>&1

    if [ -f "$SCRATCH/shots/${slug}.png" ]; then
        "$PHP" /c/laragon/www/opeschool-cloud/tools/design-parity/sheet.php "$slug" >/dev/null 2>&1 && n=$((n+1))
    fi
done

echo "built $n comparison sheets in $SCRATCH/compare"
ls "$SCRATCH/compare" | head -40
