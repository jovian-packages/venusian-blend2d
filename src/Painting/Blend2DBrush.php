<?php

namespace Jovian\Rendering\Blend2D\Painting;

use BLGradientCore;
use BLGradientStop;
use BLLinearGradientValues;
use BLMatrix2D;
use BLRadialGradientValues;
use BLRgba64;
use Jovian\Rendering\Blend2D\Rasterization\Blend2DRasterizationDriver;
use Jovian\Rendering\Blend2D\Rasterization\Blend2DScanner;
use Surface\Contracts\Rendering\Framebuffers\PixelMemory;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Painting\Brush;
use Surface\Contracts\Rendering\Rasterization\Edges;
use Surface\Contracts\Rendering\RenderingException;
use Surface\NutsAndBolts\GradientSpread;
use VelvetGE\Rasterization\Rasterizer;

/**
 * Blend2D's brush: each anti-aliased shape is drawn by Blend2D straight into the framebuffer's
 * memory, where that memory is Blend2D's own pixel layout (32-bit B G R A rows on a little-endian
 * host, Blend2DScanner::drawsInto()).
 *
 * Shapes are prepared by Blend2D's rasterizer (Rasterizer::lower()): radii held, a stroke turned
 * into its outline, curves as Blend2D geometry. The brush takes path, polyline, ellipse, ring,
 * roundrect and roundring commands in one colour, and gradpath with Blend2D's own gradient.
 * Hard edges, clear, shadow, glass, layer, image, glyphs and spans are VelvetGE's painter's, as is
 * every frame in another layout.
 */
final class Blend2DBrush implements Brush
{
    /**
     * Worst per-byte distance from VelvetGE's native painter, one anti-aliased shape at a time
     * (measured 2026-11-15 over 25 seeds a kind, B G R A and R G B A frames): rects 7, lines 2,
     * ellipses 6, round rects 27, rings 35. Where a path's own contours cross inside a pixel
     * (self-crossing fills, stroke joins, polylines, gradient paths) the two rasterisers resolve the
     * overlap differently and a pixel can differ by up to 100: at most 2.0% of the pixels that
     * differ at all (stroked paths), under 1% for every other kind.
     */
    public const int TOLERANCE = 35;

    /** The commands painted, and where each holds its clip. */
    private const array SHAPES = ['path' => 4, 'gradpath' => 4, 'polyline' => 5, 'ellipse' => 6, 'ring' => 7, 'roundrect' => 7, 'roundring' => 8];

    /** @var array<string, Rasterizer> by clip: what lowers a command's shape */
    private array $lowering = [];

    /** @var array<string, list<BLGradientStop>> the last few ramps' stops */
    private static array $ramps = [];

    public function __construct()
    {
        extension_loaded('blend2d') || throw new RenderingException('ext-blend2d is not loaded in this PHP ('.PHP_BINARY.').');
    }

    public function drawsInto(PixelMemory $memory): bool
    {
        return (new Blend2DScanner(new Region(0, 0, max(1, $memory->width), max(1, $memory->height))))->drawsInto($memory);
    }

    /** Anti-aliased shapes; a gradient only when its matrix can be turned round (Blend2D is given the inverse). */
    public function takes(array $command, Edges $edges): bool
    {
        if ($edges !== Edges::ANTIALIASED || ! isset(self::SHAPES[$command[0] ?? null])) {
            return false;
        }
        if ($command[0] !== 'gradpath') {
            return true;
        }
        $m = $command[3]['inverse'];

        return abs($m->a * $m->d - $m->b * $m->c) > 1e-12;
    }

    public function paint(array $commands, Region $rows, PixelMemory $memory, Edges $edges): array
    {
        $frame = new Region(0, 0, $memory->width, $memory->height);
        $touched = [];
        foreach ($commands as $command) {
            $cut = $command[self::SHAPES[$command[0]]]->intersect($rows)?->intersect($frame);
            $touched[] = is_null($cut) || $cut->isEmpty() ? null : $this->command($command, $cut, $edges, $memory);
        }

        return $touched;
    }

