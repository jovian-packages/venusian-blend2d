<?php

namespace Jovian\Rendering\Blend2D\Providers;

use Jovian\Rendering\Blend2D\Framebuffers\Blend2DFramebufferDriver;
use Jovian\Rendering\Blend2D\Rasterization\Blend2DRasterizationDriver;
use Voyager\NutsAndBolts\ServiceProvider;

class VenusianBlend2DServiceProvider extends ServiceProvider
{
    public function register(): void
    {

    }

    public function boot(): void
    {
        $this->registerVelvetDrivers();
    }

    protected function registerVelvetDrivers(): void
    {
        $this->registerVelvetFramebufferDriver();
        $this->registerVelvetRasterizationDriver();
    }

    /** 'blend2d' on Velvet's framebuffers. */
    protected function registerVelvetFramebufferDriver(): void
    {
        app('velvet.framebuffers')->extend('blend2d', fn () => new Blend2DFramebufferDriver());
    }

    /** 'blend2d' on Velvet's rasterizers. */
    protected function registerVelvetRasterizationDriver(): void
    {
        app('velvet.rasterizers')->extend('blend2d', fn () => new Blend2DRasterizationDriver());
    }
}
