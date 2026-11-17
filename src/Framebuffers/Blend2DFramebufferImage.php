<?php

namespace Jovian\Rendering\Blend2D\Framebuffers;

use BLContextCore;
use BLFormatInfo;
use BLGradientCore;
use BLImageCore;
use BLImageData;
use BLLinearGradientValues;
use BLMatrix2D;
use BLPatternCore;
use BLPixelConverterCore;
use BLPointI;
use BLRadialGradientValues;
use BLRectI;
use Surface\Contracts\Rendering\Framebuffers\DamageGranularity;
use Surface\Contracts\Rendering\Framebuffers\Filter;
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Framebuffers\FramebufferException;
use Surface\Contracts\Rendering\Framebuffers\InstanceThatManagesAFramebuffer;
use Surface\Contracts\Rendering\Framebuffers\PixelMemory;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Painting\PaintTarget;
use Surface\NutsAndBolts\Affine;
use Surface\NutsAndBolts\GradientSpread;
use Surface\Rendering\Framebuffers\FramebufferDelegate;
use VelvetGE\Contracts\Framebuffers\CompositingFramebuffer;
use VelvetGE\Contracts\Framebuffers\GradientFramebuffer;
use VelvetGE\Contracts\Framebuffers\ScrollingFramebuffer;
use VelvetGE\Framebuffers\ImagePlacement;
use VelvetGE\Framebuffers\Layout;
use VelvetGE\Framebuffers\MaskList;
use VelvetGE\Framebuffers\ReadsBlitSources;
use VelvetGE\Framebuffers\SpanList;

/**
 * One Blend2D image, Blend2D's way. Blend2D's colour format is PRGB32, which lies in memory as
 * B G R A bytes, so the image is a BGRA8 framebuffer and takes no other format. Its pixels are a
 * string Blend2D draws into (bl_image_init_as_from_data), holding the format's bytes: straight
 * colours, as the framework defines them.
 *
 * Every write goes through a Blend2D context: bytes land by SRC_COPY blits and repeating 1x1
 * patterns, which move them as they are; blends are SRC_OVER of a solid, a gradient or a pattern
 * of another framebuffer's pixels, through an A8 mask. Reads take the bytes straight from the
 * string. RGBA8 crosses through Blend2D's pixel converter as a shuffle of bytes.
 *
 * Blend2D blends as if the pixels were premultiplied, so over a translucent destination pixel
 * its result is not Lustrous's straight-alpha lerp: blended bytes land within TOLERANCE of
 * lustrous-php. No effects (no filter chains, Kawase levels or glass) and no kinds.
 */
final class Blend2DFramebufferImage extends FramebufferDelegate implements CompositingFramebuffer, GradientFramebuffer, ScrollingFramebuffer, PaintTarget
{
    use ReadsBlitSources;

    /** How far a blended byte may land from lustrous-php's: 5% of 255, as ruled for library framebuffers. */
    public const int TOLERANCE = 13;

    private string $pixels;

    private BLImageCore $image;

    /** @throws FramebufferException For any format but BGRA8, or when Blend2D cannot take the pixels. */
    public function __construct(
        private readonly FormatSpec $format,
        private readonly int $width,
        private readonly int $height,
    ) {
        if (! $format->equals(FormatSpec::bgra8())) {
            throw FramebufferException::unsupportedFormat($format, 'Blend2D holds colour as PRGB32: BGRA8 only.');
        }
        Layout::size($width, $height);
        $this->adopt(str_repeat("\0", $width * $height * 4));
    }

    /** A clone gets its own pixels: Blend2D draws into the string, so two frames must not share one. */
    public function __clone()
    {
        $this->adopt(self::own($this->pixels));
    }

    public function viewportWidth(): int
    {
        return $this->width;
    }

    public function viewportHeight(): int
    {
        return $this->height;
    }

    public function hostFormat(): FormatSpec
    {
        return $this->format;
    }

    /** The string Blend2D draws into: BGRA8 rows, stride width × 4. The image owns it. */
    public function memory(): ?PixelMemory
    {
        return new PixelMemory($this->pointer(), $this->width, $this->height, $this->width * 4, $this->format, $this->image);
    }

    public function pointer(): int
    {
        $data = new BLImageData();
        bl_image_get_data($this->image, $data);

        return $data->pixel_data;
    }

    public function drewInto(?Region ...$regions): static
    {
        foreach ($regions as $region) {
            $this->wrote($region);
        }

        return $this;
    }

    public function getPixel(int $x, int $y): int
    {
        $this->guard($x, $y);
        [, $b, $g, $r, $a] = unpack('C4', $this->pixels, ($y * $this->width + $x) * 4);

        return ($r << 24) | ($g << 16) | ($b << 8) | $a;
    }

