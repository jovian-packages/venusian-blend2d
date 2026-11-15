<?php

namespace Jovian\Rendering\Blend2D\Rasterization;

use BLContextCore;
use BLImageCore;
use BLPathCore;
use BLPoint;
use BLRectI;
use Surface\Contracts\Rendering\Framebuffers\ChannelOrder;
use Surface\Contracts\Rendering\Framebuffers\FramebufferException;
use Surface\Contracts\Rendering\Framebuffers\PixelMemory;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Framebuffers\ScanDirection;
use Surface\Contracts\Rendering\Rasterization\DirectScanner;
use Surface\Contracts\Rendering\Rasterization\Edges;
use Surface\Contracts\Rendering\Rasterization\FillRule;
use Surface\Contracts\Rendering\Rasterization\MaskScanner;
use Surface\Contracts\Rendering\Rasterization\RasterizeException;
use Surface\Contracts\Rendering\Rasterization\Scanner;
use Surface\Contracts\Rendering\Rasterization\ShapeScanner;
use VelvetGE\Framebuffers\Layout;

/**
 * Blend2D's scanner: paths and the curved shapes, each as Blend2D geometry filled by Blend2D.
 *
 * - mask() answers an op's coverage as bytes: Blend2D fills it in white into an A8 image over a
 *   PHP string, so the coverage is written there by Blend2D and nothing is read back. Its rows are
 *   a multiple of 4 bytes apart (Blend2D's JIT writes past an A8 image otherwise) and trimmed after.
 * - path() and the shape calls run-length encode that same coverage into spans.
 * - fill() draws ops straight into 32-bit B G R A memory, as Blend2D's PRGB32.
 *
 * Blend2D has one rendering quality, anti-aliased, so edges() answers Edges::ANTIALIASED whatever
 * was asked for and that is what it draws.
 *
 * It has no shadow (PixelScanner), which Velvet hands back undrawn. One-pixel lines belong to hard
 * edges, so it is never asked for one: a thin stroke reaches it as an outline.
 */
class Blend2DScanner implements DirectScanner, MaskScanner, ShapeScanner
{
    /** A cubic's control distance for a quarter circle of radius 1. */
    private const float KAPPA = 0.5522847498307936;

    /**
     * How far Blend2D's flattened curves may stray from the curve, in pixels: Blend2D's own quality
     * setting, at the tolerance Velvet flattens outlines to (its default, 0.2, is coarser).
     */
    public const float FLATTEN = 0.05;

    public function __construct(
        private readonly Region $clip,
    ) {}

    public function clip(): Region
    {
        return $this->clip;
    }

    public function edges(): Edges
    {
        return Edges::ANTIALIASED;
    }

    public function path(array $contours, FillRule $rule): string
    {
        return $this->spans([Scanner::PATH, $contours, $rule === FillRule::EVEN_ODD ? 1 : 0]);
    }

    public function ellipse(float $cx, float $cy, float $rx, float $ry): string
    {
        return $this->spans([Scanner::ELLIPSE, $cx, $cy, $rx, $ry]);
    }

    public function ring(float $cx, float $cy, float $rx, float $ry, float $stroke): string
    {
        return $this->spans([Scanner::RING, $cx, $cy, $rx, $ry, $stroke]);
    }

    public function roundRect(float $x0, float $y0, float $x1, float $y1, array $radii): string
    {
        return $this->spans([Scanner::ROUND_RECT, $x0, $y0, $x1, $y1, $radii]);
    }

    public function roundRing(float $x0, float $y0, float $x1, float $y1, array $radii, float $inset): string
    {
        return $this->spans([Scanner::ROUND_RING, $x0, $y0, $x1, $y1, $radii, $inset]);
    }

    /** @param list<mixed> $op */
    public function mask(array $op): ?array
    {
        $coverage = $this->coverage($op);
        if (is_null($coverage)) {
            return null;
        }
        [$a8, $area] = $coverage;

        return [$a8, $area->width, $area->x, $area->y];
    }

