<?php

namespace Jovian\Rendering\Blend2D\Images;

use Surface\Contracts\Images\ImageDecoder;
use Surface\Contracts\Images\ImageException;
use Surface\Images\ImageDecoderDriver;

/** Registers Blend2D's address decoder where ext-blend2d is loaded. */
final class Blend2DImageDecoderDriver extends ImageDecoderDriver
{
    /**
     * @throws ImageException When ext-blend2d is not loaded. Images skips a decoder that throws this.
     */
    public function __construct()
    {
        extension_loaded('blend2d') || throw new ImageException('ext-blend2d is not loaded in this PHP.');
    }

    public function driver(): string
    {
        return 'blend2d';
    }

    public function decoder(): ImageDecoder
    {
        return new Blend2DImageDecoder();
    }
}
