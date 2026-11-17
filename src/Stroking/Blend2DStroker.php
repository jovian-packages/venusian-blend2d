<?php

namespace Jovian\Rendering\Blend2D\Stroking;

use BLApproximationOptions;
use BLPathCore;
use BLStrokeOptionsCore;
use Surface\Contracts\Rendering\Rasterization\StrokeCap;
use Surface\Contracts\Rendering\Rasterization\StrokeGeometry;
use Surface\Contracts\Rendering\Rasterization\StrokeJoin;
use Surface\Contracts\Rendering\RenderingException;
use Surface\NutsAndBolts\Path;

/**
 * Blend2D's stroker: bl_path_add_stroked_path() turns the polyline into Blend2D's own outline,
 * whose round joins and caps are curves; they are flattened to contours within 0.1 px. A miter
 * past the limit is cut to a bevel (BL_STROKE_JOIN_MITER_BEVEL).
 *
 * Blend2D 0.21's stroker has no dashing (it never reads its options' dash array), so this is not a
 * DashGeometry: a dashed stroke comes back undrawn. A zero-length stroke is Blend2D's, so the
 * direction it is given is not used.
 */
final class Blend2DStroker implements StrokeGeometry
{
    /** How far a flattened round join or cap strays from its arc, in pixels. */
    private const float FLATNESS = 0.1;

    public function __construct()
    {
        extension_loaded('blend2d') || throw new RenderingException('ext-blend2d is not loaded in this PHP ('.PHP_BINARY.').');
    }

    public function outline(array $points, float $stroke, bool $closed, StrokeCap $cap = StrokeCap::BUTT, StrokeJoin $join = StrokeJoin::MITER, float $miterLimit = self::MITER_LIMIT, ?array $direction = null): array
    {
        if (! ($stroke > 0) || $points === []) {
            return [];
        }
        $line = new BLPathCore();
        bl_path_init($line);
        foreach (array_values($points) as $i => [$x, $y]) {
            $i === 0 ? bl_path_move_to($line, (float) $x, (float) $y) : bl_path_line_to($line, (float) $x, (float) $y);
        }
        if ($closed) {
            bl_path_close($line);
        }

        $options = new BLStrokeOptionsCore();
        bl_stroke_options_init($options);
        $options->width = $stroke;
        $options->miter_limit = $miterLimit;
        $options->start_cap = $options->end_cap = match ($cap) {
            StrokeCap::BUTT => BL_STROKE_CAP_BUTT,
            StrokeCap::ROUND => BL_STROKE_CAP_ROUND,
            StrokeCap::SQUARE => BL_STROKE_CAP_SQUARE,
        };
        $options->join = match ($join) {
            StrokeJoin::MITER => BL_STROKE_JOIN_MITER_BEVEL,
            StrokeJoin::ROUND => BL_STROKE_JOIN_ROUND,
            StrokeJoin::BEVEL => BL_STROKE_JOIN_BEVEL,
        };
        $outline = new BLPathCore();
        bl_path_init($outline);
        $ok = bl_path_add_stroked_path($outline, $line, null, $options, new BLApproximationOptions(flatten_tolerance: self::FLATNESS));
        $ok === BL_SUCCESS || throw new RenderingException("Blend2D could not stroke the polyline (error {$ok}).");

        return self::contours($outline);
    }

    /** @return list<list<float>> the outline's contours, curves flattened */
    private static function contours(BLPathCore $outline): array
    {
        $commands = bl_path_get_command_data($outline);
        $vertices = bl_path_get_vertex_data($outline);
        $path = new Path();
        for ($i = 0, $n = strlen($commands); $i < $n;) {
            $v = $vertices[$i];
            switch (ord($commands[$i])) {
                case BL_PATH_CMD_MOVE:
                    $path->moveTo($v->x, $v->y);
                    $i++;
                    break;
                case BL_PATH_CMD_ON:
                    $path->lineTo($v->x, $v->y);
                    $i++;
                    break;
                case BL_PATH_CMD_QUAD:
                    $path->quadTo($v->x, $v->y, $vertices[$i + 1]->x, $vertices[$i + 1]->y);
                    $i += 2;
                    break;
                case BL_PATH_CMD_CUBIC:
                    $path->cubicTo($v->x, $v->y, $vertices[$i + 1]->x, $vertices[$i + 1]->y, $vertices[$i + 2]->x, $vertices[$i + 2]->y);
                    $i += 3;
                    break;
                case BL_PATH_CMD_CLOSE:
                    $path->close();
                    $i++;
                    break;
                default:
                    throw new RenderingException('Blend2D answered a stroke outline with a command this stroker does not read: '.ord($commands[$i]).'.');
            }
        }

        $contours = [];
        foreach ($path->flatten(self::FLATNESS) as ['points' => $ring]) {
            if (count($ring) >= 3) {
                $contours[] = array_merge(...$ring);
            }
        }

        return $contours;
    }
}
