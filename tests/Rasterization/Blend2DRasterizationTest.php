<?php

declare(strict_types=1);

/*
 * Runs inside jovian/venusian-velvet's test suite (it uses topside's SpanGrid and Lustrous as the
 * reference): copy it into that package's tests/ with this package autoloaded.
 *
 * Every number here was measured 2026-11-15 (100 random shapes a kind, a 200 × 150 clip) and is
 * Blend2D's own result: nothing is tuned toward Lustrous. Blend2D's curve tolerance is set to the
 * 0.05 px Velvet flattens outlines to.
 */

use Jovian\Engines\Velvet\Lustrous\Framebuffers\CLandFramebufferDriver;
use Jovian\Engines\Velvet\Lustrous\Rasterization\PHPLandRasterizationDriver;
use Jovian\Rendering\Blend2D\Rasterization\Blend2DRasterizationDriver;
use Jovian\Rendering\Blend2D\Rasterization\Blend2DScanner;
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Framebuffers\PixelMemory;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Rasterization\Edges;
use Surface\Contracts\Rendering\Rasterization\FillRule;
use Surface\Contracts\Rendering\Rasterization\Rasterizer;
use Surface\Contracts\Rendering\Rasterization\Scanner;
use Venusian\Topside\Tests\Support\Rasterization\SpanGrid;


/** @return array<int, int> pixel (y * 65536 + x) => coverage */
function b2d_cells(string $spans): array
{
    $cells = [];
    foreach (Surface\Contracts\Rendering\Framebuffers\Spans::unpack($spans) as [$y, $x, $length, $coverage]) {
        for ($i = 0; $i < $length; $i++) {
            $cells[$y * 65536 + $x + $i] = $coverage;
        }
    }

    return $cells;
}

function b2d_r(): float
{
    return mt_rand(0, 19000) / 100;
}

/**
 * The same shape $n times through Blend2D and through Lustrous's PHP scanner.
 *
 * @return array{int, float, float} worst pixel distance, share of pixels that differ, share more than 13 apart
 */
function b2d_against_lustrous(Closure $shape, Edges $edges, int $n = 100): array
{
    $clip = new Region(0, 0, 200, 150);
    $blend2d = (new Blend2DRasterizationDriver())->rasterizer($clip, $edges);
    $lustrous = (new PHPLandRasterizationDriver())->rasterizer($clip, $edges);
    [$worst, $differ, $over, $all] = [0, 0, 0, 0];
    for ($i = 0; $i < $n; $i++) {
        mt_srand(1000 + $i);
        $a = $shape($blend2d);
        mt_srand(1000 + $i);
        $b = $shape($lustrous);
        SpanGrid::assertWellFormed($a, $clip, $edges);
        $p = b2d_cells($a);
        $q = b2d_cells($b);
        foreach ($p + $q as $k => $_) {
            $d = abs(($p[$k] ?? 0) - ($q[$k] ?? 0));
            $worst = max($worst, $d);
            $differ += $d > 0 ? 1 : 0;
            $over += $d > 13 ? 1 : 0;
            $all++;
        }
    }

    return [$worst, $differ / $all, $over / $all];
}

dataset('blend2d shapes', [
    'triangle' => [fn (Rasterizer $z) => $z->fillTriangle(b2d_r(), b2d_r(), b2d_r(), b2d_r(), b2d_r(), b2d_r()), 13, 0.0],
    'rect' => [fn (Rasterizer $z) => $z->fillRect(b2d_r() / 2, b2d_r() / 2, b2d_r() / 2, b2d_r() / 2), 13, 0.0],
    'ellipse' => [fn (Rasterizer $z) => $z->fillEllipse(b2d_r(), b2d_r() * 0.7, 1 + b2d_r() / 4, 1 + b2d_r() / 4), 13, 0.0],
    'ring' => [fn (Rasterizer $z) => $z->strokeEllipse(b2d_r(), b2d_r() * 0.7, 2 + b2d_r() / 4, 2 + b2d_r() / 4, 1 + b2d_r() / 40), 255, 0.0005],
    'round rect' => [fn (Rasterizer $z) => $z->fillRoundRect(b2d_r() / 2, b2d_r() / 3, 10 + b2d_r() / 2, 10 + b2d_r() / 3, [b2d_r() / 40, b2d_r() / 40, b2d_r() / 40, b2d_r() / 40]), 13, 0.0],
    'round ring' => [fn (Rasterizer $z) => $z->strokeRoundRect(b2d_r() / 2, b2d_r() / 3, 10 + b2d_r() / 2, 10 + b2d_r() / 3, [b2d_r() / 40, b2d_r() / 40, b2d_r() / 40, b2d_r() / 40], 1 + b2d_r() / 60), 13, 0.0],
    // a self-crossing polygon: where its edges cross inside one pixel the two resolve the overlap differently
    'self-crossing polygon' => [fn (Rasterizer $z) => $z->fillPolygon([[b2d_r(), b2d_r() * 0.7], [b2d_r(), b2d_r() * 0.7], [b2d_r(), b2d_r() * 0.7], [b2d_r(), b2d_r() * 0.7], [b2d_r(), b2d_r() * 0.7]], FillRule::EVEN_ODD), 255, 0.001],
]);

