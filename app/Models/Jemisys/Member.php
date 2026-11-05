<?php

namespace App\Models\Jemisys;

use Illuminate\Database\Eloquent\Model;

/**
 * Baca drpd cermin tempatan `jemisys_member_mirror` (DB lalai) - BUKAN terus live 'jemisys' SQL
 * Server. Query per-request terhadap Member (193K baris) pd SQL Server sebenar disahkan timeout
 * >65s (2026-11-05) - box sama dikongsi dgn sync TblInventory malam, RAM-starved/tiada index
 * sesuai utk carian NRIC/telefon. Disegerak harian via App\Jobs\SyncJemisysMirrors::syncMembers().
 *
 * CancelledDate TIDAK dicermin - dikecualikan di query SUMBER sendiri (WHERE CancelledDate IS
 * NULL semasa sync), ahli dibatalkan memang tiada langsung dlm cermin ni.
 *
 * Rujuk App\Support\MemberLookup utk carian ikut NRIC/telefon (QMS kiosk, rujuk
 * routes/api.php POST /api/internal/members/lookup).
 */
class Member extends Model
{
    protected $table = 'jemisys_member_mirror';

    protected $primaryKey = 'VIPNo';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;
}
