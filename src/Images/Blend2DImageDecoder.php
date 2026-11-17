<?php

namespace Jovian\Rendering\Blend2D\Images;

use BLFormatInfo;
use BLImageCodecCore;
use BLImageCore;
use BLImageData;
use BLImageDecoderCore;
use BLImageInfo;
use BLPixelConverterCore;
use Surface\Contracts\Images\ImageDecoder;
use Surface\Contracts\Images\ImageException;
use Surface\Contracts\Images\ImageFormat;
use Surface\Contracts\Images\ImageInfo as DecodedInfo;
use Surface\Contracts\Images\ImageSource;
use Surface\Contracts\Rendering\Framebuffers\BitDepth;
use Surface\Contracts\Rendering\Framebuffers\ChannelOrder;
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Framebuffers\PixelFormat;
use Surface\Contracts\Rendering\Framebuffers\PixelMemory;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Framebuffers\ScanDirection;

/**
 * Blend2D's address decoder. A PNG comes back premultiplied (PRGB32); the converter divides that
 * alpha back out, so the PixelMemory is straight. A JPEG comes back opaque (XRGB32) and is copied
 * with alpha 255. BMP and QOI are codecs Blend2D has and Images does not name, so this decoder
 * does not claim them.
 *
 * The brush treats a BGRA frame's bytes as already premultiplied and copies them. This decoder
 * does not: PixelMemory is straight, and the converter makes it so.
 */
final class Blend2DImageDecoder implements ImageDecoder
{
    public function driver(): string
    {
        return 'blend2d';
    }

    public function formats(): array
    {
        return [ImageFormat::PNG, ImageFormat::JPEG];
    }

    public function writes(FormatSpec $format): bool
    {
        return $format->pixel_format === PixelFormat::ROW_MAJOR
            && $format->bit_depth === BitDepth::B32
            && $format->scan_direction === ScanDirection::TOP_TO_BOTTOM
            && ($format->channel_order === ChannelOrder::RGBA || $format->channel_order === ChannelOrder::BGRA)
            && is_null($format->palette);
    }

    public function info(ImageSource $source): DecodedInfo
    {
        $bytes = $source->bytes();
        $format = ImageFormat::sniff($bytes) ?? throw ImageException::unknownFormat();
        $decoded = $this->open($bytes, $format);
        $info = new BLImageInfo();
        $this->check(bl_image_decoder_read_info($decoded, $info, $bytes, strlen($bytes)), $format, 'its header could not be read.');

        return new DecodedInfo($format, $info->size->w, $info->size->h, $info->depth >= 32, max(1, $info->frame_count));
    }

    public function decodeInto(ImageSource $source, PixelMemory $target, int $frame = 0): Region
    {
        $bytes = $source->bytes();
        $format = ImageFormat::sniff($bytes) ?? throw ImageException::unknownFormat();
        if (! $this->writes($target->format)) {
            throw ImageException::noDecoder($format, $target->format);
        }
        $facts = $this->info($source);
        if ($frame < 0 || $frame >= $facts->frames) {
            throw ImageException::frameOutOfRange($format, $frame, $facts->frames);
        }
        if ($frame !== 0) {
            throw ImageException::corrupt($format, 'Blend2D reads frame 0.');
        }
        if ($facts->width !== $target->width || $facts->height !== $target->height) {
            throw ImageException::corrupt($format, "it is {$facts->width}x{$facts->height}, and the frame is {$target->width}x{$target->height}.");
        }
        if ($target->stride < $target->width * 4) {
            throw ImageException::corrupt($format, "a row is ".($target->width * 4)." bytes, and the stride is {$target->stride}.");
        }

        $decoded = $this->open($bytes, $format);
        $image = new BLImageCore();
        bl_image_init($image);
        $this->check(bl_image_decoder_read_frame($decoded, $image, $bytes, strlen($bytes)), $format, 'its pixels could not be read.');
        $data = new BLImageData();
        $this->check(bl_image_get_data($image, $data), $format, 'its pixels could not be read.');
        $premultiplied = $data->format === BL_FORMAT_PRGB32;
        $converter = $this->converter($target->format->channel_order === ChannelOrder::RGBA, $premultiplied, $format);
        $address = $target->address;
        $sourceAddress = $data->pixel_data;
        $this->check(bl_pixel_converter_convert($converter, $address, $target->stride, $sourceAddress, $data->stride, $target->width, $target->height, null), $format, 'its pixels could not be written.');

        return new Region(0, 0, $target->width, $target->height);
    }

    private function open(string $bytes, ImageFormat $format): BLImageDecoderCore
    {
        extension_loaded('blend2d') || throw new ImageException('ext-blend2d is not loaded in this PHP.');
        $codec = new BLImageCodecCore();
        bl_image_codec_init($codec);
        if (bl_image_codec_find_by_data($codec, $bytes, strlen($bytes), null) !== BL_SUCCESS) {
            throw ImageException::corrupt($format, 'it is not a format Blend2D reads.');
        }
        $decoder = new BLImageDecoderCore();
        bl_image_decoder_init($decoder);
        $this->check(bl_image_codec_create_decoder($codec, $decoder), $format, 'it is not a format Blend2D reads.');

        return $decoder;
    }

    /** Straight RGBA or BGRA out. A PRGB32 source is divided back to straight; an XRGB32 source is copied, alpha 255. */
    private function converter(bool $rgba, bool $premultiplied, ImageFormat $format): BLPixelConverterCore
    {
        $key = ($rgba ? 'rgba' : 'bgra').($premultiplied ? ':pre' : ':plain');
        static $made = [];
        if (isset($made[$key])) {
            return $made[$key];
        }
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
        $source = $layout(false, $premultiplied ? BL_FORMAT_FLAG_PREMULTIPLIED : 0);
        $this->check(bl_pixel_converter_create($converter, $layout($rgba, 0), $source, BL_PIXEL_CONVERTER_CREATE_NO_FLAGS), $format, 'its pixels could not be written.');
        $made[$key] = $converter;

        return $converter;
    }

    private function check(int $result, ImageFormat $format, string $reason): void
    {
        if ($result !== BL_SUCCESS) {
            throw ImageException::corrupt($format, $reason);
        }
    }
}
