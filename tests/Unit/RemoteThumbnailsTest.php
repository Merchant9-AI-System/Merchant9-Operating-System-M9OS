<?php

namespace Tests\Unit;

use App\Support\RemoteThumbnails;
use PHPUnit\Framework\TestCase;

/**
 * Muat turun & cache thumbnail gambar utk eksport. Rangkaian TIDAK digunakan - pengambil gambar disuntik.
 */
class RemoteThumbnailsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'thumbs-'.uniqid();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }

        parent::tearDown();
    }

    private function png(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 30, 30));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private const A = 'https://merchant9.com/products/a.jpg';

    private const B = 'https://merchant9.com/gallery/products/2026/1/b.jpg';

    public function test_images_are_downloaded_shrunk_to_a_small_jpeg_and_cached(): void
    {
        $calls = [];
        $thumbs = new RemoteThumbnails($this->directory, function (array $urls) use (&$calls): array {
            $calls[] = $urls;

            return array_fill_keys($urls, $this->png(400, 200));
        });

        $paths = $thumbs->paths([self::A, self::B, self::A]);

        $this->assertSame([self::A, self::B], array_keys($paths));

        [$width, $height, $type] = getimagesize($paths[self::A]);
        $this->assertSame(IMAGETYPE_JPEG, $type);
        $this->assertSame([RemoteThumbnails::MAX_SIDE, RemoteThumbnails::MAX_SIDE / 2], [$width, $height]);

        // Panggilan kedua: semua dari cache, tiada muat turun.
        $this->assertSame($paths, $thumbs->paths([self::A, self::B]));
        $this->assertCount(1, $calls);
    }

    public function test_small_images_are_never_enlarged(): void
    {
        $thumbs = new RemoteThumbnails($this->directory, fn (array $urls): array => array_fill_keys($urls, $this->png(40, 30)));

        [$width, $height] = getimagesize($thumbs->paths([self::A])[self::A]);

        $this->assertSame([40, 30], [$width, $height]);
    }

    public function test_failed_downloads_and_invalid_bodies_are_skipped_and_retried_next_time(): void
    {
        $attempt = 0;
        $thumbs = new RemoteThumbnails($this->directory, function (array $urls) use (&$attempt): array {
            $attempt++;

            return [self::A => $attempt === 1 ? null : $this->png(50, 50), self::B => 'bukan imej'];
        });

        $this->assertSame([], $thumbs->paths([self::A, self::B]));
        $this->assertSame([self::A], array_keys($thumbs->paths([self::A, self::B])));
    }

    public function test_only_merchant9_https_urls_are_ever_requested(): void
    {
        $requested = [];
        $thumbs = new RemoteThumbnails($this->directory, function (array $urls) use (&$requested): array {
            $requested = [...$requested, ...$urls];

            return array_fill_keys($urls, $this->png(10, 10));
        });

        $paths = $thumbs->paths(['http://merchant9.com/x.jpg', 'https://evil.example/x.jpg', 'https://merchant9.com.evil.example/x.jpg', '', self::A]);

        $this->assertSame([self::A], $requested);
        $this->assertSame([self::A], array_keys($paths));
    }

    public function test_an_exhausted_time_budget_skips_the_remaining_downloads_but_keeps_cached_images(): void
    {
        $thumbs = new RemoteThumbnails($this->directory, fn (array $urls): array => array_fill_keys($urls, $this->png(10, 10)));
        $thumbs->paths([self::A]);

        $none = new RemoteThumbnails($this->directory, fn (array $urls): array => $this->fail('Tidak patut memuat turun apabila belanjawan habis.'));

        $this->assertSame([self::A], array_keys($none->paths([self::A, self::B], 0.0)));
    }

    public function test_transparent_images_are_flattened_on_white(): void
    {
        $image = imagecreatetruecolor(20, 20);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();

        $jpeg = RemoteThumbnails::thumbnail($png);
        $decoded = imagecreatefromstring((string) $jpeg);
        $pixel = imagecolorsforindex($decoded, imagecolorat($decoded, 10, 10));

        $this->assertGreaterThan(240, $pixel['red']);
        $this->assertGreaterThan(240, $pixel['green']);
        $this->assertGreaterThan(240, $pixel['blue']);
        $this->assertNull(RemoteThumbnails::thumbnail('rosak'));
    }
}
