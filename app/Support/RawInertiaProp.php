<?php

namespace App\Support;

use JsonSerializable;

/**
 * Bungkus data besar yg sudah siap (array biasa) supaya Inertia v3 tak menelusuri setiap nilainya.
 *
 * PropsResolver::resolveProps() masuk ke SETIAP array bersarang pada respons & buat pembinaan
 * path, tapisan separa dan resolveValue() utk setiap nilai - utk papan Back Office Actions
 * (ribuan nilai, ~400KB) ia menambah ~3s atas masa query. Objek JsonSerializable tak ditelusuri
 * dan dienkod terus oleh JsonResponse - bentuk JSON ke klien sama.
 */
final class RawInertiaProp implements JsonSerializable
{
    /** @param  array<array-key, mixed>  $data */
    public function __construct(private readonly array $data) {}

    /** @return array<array-key, mixed> */
    public function jsonSerialize(): array
    {
        return $this->data;
    }
}
