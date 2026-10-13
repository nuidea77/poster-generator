<?php

namespace Tests\Feature;

use App\Services\Media\LogoCutout;
use Tests\TestCase;

class LogoCutoutTest extends TestCase
{
    private function png($img): string
    {
        ob_start();
        imagepng($img);

        return ob_get_clean();
    }

    public function test_outer_white_is_removed_but_white_inside_the_logo_stays(): void
    {
        // White square, blue ring, white centre (like a round badge logo on a JPG).
        $img = imagecreatetruecolor(200, 200);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        imagefilledellipse($img, 100, 100, 160, 160, imagecolorallocate($img, 30, 20, 140));
        imagefilledellipse($img, 100, 100, 80, 80, imagecolorallocate($img, 255, 255, 255));

        $out = imagecreatefromstring(app(LogoCutout::class)->cut($this->png($img)));
        $alpha = fn (int $x, int $y) => (imagecolorat($out, $x, $y) >> 24) & 0x7F;

        $this->assertSame(127, $alpha(2, 2));      // outer corner: transparent
        $this->assertSame(127, $alpha(100, 8));    // outside the ring: transparent
        $this->assertSame(0, $alpha(100, 40));     // the blue ring: opaque
        $this->assertSame(0, $alpha(100, 100));    // the white centre: kept opaque
        $this->assertSame(0xFFFFFF, imagecolorat($out, 100, 100) & 0xFFFFFF);
    }

    public function test_transparent_photo_and_solid_logos_are_left_alone(): void
    {
        $cut = app(LogoCutout::class);

        $clear = imagecreatetruecolor(50, 50);
        imagesavealpha($clear, true);
        imagealphablending($clear, false);
        imagefill($clear, 0, 0, imagecolorallocatealpha($clear, 0, 0, 0, 127));
        $this->assertNull($cut->cut($this->png($clear)));

        $photo = imagecreatetruecolor(50, 50);
        for ($x = 0; $x < 50; $x++) {
            for ($y = 0; $y < 50; $y++) {
                imagesetpixel($photo, $x, $y, imagecolorallocate($photo, ($x * 37) % 256, ($y * 59) % 256, ($x * $y) % 256));
            }
        }
        $this->assertNull($cut->cut($this->png($photo)));

        $solid = imagecreatetruecolor(50, 50);
        imagefill($solid, 0, 0, imagecolorallocate($solid, 220, 0, 0));
        $this->assertNull($cut->cut($this->png($solid)));
    }
}