    /**
     * 32-bit B G R A rows, top first, on a little-endian host: Blend2D's PRGB32 in memory.
     */
    public function drawsInto(PixelMemory $memory): bool
    {
        if (pack('V', 1) !== pack('L', 1) || $memory->stride % 4 !== 0 || $memory->stride < $memory->width * 4) {
            return false;
        }
        try {
            return Layout::of($memory->format) === Layout::RGBA8888
                && Layout::channelOrder($memory->format) === ChannelOrder::BGRA
                && $memory->format->scan_direction === ScanDirection::TOP_TO_BOTTOM;
        } catch (FramebufferException) {
            return false;
        }
    }

    /**
     * Each op drawn into $memory by Blend2D, inside the clip. The region answered for an op is its
     * extents inside the clip: every pixel it changed is in there.
     *
     * @param  list<list<mixed>>  $ops
     * @param  list<int>  $colours  0xRRGGBBAA
     */
    public function fill(array $ops, array $colours, PixelMemory $memory): array
    {
        $clip = $this->clip->intersect(new Region(0, 0, $memory->width, $memory->height));
        if (is_null($clip) || $clip->isEmpty()) {
            return array_fill(0, count($ops), null);
        }

        $image = new BLImageCore();
        $address = $memory->address;    // the call takes its pixel data by reference; an address is only read
        self::ok(bl_image_init_as_from_data($image, $memory->width, $memory->height, BL_FORMAT_PRGB32, $address, $memory->stride, BL_DATA_ACCESS_RW), 'take the memory');
        $context = new BLContextCore();
        self::ok(bl_context_init_as($context, $image, null), 'begin drawing');
        self::ok(bl_context_set_flatten_tolerance($context, self::FLATTEN), 'set the curve tolerance');
        self::ok(bl_context_clip_to_rect_i($context, new BLRectI($clip->x, $clip->y, $clip->width, $clip->height)), 'clip');

        $touched = [];
        foreach ($ops as $i => $op) {
            [$path, $rule, $bounds] = $this->geometry($op);
            $area = $bounds?->intersect($clip);
            if (is_null($area) || $area->isEmpty() || ($colours[$i] & 0xFF) === 0) {
                $touched[] = null;

                continue;
            }
            self::ok(bl_context_set_fill_rule($context, $rule), 'set the fill rule');
            // 0xRRGGBBAA, straight, as Blend2D's 0xAARRGGBB
            $rgba = $colours[$i];
            self::ok(bl_context_fill_path_d_rgba32($context, new BLPoint(0, 0), $path, (($rgba & 0xFF) << 24) | ($rgba >> 8 & 0xFFFFFF)), 'fill');
            $touched[] = $area;
        }
        self::ok(bl_context_end($context), 'finish drawing');

        return $touched;
    }

    /** @param list<mixed> $op */
    private function spans(array $op): string
    {
        $coverage = $this->coverage($op);
        if (is_null($coverage)) {
            return '';
        }
        [$a8, $area] = $coverage;
        $spans = '';
        for ($r = 0; $r < $area->height; $r++) {
            $row = substr($a8, $r * $area->width, $area->width);
            for ($o = 0; $o < $area->width;) {
                $byte = $row[$o];
                $run = strspn($row, $byte, $o);
                if ($byte !== "\0") {
                    $spans .= pack('vvvC', $area->y + $r, $area->x + $o, $run, ord($byte));
                }
                $o += $run;
            }
        }

        return $spans;
    }