    public function setPixel(int $x, int $y, int $value): static
    {
        $this->guard($x, $y);
        $this->put(new Region($x, $y, 1, 1), self::encode($value));

        return $this->wrote(new Region($x, $y, 1, 1));
    }

    public function setPixels(array $pixels): static
    {
        $bounds = $this->bounds($pixels, 3, '[x, y, value]');
        foreach ($pixels as [$x, $y, $value]) {
            $this->put(new Region($x, $y, 1, 1), self::encode($value));
        }

        return $this->wrote($bounds);
    }

    public function setRegion(array $coordinates, int $value): static
    {
        $bounds = $this->bounds($coordinates, 2, '[x, y]');
        $cell = self::encode($value);
        foreach ($coordinates as [$x, $y]) {
            $this->put(new Region($x, $y, 1, 1), $cell);
        }

        return $this->wrote($bounds);
    }

    /** A rect of one value, clipped to the surface: a rect straddling the edge is ordinary. */
    public function setSegment(int $x, int $y, int $width, int $height, int $color): static
    {
        $region = (new Region($x, $y, max(0, $width), max(0, $height)))->intersect($this->whole());
        if (is_null($region)) {
            return $this;
        }
        $this->tile($region, self::encode($color));

        return $this->wrote($region);
    }

    public function clear(): static
    {
        return $this->fill(0);
    }

    public function fill(int $color): static
    {
        $this->tile($this->whole(), self::encode($color));

        return $this->wrote($this->whole());
    }

    public function writeRgba8(string $rgba8, int $width, int $height, int $x = 0, int $y = 0): static
    {
        if ($width < 1 || $height < 1 || strlen($rgba8) !== $width * $height * 4) {
            throw new FramebufferException("writeRgba8() takes {$width}x{$height} RGBA8 pixels (".(max(0, $width) * max(0, $height) * 4).' bytes), got '.strlen($rgba8).'.');
        }
        $target = (new Region($x, $y, $width, $height))->intersect($this->whole());
        if (is_null($target)) {
            return $this;
        }
        $this->blit(self::shuffled($rgba8, $width, $height), $width, $height, new Region($target->x - $x, $target->y - $y, $target->width, $target->height), $target->x, $target->y);

        return $this->wrote($target);
    }

    public function blitTo(InstanceThatManagesAFramebuffer $target, int $offset_x = 0, int $offset_y = 0): InstanceThatManagesAFramebuffer
    {
        return $target->blitFrom($this, $offset_x, $offset_y);
    }

    public function blitFrom(InstanceThatManagesAFramebuffer $source, int $offset_x = 0, int $offset_y = 0): InstanceThatManagesAFramebuffer
    {
        if ($source instanceof self) {
            $target = (new Region($offset_x, $offset_y, $source->width, $source->height))->intersect($this->whole());
            if (is_null($target)) {
                return $this;
            }
            $this->blit($source->pixels, $source->width, $source->height, new Region($target->x - $offset_x, $target->y - $offset_y, $target->width, $target->height), $target->x, $target->y);

            return $this->wrote($target);
        }
        $view = $this->view($source, premultiply: false);
        if (! is_null($view)) {
            [$image, $width, $height] = $view;
            $target = (new Region($offset_x, $offset_y, $width, $height))->intersect($this->whole());
            if (is_null($target)) {
                return $this;
            }
            $this->blitImage($image, new Region($target->x - $offset_x, $target->y - $offset_y, $target->width, $target->height), $target->x, $target->y);

            return $this->wrote($target);
        }
        [$rgba8, $width, $height, $top] = $this->blitSource($source);

        return $this->writeRgba8($rgba8, $width, $height, $offset_x, $offset_y + $top);
    }

    public function toRgba8(): string
    {
        return self::shuffled($this->pixels, $this->width, $this->height);
    }

    public function dump(?int $layer = null): string
    {
        if (! is_null($layer) && $layer !== 0) {
            throw new FramebufferException("A Blend2D image has one layer, 0; got {$layer}.");
        }

        return self::own($this->pixels);
    }

    public function flush(FormatSpec $spec, bool $as_array = false): string|array
    {
        return self::answer($this->in($this->whole(), $spec), $as_array);
    }

    public function flushRegion(Region $region, FormatSpec $spec, bool $as_array = false): string|array
    {
        if ($region->isEmpty() || $region->x < 0 || $region->y < 0 || $region->right() > $this->width || $region->bottom() > $this->height) {
            throw FramebufferException::outsideSurface($region, $this->width, $this->height);
        }

        return self::answer($this->in($region, $spec), $as_array);
    }

