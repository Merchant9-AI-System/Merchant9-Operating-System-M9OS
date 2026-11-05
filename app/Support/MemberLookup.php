<?php

namespace App\Support;

use App\Models\Jemisys\Member;
use Illuminate\Database\Eloquent\Builder;

/**
 * Carian identiti/tier ahli ikut NRIC/telefon - utk QMS kiosk (apps/desti) kenal pasti ahli
 * sedia ada semasa pelanggan key-in butiran di mesin tiket. Baca drpd cermin tempatan
 * `jemisys_member_mirror` (App\Models\Jemisys\Member) - BUKAN live SQL Server 'jemisys' lagi
 * (disahkan 2026-11-05: query per-request terhadap Member 193K baris timeout >65s, box sama
 * dikongsi dgn sync TblInventory malam, RAM-starved/tiada index sesuai). Disegerak harian via
 * App\Jobs\SyncJemisysMirrors::syncMembers().
 *
 * Carian guna lajur *_normalized (dikira SEMASA SYNC, rujuk syncMembers() & dokblok migration) -
 * perbandingan index TEPAT (`= ?`), BUKAN whereRaw(REPLACE(RTRIM(LTRIM(...)))) atas lajur mentah
 * (disahkan EXPLAIN 2026-11-05: ungkapan fungsi tu defeat index MySQL, full table scan ~4s/carian
 * - sama "kelas" isu dgn live SQL Server punca cermin ni dibina). normaliseIc()/normalisePhone()
 * DIKONGSI (public static) dgn syncMembers() - satu sumber kebenaran normalisasi, tulis & baca.
 *
 * PRIVASI: jadual Member sumber ada ~98 lajur (NRIC penuh, alamat, e-mel, kata laluan WeChat,
 * dll) - cermin HANYA simpan lajur senarai putih (rujuk migration) & fromRow() hanya pulangkan
 * itu. TIADA baki mata ganjaran (belum ada sumber disahkan utk itu - rujuk AI_AGENTS_PLAN.md).
 * vip_no DISENGAJAKAN dipulangkan sbg id rujukan dalaman (bukan PII di luar konteks akaun) - QMS
 * simpan sbg customer_jemisys_id, tak dipaparkan. Ahli dibatalkan (CancelledDate bukan null)
 * TIDAK dicermin langsung - dikecualikan di query sync sumber sendiri.
 *
 * Padanan: NRIC tepat diutamakan dahulu; jika tiada atau NRIC tiada diberi, jatuh balik ke
 * telefon (HandPhone/HandPhone2/WhatsApp). NRIC dikongsi oleh >1 ahli dlm data sebenar (disahkan
 * 2026-11-05: 355 kumpulan NRIC berkongsi antara 195K ahli, cth. akaun keluarga/pendaftaran
 * berganda) - ->first() tanpa ORDER BY pulangkan SATU ahli sahaja antara yg berkongsi, sengaja
 * tak cuba selesaikan kekaburan tu (di luar skop, keputusan perniagaan yg belum dibuat).
 */
class MemberLookup
{
    /**
     * @return array<string, mixed>
     */
    public function find(?string $icNumber, ?string $phone): array
    {
        $ic = self::normaliseIc($icNumber);
        $phoneDigits = self::normalisePhone($phone);

        $row = null;

        if ($ic !== null) {
            $row = $this->baseQuery()
                ->where('nric_normalized', $ic)
                ->first();
        }

        if ($row === null && $phoneDigits !== null) {
            $row = $this->baseQuery()
                ->where(function (Builder $query) use ($phoneDigits) {
                    $query->where('hand_phone_normalized', $phoneDigits)
                        ->orWhere('hand_phone2_normalized', $phoneDigits)
                        ->orWhere('whatsapp_normalized', $phoneDigits);
                })
                ->first();
        }

        return self::fromRow($row);
    }

    private function baseQuery(): Builder
    {
        return Member::query()
            ->selectRaw('VIPNo AS vip_no')
            ->selectRaw('Name AS name')
            ->selectRaw('MemberType AS member_type')
            ->selectRaw('StoreCode AS store_code')
            ->selectRaw('MemberSince AS member_since')
            ->selectRaw('DiscountPer AS discount_percent');
    }

    public static function normaliseIc(?string $value): ?string
    {
        $value = $value !== null ? preg_replace('/[-\s]/', '', $value) : null;

        return filled($value) ? strtoupper($value) : null;
    }

    public static function normalisePhone(?string $value): ?string
    {
        $value = $value !== null ? preg_replace('/\D/', '', $value) : null;

        return filled($value) ? $value : null;
    }

    /**
     * Bentuk respons senarai putih drpd satu baris Member (atau null = tiada padanan) - boleh
     * diuji terus tanpa database, sama corak dgn MemberAnalytics::fromRows().
     *
     * @return array<string, mixed>
     */
    public static function fromRow(?object $row): array
    {
        if ($row === null) {
            return ['found' => false];
        }

        return [
            'found' => true,
            'vip_no' => (string) $row->vip_no,
            'name' => (string) $row->name,
            'member_type' => (string) $row->member_type,
            'store_code' => (string) $row->store_code,
            'member_since' => $row->member_since !== null ? (string) $row->member_since : null,
            'discount_percent' => $row->discount_percent !== null ? (float) $row->discount_percent : null,
        ];
    }
}
