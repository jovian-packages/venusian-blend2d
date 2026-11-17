<?php

namespace Jovian\Rendering\Blend2D\Painting;

use Jovian\Rendering\Blend2D\Rasterization\Blend2DRasterizationDriver;
use Surface\Contracts\Rendering\Painting\Brush;
use Surface\Contracts\Rendering\Rasterization\RasterizeDriver;
use Surface\Contracts\Rendering\Recording\RecordDriver;
use Surface\Contracts\Rendering\RenderingException;
use VelvetGE\Painting\BrushPaintingDriver;

/**
 * 'blend2d' painting: VelvetGE's painter with Blend2DBrush. What the brush does not take is
 * rasterized by Blend2D too, unless another rasterizer is given. Without ext-blend2d the
 * constructor throws.
 */
class Blend2DPaintbrushDriver extends BrushPaintingDriver
{
    /** As BrushPaintingDriver takes them: what VelvetGE's painter rasterizes the rest with (Blend2D's own when null), direct fills, and what frames are recorded with. */
    public function __construct(?RasterizeDriver $rasterize = null, bool $directFill = true, ?RecordDriver $record = null)
    {
        extension_loaded('blend2d') || throw new RenderingException('ext-blend2d is not loaded in this PHP ('.PHP_BINARY.').');
        parent::__construct($rasterize ?? new Blend2DRasterizationDriver(), $directFill, $record);
    }

    public function driver(): string
    {
        return 'blend2d';
    }

    public function brush(): Brush
    {
        return new Blend2DBrush();
    }
}