    /** A blank frame of $width x $height. Blend2D holds BGRA8 only, so the scratch frame is BGRA8, not RGBA8. */
    public function scratch(int $width, int $height): InstanceThatManagesAFramebuffer
    {
        return new self(FormatSpec::bgra8(), $width, $height);
    }

    public function damageGranularity(): DamageGranularity
    {
        return DamageGranularity::pixel($this->width, $this->height);
    }

    public function preservesContentsOnPresent(): bool
    {
        return true;
    }

    public function scroll(Region $region, int $dx, int $dy): void
    {
        $clip = $region->intersect($this->whole());
        $source = is_null($clip) ? null : self::scrollSource($clip, $dx, $dy);
        if (is_null($source)) {
            return;
        }
        // Blend2D's blit within one image is not a move: through a scratch image, both by SRC_COPY.
        $held = new BLImageCore();
        self::check(bl_image_init_as($held, $source->width, $source->height, BL_FORMAT_PRGB32), 'make the scroll scratch');
        $context = new BLContextCore();
        self::check(bl_context_init_as($context, $held, null), 'open a context');
        bl_context_set_comp_op($context, BL_COMP_OP_SRC_COPY);
        bl_context_blit_image_i($context, self::pointI(0, 0), $this->image, self::rectI($source));
        self::check(bl_context_end($context), 'finish drawing');
        $this->blitImage($held, new Region(0, 0, $source->width, $source->height), $source->x + $dx, $source->y + $dy);
        $this->touched($clip);
    }

    public function paintSpans(string $spans, int $rgba8): static
    {
        [$list, $bounds] = SpanList::read($spans, $rgba8, $this->width, $this->height);
        if (is_null($bounds) || ($rgba8 & 0xFF) === 0) {
            return is_null($bounds) ? $this : $this->wrote($bounds);
        }
        // One mask per pass. Spans paint in list order, so a span overlapping one already in the
        // mask blends a second time: the mask so far is painted first.
        $blank = str_repeat("\0", $bounds->width * $bounds->height);
        $mask = $blank;
        $taken = [];
        foreach ($list as [$y, $x, $length, $coverage]) {
            foreach ($taken[$y] ?? [] as [$x0, $x1]) {
                if ($x < $x1 && $x + $length > $x0) {
                    $this->solidThrough($rgba8, $mask, $bounds);
                    $mask = $blank;
                    $taken = [];
                    break;
                }
            }
            $taken[$y][] = [$x, $x + $length];
            $mask = substr_replace($mask, str_repeat(chr($coverage), $length), ($y - $bounds->y) * $bounds->width + $x - $bounds->x, $length);
        }
        $this->solidThrough($rgba8, $mask, $bounds);

        return $this->wrote($bounds);
    }

    public function paintMasks(array $masks, int $rgba8, Region $clip): static
    {
        MaskList::check($masks, $rgba8, $clip, $this->width, $this->height);
        $left = $top = PHP_INT_MAX;
        $right = $bottom = PHP_INT_MIN;
        foreach ($masks as [$coverage, $width, $mx, $my]) {
            $y0 = max($my, $clip->y);
            $y1 = min($my + intdiv(strlen($coverage), $width), $clip->bottom());
            $x0 = max($mx, $clip->x);
            $x1 = min($mx + $width, $clip->right());
            if ($y0 >= $y1 || $x0 >= $x1) {
                continue;
            }
            $w = $x1 - $x0;
            $rows = '';
            for ($y = $y0; $y < $y1; $y++) {
                $row = substr($coverage, ($y - $my) * $width + $x0 - $mx, $w);
                $lead = strspn($row, "\0");
                if ($lead < $w) {
                    $left = min($left, $x0 + $lead);
                    $right = max($right, $x0 + strlen(rtrim($row, "\0")));
                    $top = min($top, $y);
                    $bottom = max($bottom, $y + 1);
                }
                $rows .= $row;
            }
            if (($rgba8 & 0xFF) !== 0) {
                $this->solidThrough($rgba8, $rows, new Region($x0, $y0, $w, $y1 - $y0));
            }
        }

        return $right === PHP_INT_MIN ? $this : $this->wrote(new Region($left, $top, $right - $left, $bottom - $top));
    }

