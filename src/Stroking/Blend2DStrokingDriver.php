<?php

namespace Jovian\Rendering\Blend2D\Stroking;

use Surface\Contracts\Rendering\Rasterization\StrokeGeometry;
use Surface\Rendering\Stroking\StrokingDriver;

/** 'blend2d': Blend2D's stroker. No dashes: Blend2D has none. */
class Blend2DStrokingDriver extends StrokingDriver
{
    private Blend2DStroker $stroker;

    public function __construct()
    {
        $this->stroker = new Blend2DStroker();
    }

    public function driver(): string
    {
        return 'blend2d';
    }

    public function stroker(): StrokeGeometry
    {
        return $this->stroker;
    }
}
