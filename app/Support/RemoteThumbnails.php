<?php

namespace App\Support;

/**
 * Gambar produk laman web merchant9.com sbg thumbnail JPEG kecil pada cakera, utk dibenamkan dlm eksport (Excel) - bukan URL.
 *
 * Gambar dimuat turun SERENTAK (curl_multi, ~0.6 saat utk 40 gambar berbanding ~4 saat berurutan), disusutkan ke sisi terpanjang
 * MAX_SIDE px (GD, tanpa dibesarkan) & dicache ikut URL - eksport berikutnya (atau yg diulang) tidak memuat turun semula. Ada belanjawan
 * masa: bila habis, gambar yg belum sempat dimuat dilangkau (kosong) & akan dilengkapkan oleh eksport seterusnya kerana yg sudah siap
 * kekal dlm cache.
 *
 * Hanya URL https://merchant9.com/ dimuat turun (URL datang dari DB - elak permintaan ke hos lain).
 */
final class RemoteThumbnails
{
    public const MAX_SIDE = 96;

    private const ALLOWED_PREFIX = 'https://merchant9.com/';

    private const CONCURRENCY = 24;

    private const TIMEOUT_SECONDS = 15;

    private const JPEG_QUALITY = 82;

    /** @var (callable(list<string>): array<string, ?string>)|null */
    private $fetcher;

    /**
     * @param  (callable(list<string>): array<string, ?string>)|null  $fetcher  url => badan respons atau null; lalai = curl_multi (ujian menyuntik pengganti)
     */
    public function __construct(private readonly string $directory, ?callable $fetcher = null)
    {
        $this->fetcher = $fetcher;
    }

    public static function default(): self
    {
        return new self(storage_path('app/supplier-lookup-thumbs'));
    }

    /**
     * @param  list<string>  $urls
     * @return array<string, string> url => laluan fail JPEG (hanya yg berjaya ada)
     */
    public function paths(array $urls, float $budgetSeconds = 90.0): array
    {
        $deadline = microtime(true) + $budgetSeconds;
        $found = [];
        $missing = [];

        foreach (array_values(array_unique(array_filter($urls))) as $url) {
            $path = $this->pathFor($url);

            if (is_file($path)) {
                $found[$url] = $path;
            } elseif (str_starts_with($url, self::ALLOWED_PREFIX)) {
                $missing[] = $url;
            }
        }

        if ($missing === []) {
            return $found;
        }

        if (! is_dir($this->directory)) {
            mkdir($this->directory, 0775, true);
        }

        foreach (array_chunk($missing, self::CONCURRENCY) as $batch) {
            if (microtime(true) >= $deadline) {
                break;
            }

            foreach ($this->fetch($batch) as $url => $body) {
                $jpeg = $body === null ? null : self::thumbnail($body);

                if ($jpeg !== null) {
                    file_put_contents($this->pathFor($url), $jpeg);
                    $found[$url] = $this->pathFor($url);
                }
            }
        }

        return $found;
    }

    public function pathFor(string $url): string
    {
        return $this->directory.DIRECTORY_SEPARATOR.md5($url).'.jpg';
    }

    /** Badan imej mentah -> JPEG disusutkan (latar putih utk lut sinar), atau null jika bukan imej yg sah. */
    public static function thumbnail(string $body): ?string
    {
        $source = @imagecreatefromstring($body);

        if ($source === false) {
            return null;
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1.0, self::MAX_SIDE / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        imagejpeg($canvas, null, self::JPEG_QUALITY);
        $jpeg = (string) ob_get_clean();

        return $jpeg === '' ? null : $jpeg;
    }

    /**
     * @param  list<string>  $urls
     * @return array<string, ?string>
     */
    private function fetch(array $urls): array
    {
        if ($this->fetcher !== null) {
            return ($this->fetcher)($urls);
        }

        $multi = curl_multi_init();
        $handles = [];

        foreach ($urls as $url) {
            $handle = curl_init($url);
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; M9OS-SupplierLookup/1.0)',
            ]);
            curl_multi_add_handle($multi, $handle);
            $handles[$url] = $handle;
        }

        do {
            $status = curl_multi_exec($multi, $active);

            if ($active) {
                curl_multi_select($multi, 1.0);
            }
        } while ($active && $status === CURLM_OK);

        $bodies = [];

        foreach ($handles as $url => $handle) {
            $ok = curl_getinfo($handle, CURLINFO_RESPONSE_CODE) === 200 && str_starts_with((string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE), 'image/');
            $bodies[$url] = $ok ? (string) curl_multi_getcontent($handle) : null;

            curl_multi_remove_handle($multi, $handle);
            curl_close($handle);
        }

        curl_multi_close($multi);

        return $bodies;
    }
}