    /**
     * Another framebuffer's pixels through $placement, Blend2D's way: the source premultiplied by
     * Blend2D's pixel converter (tinted first, when tinted), as a pattern with the placement as its
     * transform and its edges held (PAD), sampled nearest or bilinear, filled SRC_OVER through one
     * A8 mask: the pixels whose centre maps inside the source (or $window), times $opacity and
     * $coverage.
     */
    public function paintImage(InstanceThatManagesAFramebuffer $source, Affine $placement, int $opacity = 255, Filter $filter = Filter::NEAREST, ?Region $clip = null, int $tint = 0xFFFFFFFF, ?array $window = null, ?string $coverage = null): static
    {
        // A tint is a per-channel multiply Blend2D has no operator for, so a tinted picture is made
        // in PHP from the read-back; an untinted one is premultiplied by Blend2D from the source's memory.
        $view = $tint === 0xFFFFFFFF ? $this->view($source, premultiply: true) : null;
        if (is_null($view)) {
            [$rgba8, $sw, $sh, $top] = $this->blitSource($source);
        } else {
            [$picture, $sw, $sh] = $view;
            $top = 0;
        }
        $plan = ImagePlacement::plan($placement, $sw, $sh, $top, $this->whole(), $clip, $opacity, $window);
        if (is_null($plan) || $opacity === 0) {
            return $this;
        }
        [$target, $inverse, $held] = $plan;
        if (is_null($view)) {
            if ($tint !== 0xFFFFFFFF) {
                $rgba8 = self::tint($rgba8, $tint);
            }
            $picture = new BLImageCore();
            $premultiplied = self::convert($rgba8, $sw, $sh, premultiply: true);
            self::check(bl_image_init_as_from_data($picture, $sw, $sh, BL_FORMAT_PRGB32, $premultiplied, $sw * 4, BL_DATA_ACCESS_RW), 'take the source pixels');
        }

        [$a, $b, $c, $d, $e, $f] = $inverse;
        $forward = (new Affine($a, $b, $c, $d, $e, $f))->inverse();
        if (is_null($forward)) {
            return $this;
        }
        $pattern = new BLPatternCore();
        self::check(bl_pattern_init_as($pattern, $picture, null, BL_EXTEND_MODE_PAD, self::matrix($forward)), 'make the image pattern');

        $mask = $this->footprint($target, $inverse, $held ?? [0.0, 0.0, (float) $sw, (float) $sh], $opacity, is_null($coverage) ? null : $this->coverageRows($coverage, $target));
        $this->draw(function (BLContextCore $context) use ($pattern, $mask, $target, $filter): void {
            bl_context_set_hint($context, BL_CONTEXT_HINT_PATTERN_QUALITY, $filter === Filter::LINEAR ? BL_PATTERN_QUALITY_BILINEAR : BL_PATTERN_QUALITY_NEAREST);
            bl_context_set_fill_style($context, $pattern);
            bl_context_fill_mask_i($context, self::pointI($target->x, $target->y), self::a8($mask, $target->width, $target->height), null);
        });

        return $this->wrote($target);
    }

    /**
     * A gradient through spans, Blend2D's way: a Blend2D linear (t = u) or radial (t = hypot(u, v))
     * gradient over the ramp's 256 colours, its transform the placement the inverse undoes, its
     * extend mode the spread, filled SRC_OVER through the spans' coverage.
     */
    public function paintGradient(string $spans, string $ramp, string $kind, GradientSpread $spread, array $inverse): static
    {
        if (strlen($ramp) !== 1024) {
            throw new FramebufferException('paintGradient() takes a ramp of 256 RGBA colours (1024 bytes), got '.strlen($ramp).'.');
        }
        if (count($inverse) !== 6 || array_filter($inverse, fn (mixed $n): bool => (is_int($n) || is_float($n)) && is_finite($n)) !== $inverse) {
            throw new FramebufferException('paintGradient() takes an inverse of six finite numbers.');
        }
        [$list, $bounds] = SpanList::read($spans, 0xFFFFFFFF, $this->width, $this->height);
        if (is_null($bounds)) {
            return $this;
        }
        [$a, $b, $c, $d, $e, $f] = array_values($inverse);
        $forward = (new Affine((float) $a, (float) $b, (float) $c, (float) $d, (float) $e, (float) $f))->inverse();
        if (is_null($forward)) {
            return $this;
        }
        [$type, $values] = match ($kind) {
            'linear' => [BL_GRADIENT_TYPE_LINEAR, self::linear()],
            'radial' => [BL_GRADIENT_TYPE_RADIAL, self::radial()],
            default => throw new FramebufferException("paintGradient() takes 'linear' or 'radial', got '{$kind}'."),
        };
        $gradient = new BLGradientCore();
        self::check(bl_gradient_init_as($gradient, $type, $values, match ($spread) {
            GradientSpread::PAD => BL_EXTEND_MODE_PAD,
            GradientSpread::REPEAT => BL_EXTEND_MODE_REPEAT,
            GradientSpread::REFLECT => BL_EXTEND_MODE_REFLECT,
        }, [], 0, self::matrix($forward)), 'make the gradient');
        for ($i = 0; $i < 256; $i++) {
            [, $r, $g, $bl, $al] = unpack('C4', $ramp, $i * 4);
            bl_gradient_add_stop_rgba32($gradient, $i / 255, ($al << 24) | ($r << 16) | ($g << 8) | $bl);
        }

        $mask = str_repeat("\0", $bounds->width * $bounds->height);
        foreach ($list as [$y, $x, $length, $coverage]) {
            $mask = substr_replace($mask, str_repeat(chr($coverage), $length), ($y - $bounds->y) * $bounds->width + $x - $bounds->x, $length);
        }
        $this->draw(function (BLContextCore $context) use ($gradient, $mask, $bounds): void {
            bl_context_set_fill_style($context, $gradient);
            bl_context_fill_mask_i($context, self::pointI($bounds->x, $bounds->y), self::a8($mask, $bounds->width, $bounds->height), null);
        });

        return $this->wrote($bounds);
    }

