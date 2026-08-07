@props([
    'height' => 'h-2.5',
])

{{--
    Decorative diamond/zigzag band (toghu/kente-style pattern), alternating
    dark chrome-green and gold. Purely visual - aria-hidden, no semantic
    content - separates the sidebar's crest/wordmark block from the nav list.
    A repeating-linear-gradient renders crisper than a tiled raster/SVG at
    this thin a height and needs no extra asset.
--}}
<div {{ $attributes->merge(['class' => $height.' w-full shrink-0']) }}
     style="background-image:
         repeating-linear-gradient(135deg,
             var(--color-heritage-yellow) 0, var(--color-heritage-yellow) 6px,
             var(--color-chrome) 6px, var(--color-chrome) 12px),
         repeating-linear-gradient(45deg,
             var(--color-heritage-yellow) 0, var(--color-heritage-yellow) 6px,
             var(--color-chrome) 6px, var(--color-chrome) 12px);
         background-size: 12px 50%;
         background-position: 0 0, 0 100%;
         background-repeat: repeat-x;"
     aria-hidden="true"></div>
