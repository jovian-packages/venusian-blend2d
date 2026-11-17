<?php

declare(strict_types=1);

require_once __DIR__.'/../../src/Images/Blend2DImageDecoder.php';

use Jovian\Rendering\Blend2D\Images\Blend2DImageDecoder;
use Surface\Contracts\Images\ImageException;
use Surface\Contracts\Images\ImageFormat;
use Surface\Contracts\Images\ImageSource;
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Framebuffers\PixelMemory;

/*
 * Blend2D decodes a PNG to PRGB32, which is premultiplied BGRA, and a JPEG to opaque XRGB32.
 * The decoder divides the premultiplication back out. An opaque pixel comes back as the file
 * stored it. A partial pixel can be 1 off, because Blend2D rounded when it premultiplied:
 * 120 × 126 ÷ 255 is 59, and 59 × 255 ÷ 126 is 119, so red 120 with alpha 126 comes back 119.
 */

function blendPng(): string
{
    $image = imagecreatetruecolor(4, 1);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    $alphas = [0, 1, 64, 127];
    for ($x = 0; $x < 4; $x++) {
        imagesetpixel($image, $x, 0, ($alphas[$x] << 24) | ($x * 60 << 16) | 7);
    }
    ob_start();
    imagepng($image);

    return ob_get_clean();
}

function blendMemory(int $width, int $height): array
{
    $buffer = new FbBuffer(new FbFormat(FB_LAYOUT_RGBA8888, channelOrder: FB_CHANNELS_RGBA), $width, $height);
    $memory = new PixelMemory($buffer->pointer(), $width, $height, $width * 4, FormatSpec::rgba8(), $buffer);

    return [$buffer, $memory];
}

it('writes a PNG\'s straight colours, opaque exact and a partial pixel within the premultiply rounding', function () {
    extension_loaded('blend2d') || throw new RuntimeException('ext-blend2d is not loaded in this PHP.');
    $png = blendPng();
    $decoder = new Blend2DImageDecoder();
    $info = $decoder->info(ImageSource::containing($png));
    [$buffer, $memory] = blendMemory(4, 1);
    $decoder->decodeInto(ImageSource::containing($png), $memory);

    expect($info->format)->toBe(ImageFormat::PNG)
        ->and($info->hasAlpha)->toBeTrue()
        ->and($buffer->get(0, 0))->toBe(0x000007FF)
        ->and($buffer->get(1, 0))->toBe(0x3C0007FD)
        ->and($buffer->get(2, 0))->toBe(0x7700067E)
        ->and($buffer->get(3, 0))->toBe(0x00000000);
});

it('refuses a target of the wrong size before writing it', function () {
    extension_loaded('blend2d') || throw new RuntimeException('ext-blend2d is not loaded in this PHP.');
    [$buffer, $memory] = blendMemory(2, 1);
    $buffer->rect(0, 0, 2, 1, 0xA5);

    expect(fn () => (new Blend2DImageDecoder())->decodeInto(ImageSource::containing(blendPng()), $memory))
        ->toThrow(ImageException::class, 'it is 4x1, and the frame is 2x1.');
    expect($buffer->get(0, 0))->toBe(0xA5);
});

function blendOwned(int $width, int $height, int $stride, FormatSpec $format): array
{
    $owner = new FbBuffer(new FbFormat(FB_LAYOUT_INDEX8), $stride, $height);
    $owner->rect(0, 0, $stride, $height, 0xA5);
    $memory = new PixelMemory($owner->pointer(), $width, $height, $stride, $format, $owner);

    return [$owner, $memory];
}

function blendTallPng(): string
{
    $image = imagecreatetruecolor(2, 2);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagesetpixel($image, 0, 0, 0x010203);
    imagesetpixel($image, 1, 0, 0x040506);
    imagesetpixel($image, 0, 1, 0x102030);
    imagesetpixel($image, 1, 1, 0x405060);
    ob_start();
    imagepng($image);

    return ob_get_clean();
}

