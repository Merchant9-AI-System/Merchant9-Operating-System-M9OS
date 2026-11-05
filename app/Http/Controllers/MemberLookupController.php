<?php

namespace App\Http\Controllers;

use App\Support\MemberLookup;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/internal/members/lookup - dipanggil oleh QMS kiosk (apps/desti, bukan staf di sini)
 * utk kenal pasti ahli sedia ada drpd NRIC/telefon yg pelanggan key-in di mesin tiket. Auth:
 * token Sanctum keupayaan 'members:lookup' sahaja (rujuk routes/api.php) - m9os kekal SATU-SATUNYA
 * app dgn akses JEMiSys, desti TIADA sambungan database terus.
 *
 * Senarai putih respons (rujuk MemberLookup::fromRow()) - TIADA NRIC/telefon/e-mel dipulangkan.
 */
class MemberLookupController extends Controller
{
    public function __invoke(Request $request, MemberLookup $lookup): JsonResponse
    {
        $data = $request->validate([
            'ic_number' => ['nullable', 'string', 'required_without:phone'],
            'phone' => ['nullable', 'string', 'required_without:ic_number'],
        ]);

        try {
            $result = $lookup->find($data['ic_number'] ?? null, $data['phone'] ?? null);
        } catch (QueryException $e) {
            report($e);

            return response()->json([
                'message' => 'Pangkalan data tidak bertindak balas atau query melebihi had masa - cuba semula sebentar lagi.',
            ], 503);
        }

        return response()->json($result);
    }
}
