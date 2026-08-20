{{--
    The 12px toghu strip running the full height of the sidebar's right edge.

    Measured off `frontend images/super admin dashbaord.png`: the dark field
    ends at x 257 and the content canvas begins at x 270, with the pattern
    filling x 258..269.

    THE GROUND IS GOLD, not green. Cropped at 12x and set beside a first
    attempt, the reference strip is a predominantly GOLD band carrying small
    dark motifs; the first version had it the other way round - a dark green
    band carrying thin gold diamonds - which is a different object, not a
    near-miss, and the two were only distinguishable side by side at
    magnification. Hence the dark lattice over a gold base here.

    It is the VERTICAL sibling of x-toghu-band, and a separate component
    rather than a rotation of that one because a rotated element keeps its
    original box for layout: a full-width band turned on its side still
    reserves the full width and shoves the page off screen.

    Rendered as crossed gradients rather than a tiled asset because it stays
    crisp at any height and costs no request.
--}}
<div {{ $attributes->merge(['class' => 'w-[12px] shrink-0 self-stretch']) }}
     style="background-color: var(--color-shell-strip);
         background-image:
         repeating-linear-gradient(45deg,
             var(--color-shell-field) 0, var(--color-shell-field) 1px,
             transparent 1px, transparent 5px),
         repeating-linear-gradient(135deg,
             var(--color-shell-field) 0, var(--color-shell-field) 1px,
             transparent 1px, transparent 5px);
         background-size: 10px 10px;"
     aria-hidden="true"></div>
