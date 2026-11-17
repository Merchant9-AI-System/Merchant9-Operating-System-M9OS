<?php

namespace App\Http\Controllers;

use App\Models\BranchMonthlyTarget;
use App\Support\HomeSummary\BranchSection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * POST /branch-targets - ketua cawangan menetapkan sasaran jualan BULAN SEMASA (RM & gram) bagi CAWANGAN SENDIRI.
 * Cawangan datang drpd store_code pengguna dan bulan ditentukan server (input tak diterima) - tiada cara menulis utk
 * cawangan/bulan lain. Kalau sasaran sudah wujud (ditetapkan sesiapa, termasuk HQ) ia boleh dikemas kini tetapi
 * CATATAN WAJIB; setiap perubahan direkod dlm sejarah semakan (BranchMonthlyTarget::record()).
 */
class BranchTargetController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user->hasRole(BranchSection::ROLE), 403);

        $store = BranchSection::storeOf($user);

        abort_if($store === '', 403);

        $month = BranchMonthlyTarget::currentMonth();
        $existing = BranchMonthlyTarget::findFor($store, $month);

        $data = $request->validate([
            'target_rm' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'target_grams' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'remark' => [Rule::requiredIf($existing !== null), 'nullable', 'string', 'min:3', 'max:500'],
        ], [
            'remark.required' => 'Catatan wajib diisi apabila mengemas kini sasaran yang sudah ada.',
            'remark.min' => 'Catatan terlalu pendek.',
        ]);

        if ((float) $data['target_rm'] <= 0 && (float) $data['target_grams'] <= 0) {
            throw ValidationException::withMessages(['target_rm' => 'Isi sekurang-kurangnya satu sasaran (RM atau gram).']);
        }

        BranchMonthlyTarget::record($store, $month, (float) $data['target_rm'], (float) $data['target_grams'], $data['remark'] ?? null, $user);

        return back()->with('success', $existing !== null ? 'Sasaran dikemas kini.' : 'Sasaran ditetapkan.');
    }
}