it('writes a BGRA frame in B, G, R, A byte order', function () {
    extension_loaded('blend2d') || throw new RuntimeException('ext-blend2d is not loaded in this PHP.');
    [$owner, $memory] = blendOwned(2, 2, 8, FormatSpec::bgra8());
    (new Blend2DImageDecoder())->decodeInto(ImageSource::containing(blendTallPng()), $memory);

    // Row 1 pixel 0 is red 0x10, green 0x20, blue 0x30: stored 30 20 10 FF.
    expect([$owner->get(0, 1), $owner->get(1, 1), $owner->get(2, 1), $owner->get(3, 1)])->toBe([0x30, 0x20, 0x10, 0xFF]);
});

it('puts each row at its stride and leaves the padding between rows untouched', function () {
    extension_loaded('blend2d') || throw new RuntimeException('ext-blend2d is not loaded in this PHP.');
    // 2 pixels a row is 8 bytes; the stride is 16, so bytes 8..15 of each row are padding.
    [$owner, $memory] = blendOwned(2, 2, 16, FormatSpec::rgba8());
    (new Blend2DImageDecoder())->decodeInto(ImageSource::containing(blendTallPng()), $memory);

    expect([$owner->get(0, 1), $owner->get(1, 1), $owner->get(2, 1), $owner->get(3, 1)])->toBe([0x10, 0x20, 0x30, 0xFF]);
    for ($row = 0; $row < 2; $row++) {
        for ($byte = 8; $byte < 16; $byte++) {
            expect($owner->get($byte, $row))->toBe(0xA5);
        }
    }
});

it('refuses a stride shorter than a row before writing', function () {
    extension_loaded('blend2d') || throw new RuntimeException('ext-blend2d is not loaded in this PHP.');
    [$owner, $memory] = blendOwned(2, 2, 4, FormatSpec::rgba8());

    expect(fn () => (new Blend2DImageDecoder())->decodeInto(ImageSource::containing(blendTallPng()), $memory))
        ->toThrow(ImageException::class, 'a row is 8 bytes, and the stride is 4.');
    expect($owner->get(0, 0))->toBe(0xA5);
});

it('writes a flat JPEG as opaque pixels and reports its header', function () {
    extension_loaded('blend2d') || throw new RuntimeException('ext-blend2d is not loaded in this PHP.');
    // One 8x8 block of one colour: a baseline JPEG at quality 100 keeps a flat block exact.
    $image = imagecreatetruecolor(8, 8);
    imagefilledrectangle($image, 0, 0, 7, 7, 0x405060);
    ob_start();
    imagejpeg($image, null, 100);
    $jpeg = ob_get_clean();
    $decoder = new Blend2DImageDecoder();
    $info = $decoder->info(ImageSource::containing($jpeg));
    [$buffer, $memory] = blendMemory(8, 8);
    $decoder->decodeInto(ImageSource::containing($jpeg), $memory);

    expect([$info->format, $info->width, $info->height, $info->hasAlpha, $info->frames])->toBe([ImageFormat::JPEG, 8, 8, false, 1])
        ->and($buffer->get(0, 0))->toBe(0x405060FF)
        ->and($buffer->get(7, 7))->toBe(0x405060FF);
});

it('refuses a PNG whose pixels Blend2D cannot read, with its reason', function () {
    extension_loaded('blend2d') || throw new RuntimeException('ext-blend2d is not loaded in this PHP.');
    // The header is whole; the image data stops 20 bytes in, before any row is complete.
    $png = blendPng();
    $cut = substr($png, 0, strpos($png, 'IDAT') + 20);
    [$buffer, $memory] = blendMemory(4, 1);

    expect(fn () => (new Blend2DImageDecoder())->decodeInto(ImageSource::containing($cut), $memory))
        ->toThrow(ImageException::class, 'its pixels could not be read.');
});