it('fills anti-aliased shapes within the measured distance of Lustrous', function (Closure $shape, int $worst, float $over): void {
    [$w, , $o] = b2d_against_lustrous($shape, Edges::ANTIALIASED);

    expect($w)->toBeLessThanOrEqual($worst)
        ->and($o)->toBeLessThanOrEqual($over);
})->with('blend2d shapes');

it('draws anti-aliased when asked for hard edges, and says so: Blend2D has no hard-edged mode', function (Closure $shape): void {
    $clip = new Region(0, 0, 200, 150);
    $hard = (new Blend2DRasterizationDriver())->rasterizer($clip, Edges::HARD);
    $soft = (new Blend2DRasterizationDriver())->rasterizer($clip, Edges::ANTIALIASED);

    expect($hard->edges())->toBe(Edges::ANTIALIASED);
    $partial = 0;
    for ($n = 0; $n < 40; $n++) {
        mt_srand(7000 + $n);
        $asked = $shape($hard);
        mt_srand(7000 + $n);
        expect($asked)->toBe($shape($soft));
        $partial += count(array_filter(b2d_cells((string) $asked), fn (int $c): bool => $c < 255));
    }
    expect($partial)->toBeGreaterThan(0);
})->with('blend2d shapes');

it('answers the same coverage as a mask as it does as spans, at every row width', function (): void {
    $scanner = new Blend2DScanner(new Region(0, 0, 200, 150));
    for ($i = 0; $i < 60; $i++) {
        mt_srand(3000 + $i);
        // widths of every remainder by 4: the coverage rows Blend2D writes are padded to 4 and trimmed
        $op = [Scanner::ELLIPSE, b2d_r(), b2d_r() * 0.7, 1 + $i / 2, 2 + b2d_r() / 8];
        $spans = $scanner->ellipse(...array_slice($op, 1));
        $mask = $scanner->mask($op);
        if (is_null($mask)) {
            expect($spans)->toBe('');

            continue;
        }
        [$a8, $width, $left, $top] = $mask;
        $fromMask = [];
        foreach (str_split($a8, $width) as $row => $bytes) {
            foreach (str_split($bytes) as $col => $byte) {
                if ($byte !== "\0") {
                    $fromMask[($top + $row) * 65536 + $left + $col] = ord($byte);
                }
            }
        }
        expect($fromMask)->toBe(b2d_cells($spans));
    }
});

it('hands back a shadow undrawn, and draws a thin line as an outline: Blend2D has no shadow and no one-pixel line', function (): void {
    $z = (new Blend2DRasterizationDriver())->rasterizer(new Region(0, 0, 200, 150), Edges::HARD);

    expect($z->shadow(10.0, 10.0, 60.0, 40.0, 5.0, 4.0))->toBeNull()
        ->and($z->line(5.0, 5.0, 150.0, 90.0))->not->toBeNull()->not->toBe('')
        ->and($z->line(5.0, 5.0, 150.0, 90.0, 3.0))->not->toBe('');
});

it('draws in place into 32-bit B G R A rows only', function (): void {
    $frames = new CLandFramebufferDriver();
    $memory = fn (FormatSpec $spec): PixelMemory => $frames->full($spec, 8, 4)->memory();
    $scanner = new Blend2DScanner(new Region(0, 0, 8, 4));

    expect($scanner->drawsInto($memory(FormatSpec::bgra8())))->toBeTrue()
        ->and($scanner->drawsInto($memory(FormatSpec::rgba8())))->toBeFalse();
});

it('blends in place within 2 of the frame blending the same coverage, over pixels of every alpha', function (): void {
    $frames = new CLandFramebufferDriver();
    $driver = new Blend2DRasterizationDriver();
    $clip = new Region(0, 0, 160, 120);
    $worst = 0;
    for ($n = 0; $n < 60; $n++) {
        mt_srand(5000 + $n);
        $background = (new \Random\Randomizer(new \Random\Engine\Mt19937(5200 + $n)))->getBytes(160 * 120 * 4);   // seeded, so a failure repeats
        [$direct, $staged] = [$frames->full(FormatSpec::bgra8(), 160, 120), $frames->full(FormatSpec::bgra8(), 160, 120)];
        $direct->writeRgba8($background, 160, 120);
        $staged->writeRgba8($background, 160, 120);
        $r = fn (): float => mt_rand(0, 15500) / 100;
        $colour = (mt_rand(0, 0xFFFFFF) << 8) | mt_rand(20, 255);
        $shape = $n % 2 === 0
            ? ['path', [[[$r(), $r() * 0.75], [$r(), $r() * 0.75], [$r(), $r() * 0.75], [$r(), $r() * 0.75]]], FillRule::NON_ZERO, $colour, $clip]
            : ['ellipse', $r(), $r() * 0.75, 2 + $r() / 4, 2 + $r() / 4, $colour, $clip];
        $driver->rasterizer($clip, Edges::ANTIALIASED)->fillBatch([$shape], $direct, true);
        $driver->rasterizer($clip, Edges::ANTIALIASED)->fillBatch([$shape], $staged, false);
        [$a, $b] = [$direct->toRgba8(), $staged->toRgba8()];
        for ($i = 0, $len = strlen($a); $i < $len; $i++) {
            $worst = max($worst, abs(ord($a[$i]) - ord($b[$i])));
        }
    }

    expect($worst)->toBeLessThanOrEqual(2);
});

it('is the blend2d driver', function (): void {
    expect((new Blend2DRasterizationDriver())->driver())->toBe('blend2d');
});