    /** The pixels as Blend2D's image: a fresh string, and the image drawing into it. */
    private function adopt(string $pixels): void
    {
        $this->pixels = $pixels;
        $this->image = new BLImageCore();
        self::check(bl_image_init_as_from_data($this->image, $this->width, $this->height, BL_FORMAT_PRGB32, $this->pixels, $this->width * 4, BL_DATA_ACCESS_RW), "take a {$this->width}x{$this->height} image");
    }

    /** Runs $paint on a context over the image, then ends it, so the bytes are in the string when it returns. */
    private function draw(\Closure $paint): void
    {
        $context = new BLContextCore();
        self::check(bl_context_init_as($context, $this->image, null), 'open a context');
        $paint($context);
        self::check(bl_context_end($context), 'finish drawing');
    }

    /** BGRA8 rows of $width x $height (a $area of them) landed at ($x, $y) by SRC_COPY: the bytes as they are. */
    private function blit(string $bgra8, int $width, int $height, Region $area, int $x, int $y): void
    {
        $source = new BLImageCore();
        self::check(bl_image_init_as_from_data($source, $width, $height, BL_FORMAT_PRGB32, $bgra8, $width * 4, BL_DATA_ACCESS_RW), 'take the pixels to copy');
        $this->draw(function (BLContextCore $context) use ($source, $area, $x, $y): void {
            bl_context_set_comp_op($context, BL_COMP_OP_SRC_COPY);
            bl_context_blit_image_i($context, self::pointI($x, $y), $source, self::rectI($area));
        });
    }

    /** A rect ($area) of $image landed at ($x, $y) by SRC_COPY: the bytes as they are. */
    private function blitImage(BLImageCore $image, Region $area, int $x, int $y): void
    {
        $this->draw(function (BLContextCore $context) use ($image, $area, $x, $y): void {
            bl_context_set_comp_op($context, BL_COMP_OP_SRC_COPY);
            bl_context_blit_image_i($context, self::pointI($x, $y), $image, self::rectI($area));
        });
    }

    /**
     * $source's pixels as a Blend2D image, read from its memory with no PHP bytes: BGRA8 memory
     * is wrapped where it is (its bytes as PRGB32, moved as they are); RGBA8 memory, or any memory
     * when $premultiply, goes through Blend2D's converter into a new image, address to address.
     * Null for memory in another layout, or none: the caller reads back.
     *
     * @return array{BLImageCore, int, int}|null
     */
    private function view(InstanceThatManagesAFramebuffer $source, bool $premultiply): ?array
    {
        $memory = $this->sourceMemory($source);
        if (is_null($memory) || $memory->stride % 4 !== 0) {
            return null;
        }
        $rgba = $memory->format->equals(FormatSpec::rgba8());
        if (! $rgba && ! $memory->format->equals(FormatSpec::bgra8())) {
            return null;
        }
        $image = new BLImageCore();
        if (! $rgba && ! $premultiply) {
            $address = $memory->address;
            self::check(bl_image_init_as_from_data($image, $memory->width, $memory->height, BL_FORMAT_PRGB32, $address, $memory->stride, BL_DATA_ACCESS_READ), 'take the source memory');

            return [$image, $memory->width, $memory->height];
        }
        self::check(bl_image_init_as($image, $memory->width, $memory->height, BL_FORMAT_PRGB32), 'make the source image');
        $data = new BLImageData();
        bl_image_get_data($image, $data);
        $into = $data->pixel_data;
        self::check(bl_pixel_converter_convert(self::converter($rgba, $premultiply), $into, $data->stride, $memory->address, $memory->stride, $memory->width, $memory->height, null), 'convert the source memory');

        return [$image, $memory->width, $memory->height];
    }