    /** One command inside $cut, drawn by Blend2D. Answers its extents inside $cut, or null when it draws nothing. */
    private function command(array $command, Region $cut, Edges $edges, PixelMemory $memory): ?Region
    {
        $rasterizer = $this->lowering["{$cut->x},{$cut->y},{$cut->width},{$cut->height}"] ??= (new Blend2DRasterizationDriver())->rasterizer($cut, $edges);
        $gradient = $command[0] === 'gradpath';
        [$ops, $colours] = $rasterizer->lower([$gradient ? ['path', $command[1], $command[2], 0xFF, $cut] : $command]);
        if ($ops === [] || (! $gradient && ($colours[0] & 0xFF) === 0)) {
            return null;
        }
        $scanner = new Blend2DScanner($cut);
        if (! $gradient) {
            return $scanner->fill($ops, $colours, $memory)[0];
        }

        return $scanner->fillGradient($ops[0], self::gradient($command[3]), $memory);
    }

    /**
     * The command's gradient as Blend2D's: the ramp's 256 colours as stops at i / 255, over the unit
     * line (linear, from (0, 0) to (1, 0)) or the unit circle (radial, about (0, 0)), placed by the
     * inverse of the command's matrix, which takes a pixel to the gradient's space.
     *
     * @param  array{kind: string, inverse: \Surface\NutsAndBolts\Affine, spread: GradientSpread, ramp: string}  $g
     */
    private static function gradient(array $g): BLGradientCore
    {
        $m = $g['inverse'];
        $det = $m->a * $m->d - $m->b * $m->c;
        $toPixels = new BLMatrix2D($m->d / $det, -$m->b / $det, -$m->c / $det, $m->a / $det, ($m->c * $m->f - $m->d * $m->e) / $det, ($m->b * $m->e - $m->a * $m->f) / $det);
        [$type, $values] = $g['kind'] === 'radial'
            ? [BL_GRADIENT_TYPE_RADIAL, new BLRadialGradientValues(0.0, 0.0, 0.0, 0.0, 1.0, 0.0)]
            : [BL_GRADIENT_TYPE_LINEAR, new BLLinearGradientValues(0.0, 0.0, 1.0, 0.0)];
        $extend = match ($g['spread']) {
            GradientSpread::PAD => BL_EXTEND_MODE_PAD,
            GradientSpread::REPEAT => BL_EXTEND_MODE_REPEAT,
            GradientSpread::REFLECT => BL_EXTEND_MODE_REFLECT,
        };
        $stops = self::stops($g['ramp']);
        $gradient = new BLGradientCore();
        $ok = bl_gradient_init_as($gradient, $type, $values, $extend, $stops, count($stops), $toPixels);
        $ok === BL_SUCCESS || throw new RenderingException("Blend2D could not make a gradient (error {$ok}).");

        return $gradient;
    }

    /** @return list<BLGradientStop> the ramp's 256 straight colours, each channel widened to 16 bits */
    private static function stops(string $ramp): array
    {
        if (isset(self::$ramps[$ramp])) {
            return self::$ramps[$ramp];
        }
        strlen($ramp) === 1024 || throw new RenderingException('A gradient ramp is 256 colours of 4 bytes.');
        $stops = [];
        foreach (array_values(unpack('C*', $ramp)) as $k => $byte) {
            $channel[$k % 4] = $byte * 257;
            if ($k % 4 === 3) {
                [$r, $g, $b, $a] = $channel;
                // 0xAAAARRRRGGGGBBBB, as Blend2D's BLRgba64
                $stops[] = new BLGradientStop(intdiv($k, 4) / 255, new BLRgba64(($a << 48) | ($r << 32) | ($g << 16) | $b));
            }
        }
        if (count(self::$ramps) >= 16) {
            array_shift(self::$ramps);
        }

        return self::$ramps[$ramp] = $stops;
    }
}
