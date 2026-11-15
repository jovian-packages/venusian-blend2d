<?php

use Jovian\Engines\Velvet\Lustrous\Framebuffers\FullFramebuffer;
use Jovian\Engines\Velvet\Lustrous\Framebuffers\PHPLandFramebufferDriver;
use Jovian\Rendering\Blend2D\Framebuffers\Blend2DFramebufferImage;
use Jovian\Rendering\Blend2D\Framebuffers\Blend2DFramebufferDriver;
use Surface\Contracts\Rendering\Framebuffers\BitDepth;
use Surface\Contracts\Rendering\Framebuffers\ChannelOrder;
use Surface\Contracts\Rendering\Framebuffers\Endianness;
use Surface\Contracts\Rendering\Framebuffers\Filter;
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Framebuffers\FramebufferException;
use Surface\Contracts\Rendering\Framebuffers\InstanceThatManagesAFramebuffer;
use Surface\Contracts\Rendering\Framebuffers\PixelFormat;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Framebuffers\Spans;
use Surface\NutsAndBolts\Affine;
use Surface\NutsAndBolts\GradientSpread;
use VelvetGE\Contracts\Framebuffers\CompositingFramebuffer;
use VelvetGE\Contracts\Framebuffers\EffectsFramebuffer;
use VelvetGE\Contracts\Framebuffers\ScrollingFramebuffer;

/*
 * A Blend2D image held to lustrous-php, the reference: the same calls on both, in BGRA8, the one
 * colour layout Blend2D has. Moves, fills and conversions move bytes, so they match exactly;
 * blends are Blend2D's arithmetic, within the image's TOLERANCE (5% of 255) of lustrous-php's.
 *
 * Measured and left as Blend2D's: a radial gradient whose transform shears lands up to 3 ramp
 * entries from the exact t (1 without shear), so the radial cases here do not shear.
 */

const BW = 37;
const BH = 23;

dataset('blend2d formats', [
    'bgra8' => [FormatSpec::bgra8()],
]);

/** @return array{Blend2DFramebufferImage, FullFramebuffer} */
function b2both(FormatSpec $spec, int $w = BW, int $h = BH, ?string $rgba8 = null): array
{
    $pair = [new Blend2DFramebufferImage($spec, $w, $h), new FullFramebuffer(new PHPLandFramebufferDriver(), $spec, $w, $h)];
    if (! is_null($rgba8)) {
        foreach ($pair as $frame) {
            $frame->writeRgba8($rgba8, $w, $h);
        }
    }

    return $pair;
}

/** The largest difference between two byte strings of one length. */
function b2furthest(string $a, string $b): int
{
    expect(strlen($a))->toBe(strlen($b));
    $most = 0;
    for ($i = 0, $n = strlen($a); $i < $n; $i++) {
        $most = max($most, abs(ord($a[$i]) - ord($b[$i])));
    }

    return $most;
}

function b2Value(FormatSpec $spec): int
{
    return match ($spec->bit_depth) {
        BitDepth::B16 => mt_rand(0, 0xFFFF),
        BitDepth::B24 => mt_rand(0, 0xFFFFFF),
        default => mt_rand(0, 0xFFFFFFFF),
    };
}

/** How far a read-out may differ: 0, since BGRA8 to RGBA8 only moves bytes. */
function b2Slack(FormatSpec $spec): int
{
    return $spec->bit_depth === BitDepth::B16 ? 1 : 0;
}

beforeEach(fn () => mt_srand(7));

it('is the core plus compositing and scrolling, and no effects', function () {
    $image = new Blend2DFramebufferImage(FormatSpec::bgra8(), 4, 4);

    expect($image)->toBeInstanceOf(InstanceThatManagesAFramebuffer::class)
        ->and($image)->toBeInstanceOf(CompositingFramebuffer::class)
        ->and($image)->toBeInstanceOf(ScrollingFramebuffer::class)
        ->and($image)->not->toBeInstanceOf(EffectsFramebuffer::class);
});

it('refuses every format but BGRA8', function (FormatSpec $spec) {
    new Blend2DFramebufferImage($spec, 8, 2);
})->with([
    'rgba8' => [FormatSpec::rgba8()],
    'mono' => [new FormatSpec(PixelFormat::MONO_HORIZONTAL, BitDepth::B1)],
    'rgb888' => [new FormatSpec(PixelFormat::ROW_MAJOR, BitDepth::B24)],
])->throws(FramebufferException::class);

it('is made by the blend2d driver', function () {
    $driver = new Blend2DFramebufferDriver();

    expect($driver->driver())->toBe('blend2d')
        ->and($driver->make(FormatSpec::bgra8(), 5, 3))->toBeInstanceOf(Blend2DFramebufferImage::class);
});