    /**
     * The op's coverage over its extents inside the clip, rows packed, or null when it covers no
     * pixel there. Blend2D writes it into the string.
     *
     * @param  list<mixed>  $op
     * @return array{string, Region}|null
     */
    private function coverage(array $op): ?array
    {
        [$path, $rule, $bounds] = $this->geometry($op);
        $area = $bounds?->intersect($this->clip);
        if (is_null($area) || $area->isEmpty()) {
            return null;
        }

        // rows a multiple of 4 bytes apart: Blend2D's JIT writes past an A8 image otherwise (ext-blend2d refuses it)
        $stride = ($area->width + 3) & ~3;
        $a8 = str_repeat("\0", $stride * $area->height);
        $image = new BLImageCore();
        self::ok(bl_image_init_as_from_data($image, $area->width, $area->height, BL_FORMAT_A8, $a8, $stride, BL_DATA_ACCESS_RW), 'take the coverage bytes');
        $context = new BLContextCore();
        self::ok(bl_context_init_as($context, $image, null), 'begin drawing');
        self::ok(bl_context_set_flatten_tolerance($context, self::FLATTEN), 'set the curve tolerance');
        self::ok(bl_context_set_fill_rule($context, $rule), 'set the fill rule');
        // the image's pixel (0, 0) is the area's corner
        self::ok(bl_context_fill_path_d_rgba32($context, new BLPoint(-$area->x, -$area->y), $path, 0xFFFFFFFF), 'fill');
        self::ok(bl_context_end($context), 'finish drawing');
        unset($context, $image);

        if ($stride !== $area->width) {
            $a8 = implode('', array_map(fn (string $row): string => substr($row, 0, $area->width), str_split($a8, $stride)));
        }

        return strspn($a8, "\0") === strlen($a8) ? null : [$a8, $area];
    }

    /**
     * The op as a Blend2D path, the fill rule to fill it by, and the pixels its extents reach (null
     * when it has no outline).
     *
     * @param  list<mixed>  $op
     * @return array{BLPathCore, int, Region|null}
     */
    private function geometry(array $op): array
    {
        $path = new BLPathCore();
        bl_path_init($path);

        return match ($op[0] ?? null) {
            Scanner::PATH => [$path, (int) $op[2] === 1 ? BL_FILL_RULE_EVEN_ODD : BL_FILL_RULE_NON_ZERO, self::contours($path, $op[1])],
            Scanner::ELLIPSE => [$path, BL_FILL_RULE_NON_ZERO, self::ellipseOf($path, $op[1], $op[2], $op[3], $op[4])],
            Scanner::RING => self::ringOf($path, $op[1], $op[2], $op[3], $op[4], $op[5]),
            Scanner::ROUND_RECT => [$path, BL_FILL_RULE_NON_ZERO, self::roundRectOf($path, $op[1], $op[2], $op[3], $op[4], array_values($op[5]))],
            Scanner::ROUND_RING => self::roundRingOf($path, $op[1], $op[2], $op[3], $op[4], array_values($op[5]), $op[6]),
            default => throw new RasterizeException('Blend2DScanner has no op '.var_export($op[0] ?? null, true).'.'),
        };
    }

    /** @param list<list<float>> $contours flat x, y lists, closed implicitly */
    private static function contours(BLPathCore $path, array $contours): ?Region
    {
        [$x0, $y0, $x1, $y1] = [INF, INF, -INF, -INF];
        foreach ($contours as $flat) {
            $n = count($flat);
            if ($n < 6) {
                continue;
            }
            bl_path_move_to($path, $flat[0], $flat[1]);
            for ($i = 0; $i < $n; $i += 2) {
                if ($i > 0) {
                    bl_path_line_to($path, $flat[$i], $flat[$i + 1]);
                }
                [$x0, $x1] = [min($x0, $flat[$i]), max($x1, $flat[$i])];
                [$y0, $y1] = [min($y0, $flat[$i + 1]), max($y1, $flat[$i + 1])];
            }
            bl_path_close($path);
        }

        return $x0 === INF ? null : self::box($x0, $y0, $x1, $y1);
    }

    private static function ellipseOf(BLPathCore $path, float $cx, float $cy, float $rx, float $ry): Region
    {
        self::addEllipse($path, $cx, $cy, $rx, $ry);

        return self::box($cx - $rx, $cy - $ry, $cx + $rx, $cy + $ry);
    }

    /** @return array{BLPathCore, int, Region} the band between radii r ± stroke / 2 */
    private static function ringOf(BLPathCore $path, float $cx, float $cy, float $rx, float $ry, float $stroke): array
    {
        $half = $stroke / 2;
        self::addEllipse($path, $cx, $cy, $rx + $half, $ry + $half);
        if ($rx - $half > 0 && $ry - $half > 0) {
            self::addEllipse($path, $cx, $cy, $rx - $half, $ry - $half);
        }

        return [$path, BL_FILL_RULE_EVEN_ODD, self::box($cx - $rx - $half, $cy - $ry - $half, $cx + $rx + $half, $cy + $ry + $half)];
    }