    /** Packed BGRA8 rows into a rect. */
    private function put(Region $region, string $bgra8): void
    {
        $this->blit(self::own($bgra8), $region->width, $region->height, new Region(0, 0, $region->width, $region->height), $region->x, $region->y);
    }

    /** One BGRA8 cell over a rect: a repeating 1x1 pattern, SRC_COPY. */
    private function tile(Region $region, string $cell): void
    {
        $cell = self::own($cell);
        $one = new BLImageCore();
        self::check(bl_image_init_as_from_data($one, 1, 1, BL_FORMAT_PRGB32, $cell, 4, BL_DATA_ACCESS_RW), 'take the fill colour');
        $pattern = new BLPatternCore();
        self::check(bl_pattern_init_as($pattern, $one, null, BL_EXTEND_MODE_REPEAT, null), 'make the fill pattern');
        $this->draw(function (BLContextCore $context) use ($pattern, $region): void {
            bl_context_set_comp_op($context, BL_COMP_OP_SRC_COPY);
            bl_context_set_fill_style($context, $pattern);
            bl_context_fill_rect_i($context, self::rectI($region));
        });
    }

    /** 0xRRGGBBAA, straight, through an A8 mask over $at, SRC_OVER: Blend2D premultiplies the solid. */
    private function solidThrough(int $rgba8, string $mask, Region $at): void
    {
        $argb = (($rgba8 & 0xFF) << 24) | (($rgba8 >> 8) & 0xFFFFFF);
        $this->draw(function (BLContextCore $context) use ($argb, $mask, $at): void {
            bl_context_set_fill_style_rgba32($context, $argb);
            bl_context_fill_mask_i($context, self::pointI($at->x, $at->y), self::a8($mask, $at->width, $at->height), null);
        });
    }

    /** A rect's BGRA8 bytes, rows packed, top row first. */
    private function read(Region $region): string
    {
        if ($region->x === 0 && $region->width === $this->width) {
            return self::own(substr($this->pixels, $region->y * $this->width * 4, $region->height * $this->width * 4));
        }
        $rows = '';
        for ($y = $region->y; $y < $region->bottom(); $y++) {
            $rows .= substr($this->pixels, ($y * $this->width + $region->x) * 4, $region->width * 4);
        }

        return $rows;
    }

    /** A rect's bytes in $spec: BGRA8 as they are, RGBA8 by the converter's shuffle; nothing else. */
    private function in(Region $region, FormatSpec $spec): string
    {
        return match (true) {
            $spec->equals(FormatSpec::bgra8()) => $this->read($region),
            $spec->equals(FormatSpec::rgba8()) => self::shuffled($this->read($region), $region->width, $region->height),
            default => throw FramebufferException::unsupportedFormat($spec, 'a Blend2D image converts to BGRA8 and RGBA8 only.'),
        };
    }

    /**
     * Per row of $target, the pixels whose centre maps (through $inverse) inside $held, as A8 at
     * $opacity, times the coverage of the span holding each pixel when $coverage rows are given.
     *
     * @param  array{float, float, float, float, float, float}  $inverse
     * @param  array{float, float, float, float}  $held
     * @param  list<string>|null  $coverage
     */
    private function footprint(Region $target, array $inverse, array $held, int $opacity, ?array $coverage): string
    {
        [$a, $b, $c, $d, $e, $f] = $inverse;
        [$u0, $v0, $u1, $v1] = $held;
        $level = chr($opacity);
        $mask = '';
        for ($row = 0; $row < $target->height; $row++) {
            $cy = $target->y + $row + 0.5;
            // u = a·x + (c·cy + e), v = b·x + (d·cy + f): each bound holds x in an interval.
            [$lo, $hi] = [-INF, INF];
            foreach ([[$a, $c * $cy + $e, $u0, $u1], [$b, $d * $cy + $f, $v0, $v1]] as [$k, $m, $low, $high]) {
                if ($k == 0.0) {
                    if (! ($m >= $low && $m < $high)) {
                        [$lo, $hi] = [INF, -INF];
                    }
                    continue;
                }
                [$p, $q] = [($low - $m) / $k, ($high - $m) / $k];
                // Inside means low <= u < high: inclusive at the low bound, whichever way x runs.
                if ($k > 0) {
                    $lo = max($lo, $p);
                    $hi = min($hi, $q);
                } else {
                    $lo = max($lo, $q);
                    $hi = min($hi, $p);
                }
            }
            // Pixel centres x + 0.5 in the interval, and the target's columns.
            $first = max($target->x, (int) ceil($lo - 0.5));
            $last = min($target->right(), (int) ceil($hi - 0.5));
            $line = str_repeat("\0", $target->width);
            if ($first < $last) {
                $line = substr_replace($line, str_repeat($level, $last - $first), $first - $target->x, $last - $first);
            }
            if (! is_null($coverage)) {
                $line = self::scaled($line, $coverage[$row]);
            }
            $mask .= $line;
        }

        return $mask;
    }

