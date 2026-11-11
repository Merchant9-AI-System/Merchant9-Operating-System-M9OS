<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Throwable;

/**
 * Menentukan sama ada modal "domain telah bertukar" patut dipaparkan (config/domain_notice.php).
 * Dipaparkan HANYA jika: dihidupkan, URL domain baharu sah (http/https), tarikh akhir (jika ada) belum lepas,
 * dan hos permintaan semasa BUKAN domain baharu.
 */
class DomainNotice
{
    public static function shouldShow(Request $request): bool
    {
        return self::newHost() !== null
            && ! self::expired()
            && strtolower($request->getHost()) !== self::newHost();
    }

    /** URL domain baharu dgn laluan halaman semasa (supaya "Pergi" terus ke halaman yg sama). */
    public static function targetUrl(Request $request): string
    {
        return rtrim((string) config('domain_notice.new_url'), '/').$request->getRequestUri();
    }

    /** Hos domain baharu (huruf kecil) atau null jika dimatikan / URL tak sah. */
    public static function newHost(): ?string
    {
        if (! config('domain_notice.enabled')) {
            return null;
        }

        $url = trim((string) config('domain_notice.new_url'));
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = parse_url($url, PHP_URL_HOST);

        if (! in_array($scheme, ['http', 'https'], true) || ! is_string($host) || $host === '') {
            return null;
        }

        return strtolower($host);
    }

    private static function expired(): bool
    {
        $until = config('domain_notice.until');

        if (blank($until)) {
            return false;
        }

        try {
            return now()->greaterThan(Carbon::parse($until)->endOfDay());
        } catch (Throwable) {
            // Tarikh tak sah -> jangan sembunyikan notis secara senyap kerana salah taip.
            return false;
        }
    }
}