    /** @param list<float> $radii [tl, tr, br, bl], already held to the box */
    private static function roundRectOf(BLPathCore $path, float $x0, float $y0, float $x1, float $y1, array $radii): Region
    {
        self::addRoundRect($path, $x0, $y0, $x1, $y1, $radii);

        return self::box($x0, $y0, $x1, $y1);
    }

    /**
     * @param  list<float>  $radii
     * @return array{BLPathCore, int, Region} the band inside the box, $inset wide; inner radii max(0, r − inset)
     */
    private static function roundRingOf(BLPathCore $path, float $x0, float $y0, float $x1, float $y1, array $radii, float $inset): array
    {
        self::addRoundRect($path, $x0, $y0, $x1, $y1, $radii);
        if ($x1 - $inset > $x0 + $inset && $y1 - $inset > $y0 + $inset) {
            self::addRoundRect($path, $x0 + $inset, $y0 + $inset, $x1 - $inset, $y1 - $inset, array_map(fn (float $r): float => max(0.0, $r - $inset), $radii));
        }

        return [$path, BL_FILL_RULE_EVEN_ODD, self::box($x0, $y0, $x1, $y1)];
    }

    private static function addEllipse(BLPathCore $p, float $cx, float $cy, float $rx, float $ry): void
    {
        [$kx, $ky] = [$rx * self::KAPPA, $ry * self::KAPPA];
        bl_path_move_to($p, $cx + $rx, $cy);
        bl_path_cubic_to($p, $cx + $rx, $cy + $ky, $cx + $kx, $cy + $ry, $cx, $cy + $ry);
        bl_path_cubic_to($p, $cx - $kx, $cy + $ry, $cx - $rx, $cy + $ky, $cx - $rx, $cy);
        bl_path_cubic_to($p, $cx - $rx, $cy - $ky, $cx - $kx, $cy - $ry, $cx, $cy - $ry);
        bl_path_cubic_to($p, $cx + $kx, $cy - $ry, $cx + $rx, $cy - $ky, $cx + $rx, $cy);
        bl_path_close($p);
    }

    /** @param list<float> $r [tl, tr, br, bl] */
    private static function addRoundRect(BLPathCore $p, float $x0, float $y0, float $x1, float $y1, array $r): void
    {
        [$tl, $tr, $br, $bl] = $r;
        $k = 1 - self::KAPPA;
        bl_path_move_to($p, $x0 + $tl, $y0);
        bl_path_line_to($p, $x1 - $tr, $y0);
        if ($tr > 0) {
            bl_path_cubic_to($p, $x1 - $tr * $k, $y0, $x1, $y0 + $tr * $k, $x1, $y0 + $tr);
        }
        bl_path_line_to($p, $x1, $y1 - $br);
        if ($br > 0) {
            bl_path_cubic_to($p, $x1, $y1 - $br * $k, $x1 - $br * $k, $y1, $x1 - $br, $y1);
        }
        bl_path_line_to($p, $x0 + $bl, $y1);
        if ($bl > 0) {
            bl_path_cubic_to($p, $x0 + $bl * $k, $y1, $x0, $y1 - $bl * $k, $x0, $y1 - $bl);
        }
        bl_path_line_to($p, $x0, $y0 + $tl);
        if ($tl > 0) {
            bl_path_cubic_to($p, $x0, $y0 + $tl * $k, $x0 + $tl * $k, $y0, $x0 + $tl, $y0);
        }
        bl_path_close($p);
    }

    /** The pixels a box of coordinates reaches. */
    private static function box(float $x0, float $y0, float $x1, float $y1): Region
    {
        [$left, $top] = [(int) floor($x0), (int) floor($y0)];

        return new Region($left, $top, (int) ceil($x1) - $left, (int) ceil($y1) - $top);
    }

    private static function ok(int $result, string $what): void
    {
        $result === BL_SUCCESS || throw new RasterizeException("Blend2D could not {$what} (error {$result}).");
    }
}
