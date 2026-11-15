<?php

namespace Jovian\Rendering\Blend2D\Framebuffers;

use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Framebuffers\FramebufferException;
use Surface\Rendering\Framebuffers\FramebufferDriver;

/** Blend2D as a Velvet framebuffer module: every make() is one Blend2DFramebufferImage (BGRA8). */
class Blend2DFramebufferDriver extends FramebufferDriver
{
    /** @throws FramebufferException When ext-blend2d is not loaded. */
    public function __construct()
    {
        if (! extension_loaded('blend2d')) {
            throw FramebufferException::extensionMissing('blend2d', '0.11.0');
        }
    }

    public function driver(): string
    {
        return 'blend2d';
    }

    /** @throws FramebufferException For any format but BGRA8. */
    public function make(FormatSpec $format, int $width, int $height): Blend2DFramebufferImage
    {
        return new Blend2DFramebufferImage($format, $width, $height);
    }
}