it('holds pixel values as lustrous-php does', function (FormatSpec $spec) {
    [$p, $l] = b2both($spec);
    for ($i = 0; $i < 50; $i++) {
        [$x, $y, $v] = [mt_rand(0, BW - 1), mt_rand(0, BH - 1), b2Value($spec)];
        $p->setPixel($x, $y, $v);
        $l->setPixel($x, $y, $v);
        expect($p->getPixel($x, $y))->toBe($l->getPixel($x, $y));
    }

    expect($p->dump())->toBe($l->dump());
})->with('blend2d formats');

it('fills, writes segments and clears to the same bytes', function (FormatSpec $spec) {
    [$p, $l] = b2both($spec);
    foreach ([$p, $l] as $frame) {
        mt_srand(1);
        $frame->fill(b2Value($spec))->setSegment(30, 18, 20, 20, b2Value($spec))->setPixels([[0, 0, b2Value($spec)], [3, 4, b2Value($spec)]])->setRegion([[5, 5], [6, 7]], b2Value($spec));
    }
    expect($p->dump())->toBe($l->dump());

    $p->clear();
    $l->clear();
    expect($p->dump())->toBe($l->dump());
})->with('blend2d formats');

it('takes RGBA8 in, and reads it out, as lustrous-php does', function (FormatSpec $spec) {
    [$p, $l] = b2both($spec, rgba8: random_bytes(BW * BH * 4));
    $region = new Region(3, 2, 11, 9);

    expect($p->dump())->toBe($l->dump())
        ->and(b2furthest($p->toRgba8(), $l->toRgba8()))->toBeLessThanOrEqual(b2Slack($spec))
        ->and(b2furthest($p->flush(FormatSpec::bgra8()), $l->flush(FormatSpec::bgra8())))->toBeLessThanOrEqual(b2Slack($spec))
        ->and(b2furthest($p->flushRegion($region, FormatSpec::rgba8()), $l->flushRegion($region, FormatSpec::rgba8())))->toBeLessThanOrEqual(b2Slack($spec))
        ->and($p->flush($spec))->toBe($l->flush($spec));
})->with('blend2d formats');

it('scrolls to the same bytes', function (FormatSpec $spec) {
    [$p, $l] = b2both($spec, rgba8: random_bytes(BW * BH * 4));
    foreach ([[3, -2], [-5, 4], [0, 7]] as [$dx, $dy]) {
        $p->scroll(new Region(2, 2, 30, 18), $dx, $dy);
        $l->scroll(new Region(2, 2, 30, 18), $dx, $dy);
    }

    expect($p->dump())->toBe($l->dump());
})->with('blend2d formats');

it('blits between images, and to and from Lustrous, without changing a byte', function (FormatSpec $spec) {
    [$p, $l] = b2both($spec, rgba8: random_bytes(BW * BH * 4));
    $copy = new Blend2DFramebufferImage($spec, BW, BH);
    $lustrous = new FullFramebuffer(new PHPLandFramebufferDriver(), $spec, BW, BH);

    $p->blitTo($copy);
    $p->blitTo($lustrous);
    expect($copy->dump())->toBe($p->dump())
        ->and($lustrous->dump())->toBe($l->dump());

    $back = new Blend2DFramebufferImage($spec, BW, BH);
    $back->blitFrom($lustrous);
    expect($back->dump())->toBe($l->dump());
})->with('blend2d formats');

it('blends spans and masks within its tolerance of lustrous-php', function (FormatSpec $spec) {
    for ($t = 0; $t < 30; $t++) {
        $background = random_bytes(BW * BH * 4);
        $colour = mt_rand(0, 0xFFFFFFFF);

        $spans = '';
        for ($y = 0; $y < BH; $y += mt_rand(1, 2)) {
            for ($x = mt_rand(0, 3); $x < BW - 1; $x += mt_rand(2, 9)) {
                $length = mt_rand(1, min(6, BW - $x));
                $spans .= Spans::pack($y, $x, $length, mt_rand(1, 255));
                $x += $length;
            }
        }
        [$p, $l] = b2both($spec, rgba8: $background);
        $p->paintSpans($spans, $colour);
        $l->paintSpans($spans, $colour);
        expect(b2furthest($p->toRgba8(), $l->toRgba8()))->toBeLessThanOrEqual(Blend2DFramebufferImage::TOLERANCE);

        $masks = [[random_bytes(10 * 6), 10, mt_rand(-3, BW - 5), mt_rand(-3, BH - 3)], [random_bytes(4 * 9), 4, mt_rand(0, BW - 4), mt_rand(0, BH - 9)]];
        [$p, $l] = b2both($spec, rgba8: $background);
        $p->paintMasks($masks, $colour, new Region(1, 1, BW - 2, BH - 2));
        $l->paintMasks($masks, $colour, new Region(1, 1, BW - 2, BH - 2));
        expect(b2furthest($p->toRgba8(), $l->toRgba8()))->toBeLessThanOrEqual(Blend2DFramebufferImage::TOLERANCE);
    }
})->with('blend2d formats');

