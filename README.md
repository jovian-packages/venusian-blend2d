# jovian/venusian-blend2d

[Blend2D](https://blend2d.com) for [Venusian Topside](https://github.com/VenusianPHP/topside), through [ext-blend2d](https://github.com/php-io-extensions/blend2d): a framebuffer, a rasterizer, a painter, a stroker and an image decoder, each Blend2D's own.

```bash
composer require jovian/venusian-blend2d
```

The service provider registers `blend2d` on Velvet's framebuffers, rasterizers and painters, on Topside's strokers, and on Images' decoders. Pick it like any other driver, or let `auto` try it. Without ext-blend2d loaded, the drivers refuse to construct and the decoder is not registered.

## Framebuffer

One Blend2D image a frame, in Blend2D's own layout: PRGB32, which lies in memory as B, G, R, A bytes. It takes no other format. Writes go through a Blend2D context; RGBA crosses through Blend2D's pixel converter.

## Rasterization

Paths and curved shapes are Blend2D geometry, filled by Blend2D. Blend2D has one quality, anti-aliased, so a request for hard edges is drawn anti-aliased too. Shadows are handed back to Velvet undrawn.

## Painting

Velvet's painter with Blend2D's brush. On B, G, R, A memory, `path`, `gradpath`, `polyline`, `ellipse`, `ring`, `roundrect` and `roundring` are drawn by Blend2D in place, gradients with Blend2D's own. Hard edges, clears, shadows, glass, layers, images, glyphs, spans and every other layout are Velvet's painter's.

## Stroking

`bl_path_add_stroked_path()` makes the outline; round joins and caps are flattened within 0.1 px. Blend2D 0.21's stroker has no dashes, so a dashed stroke comes back undrawn.

## Images

`blend2d` on Images reads PNG and JPEG with Blend2D's codecs, and writes RGBA or BGRA frames:

- **Header:** `bl_image_decoder_read_info()`, without the pixels.
- **Pixels:** `bl_image_decoder_read_frame()` decodes into a Blend2D image, and `bl_pixel_converter_convert()` writes it into the frame's memory, address to address, at the frame's row stride. A PNG decodes premultiplied and the converter divides it back, so a partial pixel is Blend2D's rounding: red 120 at alpha 126 comes back 119. A JPEG is opaque.
- **Refusals,** before a byte is written: a frame of another size, a stride shorter than a row, a format Blend2D does not read. A file whose pixels Blend2D cannot read is refused with that reason.
- BMP and QOI are Blend2D codecs that Images does not name, so they are not claimed. TIFF is not read.

## Requirements

- PHP 8.4+, [ext-blend2d](https://github.com/php-io-extensions/blend2d) 0.11.
- venusian/topside 0.11.

## License

MIT.
