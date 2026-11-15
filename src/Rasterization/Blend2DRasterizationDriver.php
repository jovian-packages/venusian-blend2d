<?php

namespace Jovian\Rendering\Blend2D\Rasterization;

use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Rasterization\Edges;
use Surface\Contracts\Rendering\Rasterization\RasterizeException;
use Surface\Contracts\Rendering\Rasterization\Scanner;
use VelvetGE\Rasterization\ScannerRasterizationDriver;

/**
 * 'blend2d': paths and curved shapes filled by Blend2D through ext-blend2d, always anti-aliased:
 * Blend2D has no hard-edged mode, so a request for hard edges is drawn anti-aliased too. A shadow it
 * has no answer for, and Velvet hands it back undrawn.
 */
class Blend2DRasterizationDriver extends ScannerRasterizationDriver
{
    public function __construct()
    {
        if (! extension_loaded('blend2d')) {
            throw RasterizeException::extensionMissing('blend2d', 'ext-blend2d');
        }
    }

    public function driver(): string
    {
        return 'blend2d';
    }

    protected function scanner(Region $clip, Edges $edges): Scanner
    {
        return new Blend2DScanner($clip);
    }
}
