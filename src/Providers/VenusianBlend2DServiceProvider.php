<?php

namespace Jovian\Rendering\Blend2D\Providers;

use Jovian\Rendering\Blend2D\Framebuffers\Blend2DFramebufferDriver;
use Jovian\Rendering\Blend2D\Images\Blend2DImageDecoderDriver;
use Jovian\Rendering\Blend2D\Painting\Blend2DPaintbrushDriver;
use Jovian\Rendering\Blend2D\Rasterization\Blend2DRasterizationDriver;
use Jovian\Rendering\Blend2D\Stroking\Blend2DStrokingDriver;
use ReflectionException;
use Voyager\NutsAndBolts\ServiceProvider;

class VenusianBlend2DServiceProvider extends ServiceProvider
{
    /**
     * @throws ReflectionException
     */
    public function boot(): void
    {
        $this->registerVelvetDrivers();
    }

    /**
     * @throws ReflectionException
     */
    protected function registerVelvetDrivers(): void
    {
        $this->registerVelvetFramebufferDriver();
        $this->registerVelvetRasterizationDriver();
        $this->registerVelvetPaintbrushDriver();
        $this->registerStrokingDriver();
        $this->registerImageDecoder();
    }

    /** 'blend2d' on Velvet's framebuffers.
     * @throws ReflectionException
     */
    protected function registerVelvetFramebufferDriver(): void
    {
        app('velvet.framebuffers')->extend('blend2d', fn () => new Blend2DFramebufferDriver());
    }

    /** 'blend2d' on Velvet's rasterizers.
     * @throws ReflectionException
     */
    protected function registerVelvetRasterizationDriver(): void
    {
        app('velvet.rasterizers')->extend('blend2d', fn () => new Blend2DRasterizationDriver());
    }

    /**
     * @throws ReflectionException
     */
    protected function registerVelvetPaintbrushDriver(): void
    {
        app('velvet.painters')->extend('blend2d', fn () => new Blend2DPaintbrushDriver());
    }

    /**
     * 'blend2d' on Topside's strokers: stroking is Rendering's, for every engine, not Velvet's.
     *
     * @throws ReflectionException
     */
    /** 'blend2d' on Images' decoders. PNG and JPEG, written straight into a PixelMemory. */
    protected function registerImageDecoder(): void
    {
        if (! extension_loaded('blend2d')) {
            return;
        }
        app('images.decoders')->extend('blend2d', fn () => new Blend2DImageDecoderDriver());
    }

    protected function registerStrokingDriver(): void
    {
        app('strokers')->extend('blend2d', fn () => new Blend2DStrokingDriver());
    }
}
