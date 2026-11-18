<?php

namespace App\Http\Controllers;

use App\Models\ChecklistCompletion;
use App\Models\ChecklistItem;
use App\Support\HomeSummary\BranchSection;
use App\Support\HomeSummary\HomeLayout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * POST /checklist/{item} (body: done = true|false) - ketua cawangan menanda/menyahtanda item Daily Checklist HARI INI
 * (hari Malaysia) bagi CAWANGAN SENDIRI. Hanya item aktif yg terpakai utk cawangan itu (selainnya 404). Idempotent:
 * `done` eksplisit (bukan "flip") supaya klik berganda tidak membalikkan keadaan.
 */
class ChecklistToggleController extends Controller
{
    public function __invoke(Request $request, ChecklistItem $item): RedirectResponse
    {
        $user = $request->user();

        // Modul mesti dipasang utk peranan pengguna (susun atur /home - HomeLayout), bukan sekadar nama peranan.
        abort_unless(HomeLayout::has($user, 'checklist'), 403);

        $store = BranchSection::storeOf($user);

        abort_if($store === '', 403);
        abort_unless($item->is_active && $item->appliesToStore($store), 404);

        $done = $request->validate(['done' => ['required', 'boolean']])['done'];
        $now = Carbon::now('Asia/Kuala_Lumpur');
        $key = ['checklist_item_id' => $item->id, 'store_code' => $store, 'date' => $now->toDateString()];

        if ($done) {
            ChecklistCompletion::query()->firstOrCreate($key, [
                'completed_by_id' => $user->id,
                'completed_by_name' => $user->name,
                'completed_at' => $now,
            ]);
        } else {
            ChecklistCompletion::query()->where($key)->delete();
        }

        return back();
    }
}