    /**
     * Spans as one coverage row per row of $target (0 where no span holds a pixel).
     *
     * @return list<string>
     */
    private function coverageRows(string $spans, Region $target): array
    {
        [$list] = SpanList::read($spans, 0xFFFFFFFF, $this->width, $this->height);
        $rows = array_fill(0, $target->height, str_repeat("\0", $target->width));
        foreach ($list as [$y, $x, $length, $coverage]) {
            $x0 = max($x, $target->x);
            $x1 = min($x + $length, $target->right());
            if ($y < $target->y || $y >= $target->bottom() || $x0 >= $x1) {
                continue;
            }
            $rows[$y - $target->y] = substr_replace($rows[$y - $target->y], str_repeat(chr($coverage), $x1 - $x0), $x0 - $target->x, $x1 - $x0);
        }

        return $rows;
    }

    /** Two A8 rows multiplied byte by byte, (a × b + 127) / 255. */
    private static function scaled(string $a, string $b): string
    {
        $out = '';
        for ($i = 0, $n = strlen($a); $i < $n; $i++) {
            $x = ord($a[$i]);
            $out .= $x === 0 ? "\0" : chr(intdiv($x * ord($b[$i]) + 127, 255));
        }

        return $out;
    }

    /** Straight RGBA8 times a 0xRRGGBBAA tint per channel, alpha included: c = (c × t + 127) / 255. */
    private static function tint(string $rgba8, int $tint): string
    {
        $t = [($tint >> 24) & 0xFF, ($tint >> 16) & 0xFF, ($tint >> 8) & 0xFF, $tint & 0xFF];
        $out = '';
        for ($i = 0, $n = strlen($rgba8); $i < $n; $i++) {
            $out .= chr(intdiv(ord($rgba8[$i]) * $t[$i & 3] + 127, 255));
        }

        return $out;
    }

    /** RGBA8 to BGRA8 or back: the same shuffle (bytes 0 and 2 swap), by Blend2D's converter. */
    private static function shuffled(string $bytes, int $width, int $height): string
    {
        return self::convert($bytes, $width, $height, premultiply: false);
    }

    /**
     * Blend2D's pixel converter between RGBA8 and BGRA8 bytes. Told both sides are premultiplied,
     * it moves bytes and nothing else; told the source is straight, it premultiplies on the way.
     */
    private static function convert(string $bytes, int $width, int $height, bool $premultiply): string
    {
        static $converters = [];
        $converter = $converters[(int) $premultiply] ??= (function () use ($premultiply): BLPixelConverterCore {
            $layout = function (bool $rgba, int $flags): BLFormatInfo {
                $info = new BLFormatInfo();
                $info->depth = 32;
                $info->flags = BL_FORMAT_FLAG_RGBA | BL_FORMAT_FLAG_BYTE_ALIGNED | $flags;
                [$info->r_size, $info->g_size, $info->b_size, $info->a_size] = [8, 8, 8, 8];
                [$info->r_shift, $info->g_shift, $info->b_shift, $info->a_shift] = $rgba ? [0, 8, 16, 24] : [16, 8, 0, 24];

                return $info;
            };
            $converter = new BLPixelConverterCore();
            bl_pixel_converter_init($converter);
            $pre = BL_FORMAT_FLAG_PREMULTIPLIED;
            self::check(bl_pixel_converter_create($converter, $layout(false, $pre), $layout(true, $premultiply ? 0 : $pre), BL_PIXEL_CONVERTER_CREATE_NO_FLAGS), 'make a pixel converter');

            return $converter;
        })();
        $out = str_repeat("\0", strlen($bytes));
        self::check(bl_pixel_converter_convert($converter, $out, $width * 4, $bytes, $width * 4, $width, $height, null), 'convert pixels');

        return $out;
    }

