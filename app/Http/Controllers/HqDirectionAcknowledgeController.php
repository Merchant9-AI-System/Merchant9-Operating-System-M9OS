<?php

namespace App\Http\Controllers;

use App\Models\HqDirection;
use App\Support\HomeSummary\BranchSection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * POST /hq-directions/{direction}/acknowledge - ketua cawangan menanda arahan HQ sebagai "Faham" bagi CAWANGAN
 * SENDIRI (daripada store_code pengguna). Hanya arahan yang berkuat kuasa hari ini DAN menyasarkan cawangan itu boleh
 * diakui (selainnya 404 - tidak mendedahkan kewujudan arahan cawangan lain). Idempotent.
 */
class HqDirectionAcknowledgeController extends Controller
{
    public function __invoke(Request $request, HqDirection $direction): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user->hasRole(BranchSection::ROLE), 403);

        $store = BranchSection::storeOf($user);

        abort_if($store === '', 403);

        $targetsStore = HqDirection::query()->whereKey($direction->getKey())->forStore($store)->exists();

        abort_unless($targetsStore && $direction->isActiveOn(HqDirection::today()), 404);

        $direction->acknowledge($store, $user);

        return back()->with('success', 'Arahan ditanda difahami.');
    }
}
