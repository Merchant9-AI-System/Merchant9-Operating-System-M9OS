<?php

namespace App\Http\Controllers;

use App\Support\HomeSummary\HomeLayout;
use App\Support\HomeSummary\TransferEvidence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /home/design-images?codes[]=... - imej design (daripada cermin JEMiSys) utk panel /home, dimuatkan ikut keperluan
 * semasa baris muncul dlm paparan (lazy). Ketua cawangan sahaja; maksimum MAX_CODES kod setiap permintaan.
 * Kod tanpa imej tidak disertakan dlm hasil.
 */
class DesignImagesController extends Controller
{
    public const MAX_CODES = 40;

    public function __invoke(Request $request, TransferEvidence $evidence): JsonResponse
    {
        // Modul mesti dipasang utk peranan pengguna (susun atur /home - HomeLayout), bukan sekadar nama peranan.
        abort_unless(HomeLayout::has($request->user(), 'rearrange'), 403);

        $codes = $request->query('codes');

        // Pengesahan manual -> 422 JSON bersih (ValidationException pada laluan web memerlukan sesi/borang).
        if (! is_array($codes) || $codes === [] || count($codes) > self::MAX_CODES || collect($codes)->contains(fn ($c) => ! is_string($c) || $c === '' || strlen($c) > 60)) {
            return response()->json(['message' => 'codes mesti senarai 1-'.self::MAX_CODES.' kod.'], 422);
        }

        return response()->json(['images' => (object) $evidence->images(array_values(array_unique($codes)))]);
    }
}