it('paints gradients within its tolerance of lustrous-php', function (FormatSpec $spec, string $kind, GradientSpread $spread) {
    $ramp = '';
    for ($i = 0; $i < 256; $i++) {
        $ramp .= pack('C4', $i, 255 - $i, (int) (128 + 127 * sin($i / 20)), (int) (60 + $i * 0.7));
    }
    $spans = '';
    for ($y = 0; $y < BH; $y++) {
        $spans .= Spans::pack($y, 1, BW - 2, mt_rand(1, 255));
    }
    [$p, $l] = b2both($spec, rgba8: random_bytes(BW * BH * 4));
    $inverse = $kind === 'radial' ? [1 / 30, 0.0, 0.0, 1 / 25, -0.1, -0.2] : [1 / 30, 0.004, -0.01, 1 / 25, -0.1, -0.2];
    $p->paintGradient($spans, $ramp, $kind, $spread, $inverse);
    $l->paintGradient($spans, $ramp, $kind, $spread, $inverse);

    expect(b2furthest($p->toRgba8(), $l->toRgba8()))->toBeLessThanOrEqual(Blend2DFramebufferImage::TOLERANCE);
})->with('blend2d formats')->with(['linear', 'radial'])->with([GradientSpread::PAD, GradientSpread::REPEAT, GradientSpread::REFLECT]);

it('paints another framebuffer within its tolerance of lustrous-php', function (FormatSpec $spec, Affine $placement, Filter $filter, int $opacity, int $tint, ?array $window, bool $coverage) {
    $W = 48;
    $H = 40;
    for ($t = 0; $t < 10; $t++) {
        [$p, $l] = b2both($spec, $W, $H, random_bytes($W * $H * 4));
        [$ps, $ls] = b2both(FormatSpec::bgra8(), 9, 7, random_bytes(9 * 7 * 4));
        $spans = null;
        if ($coverage) {
            $spans = '';
            for ($y = 0; $y < $H; $y++) {
                if ($y % 3 !== 0) {
                    $spans .= Spans::pack($y, 2 + $y % 5, 20, mt_rand(1, 255));
                }
            }
        }
        $p->paintImage($ps, $placement, $opacity, $filter, null, $tint, $window, $spans);
        $l->paintImage($ls, $placement, $opacity, $filter, null, $tint, $window, $spans);

        expect(b2furthest($p->toRgba8(), $l->toRgba8()))->toBeLessThanOrEqual(Blend2DFramebufferImage::TOLERANCE);
    }
})->with('blend2d formats')->with([
    'moved' => [Affine::translation(11, 7), Filter::NEAREST, 255, 0xFFFFFFFF, null, false],
    'scaled, linear, translucent, tinted' => [Affine::translation(4.3, 3.1)->multiply(Affine::scaling(1.7, 1.3)), Filter::LINEAR, 180, 0xFF8040C0, null, false],
    'turned, nearest' => [Affine::translation(24, 6)->multiply(Affine::rotation(M_PI / 6))->multiply(Affine::scaling(2, 2)), Filter::NEAREST, 255, 0xFFFFFFFF, null, false],
    'turned, windowed' => [Affine::translation(30, 4)->multiply(Affine::rotation(1.3))->multiply(Affine::scaling(2.5, 1.5)), Filter::LINEAR, 230, 0xFFFFFFFF, [1.5, 1.0, 7.2, 5.5], false],
    'scaled, through coverage' => [Affine::translation(3.25, 2.25)->multiply(Affine::scaling(3, 2.5)), Filter::NEAREST, 200, 0xFFFFFFFF, null, true],
]);

it('offers its pixels as memory for drawing in place', function () {
    $image = new Blend2DFramebufferImage(FormatSpec::bgra8(), 8, 4);
    $memory = $image->memory();

    expect($memory)->not->toBeNull()
        ->and($image->pointer())->not->toBe(0)
        ->and($memory->width)->toBe(8)
        ->and($memory->height)->toBe(4)
        ->and($image->drewInto(new Region(0, 0, 2, 2), null))->toBe($image);
});

it('keeps its own pixels when cloned', function () {
    $a = new Blend2DFramebufferImage(FormatSpec::bgra8(), 4, 2);
    $a->fill(0x11223344);
    $b = clone $a;
    $b->fill(0xAABBCCDD);

    expect($a->getPixel(0, 0))->toBe(0x11223344)
        ->and($b->getPixel(0, 0))->toBe(0xAABBCCDD);
});

it('hands out reads that later drawing does not change', function () {
    $image = new Blend2DFramebufferImage(FormatSpec::bgra8(), 4, 2);
    $image->fill(0x11223344);
    $dump = $image->dump();
    $image->fill(0xAABBCCDD);

    expect($dump)->toBe(str_repeat("\x33\x22\x11\x44", 8));
});

// Library to library is a pointer: C sources are read where they are (venusian-velvet/tests/Support).
moduleTicketChecks('blend2d', fn (FormatSpec $f, int $w, int $h) => new Blend2DFramebufferImage($f, $w, $h), FormatSpec::bgra8(), [
    'rgba8' => FormatSpec::rgba8(),
    'bgra8' => FormatSpec::bgra8(),
]);