    /**
     * Blend2D's converter from straight RGBA8 ($rgba) or BGRA8 memory into PRGB32 bytes: told both
     * sides are premultiplied it moves bytes only; told the source is straight it premultiplies.
     */
    private static function converter(bool $rgba, bool $premultiply): BLPixelConverterCore
    {
        static $made = [];

        return $made[(int) $rgba][(int) $premultiply] ??= (function () use ($rgba, $premultiply): BLPixelConverterCore {
            $layout = function (bool $rgbaOrder, int $flags): BLFormatInfo {
                $info = new BLFormatInfo();
                $info->depth = 32;
                $info->flags = BL_FORMAT_FLAG_RGBA | BL_FORMAT_FLAG_BYTE_ALIGNED | $flags;
                [$info->r_size, $info->g_size, $info->b_size, $info->a_size] = [8, 8, 8, 8];
                [$info->r_shift, $info->g_shift, $info->b_shift, $info->a_shift] = $rgbaOrder ? [0, 8, 16, 24] : [16, 8, 0, 24];

                return $info;
            };
            $converter = new BLPixelConverterCore();
            bl_pixel_converter_init($converter);
            $pre = BL_FORMAT_FLAG_PREMULTIPLIED;
            self::check(bl_pixel_converter_create($converter, $layout(false, $pre), $layout($rgba, $premultiply ? 0 : $pre), BL_PIXEL_CONVERTER_CREATE_NO_FLAGS), 'make a pixel converter');

            return $converter;
        })();
    }

    /** A pixel value (0xRRGGBBAA) as BGRA8 bytes. */
    private static function encode(int $value): string
    {
        return pack('C4', ($value >> 8) & 0xFF, ($value >> 16) & 0xFF, ($value >> 24) & 0xFF, $value & 0xFF);
    }

    /** A copy of $bytes no other string shares: Blend2D writes where it is given, so nothing it holds may be shared. */
    private static function own(string $bytes): string
    {
        if ($bytes === '') {
            return '';
        }
        $copy = $bytes;
        $copy[0] = $bytes[0];

        return $copy;
    }

    private static function a8(string $mask, int $width, int $height): BLImageCore
    {
        $image = new BLImageCore();
        // Blend2D's JIT reads and writes an A8 row in 4-byte steps: a row's stride is a multiple of 4, padded with 0
        $stride = ($width + 3) & ~3;
        $mask = $stride === $width
            ? self::own($mask)
            : implode('', array_map(fn (string $row): string => str_pad($row, $stride, "\0"), str_split($mask, $width)));
        self::check(bl_image_init_as_from_data($image, $width, $height, BL_FORMAT_A8, $mask, $stride, BL_DATA_ACCESS_RW), 'take a coverage mask');

        return $image;
    }

    private static function matrix(Affine $m): BLMatrix2D
    {
        $matrix = new BLMatrix2D();
        [$matrix->m00, $matrix->m01, $matrix->m10, $matrix->m11, $matrix->m20, $matrix->m21] = [$m->a, $m->b, $m->c, $m->d, $m->e, $m->f];

        return $matrix;
    }

    private static function linear(): BLLinearGradientValues
    {
        $values = new BLLinearGradientValues();
        [$values->x0, $values->y0, $values->x1, $values->y1] = [0.0, 0.0, 1.0, 0.0];

        return $values;
    }

    private static function radial(): BLRadialGradientValues
    {
        $values = new BLRadialGradientValues();
        [$values->x0, $values->y0, $values->x1, $values->y1, $values->r0, $values->r1] = [0.0, 0.0, 0.0, 0.0, 1.0, 0.0];

        return $values;
    }

    private static function pointI(int $x, int $y): BLPointI
    {
        $point = new BLPointI();
        [$point->x, $point->y] = [$x, $y];

        return $point;
    }

    private static function rectI(Region $region): BLRectI
    {
        $rect = new BLRectI();
        [$rect->x, $rect->y, $rect->w, $rect->h] = [$region->x, $region->y, $region->width, $region->height];

        return $rect;
    }

    /** @throws FramebufferException When Blend2D answers an error. */
    private static function check(int $result, string $doing): void
    {
        if ($result !== 0) {
            throw new FramebufferException("Blend2D could not {$doing} (error {$result}).");
        }
    }

    private function whole(): Region
    {
        return Region::wholeSurface($this->width, $this->height);
    }

    /**
     * The whole list checked before anything is written, and its bounding box.
     *
     * @param  array<int, array<int, int>>  $pixels
     */
    private function bounds(array $pixels, int $arity, string $shape): ?Region
    {
        $left = $top = PHP_INT_MAX;
        $right = $bottom = PHP_INT_MIN;
        foreach (array_values($pixels) as $position => $pixel) {
            if (! is_array($pixel) || count($pixel) !== $arity || ! is_int($pixel[0] ?? null) || ! is_int($pixel[1] ?? null) || ($arity === 3 && ! is_int($pixel[2] ?? null))) {
                throw new FramebufferException("Pixel {$position} is not {$shape}.");
            }
            $this->guard($pixel[0], $pixel[1]);
            $left = min($left, $pixel[0]);
            $top = min($top, $pixel[1]);
            $right = max($right, $pixel[0]);
            $bottom = max($bottom, $pixel[1]);
        }

        return $pixels === [] ? null : new Region($left, $top, $right - $left + 1, $bottom - $top + 1);
    }
}
