<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Vision;

use App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions\RasterPreprocessingException;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Preprocessing\RasterAnimationInspector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RasterAnimationInspectorTest extends TestCase
{
    #[Test]
    public function valid_apng_actl_chunk_is_detected_structurally(): void
    {
        $png = $this->png();
        $chunk = $this->pngChunk('acTL', pack('NN', 2, 0));
        $animated = substr($png, 0, 33).$chunk.substr($png, 33);

        $this->expectReason('animated_image', fn () => (new RasterAnimationInspector)->assertSingleFrame($animated, 'image/png'));
    }

    #[Test]
    public function png_data_appended_after_iend_is_malformed_not_animation(): void
    {
        $this->expectReason('invalid_image_container', fn () => (new RasterAnimationInspector)->assertSingleFrame($this->png().'acTL', 'image/png'));
    }

    #[Test]
    public function animated_webp_flags_and_anim_chunk_are_detected_structurally(): void
    {
        $vp8x = 'VP8X'.pack('V', 10)."\x02".str_repeat("\0", 9);
        $bytes = 'RIFF'.pack('V', 4 + strlen($vp8x)).'WEBP'.$vp8x;
        $this->expectReason('animated_image', fn () => (new RasterAnimationInspector)->assertSingleFrame($bytes, 'image/webp'));

        $anim = 'ANIM'.pack('V', 6).str_repeat("\0", 6);
        $bytes = 'RIFF'.pack('V', 4 + strlen($anim)).'WEBP'.$anim;
        $this->expectReason('animated_image', fn () => (new RasterAnimationInspector)->assertSingleFrame($bytes, 'image/webp'));
    }

    #[Test]
    public function second_valid_gif_image_descriptor_is_detected_as_animation(): void
    {
        $gif = $this->gif();
        $position = strpos($gif, ',');
        self::assertIsInt($position);
        $descriptor = substr($gif, $position, strlen($gif) - $position - 1);
        $animated = substr($gif, 0, -1).$descriptor.';';

        $this->expectReason('animated_image', fn () => (new RasterAnimationInspector)->assertSingleFrame($animated, 'image/gif'));
    }

    private function expectReason(string $reason, callable $callback): void
    {
        try {
            $callback();
            self::fail('Container was accepted.');
        } catch (RasterPreprocessingException $exception) {
            self::assertSame($reason, $exception->reason);
        }
    }

    private function png(): string
    {
        $image = imagecreatetruecolor(2, 2);
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();

        return is_string($bytes) ? $bytes : '';
    }

    private function gif(): string
    {
        $image = imagecreatetruecolor(2, 2);
        ob_start();
        imagegif($image);
        $bytes = ob_get_clean();

        return is_string($bytes) ? $bytes : '';
    }

    private function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', (int) sprintf('%u', crc32($type.$data)));
    }
}
