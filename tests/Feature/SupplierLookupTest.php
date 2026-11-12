<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\RemoteThumbnails;
use App\Support\SupplierLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Halaman Supplier Lookup: server menghantar SEMUA design satu supplier sekali (kod + [Amik, Sold] setiap cawangan, drpd piece yg
 * diambil dlm tempoh); susunan, tapisan cawangan & halaman berlaku di pelayar (logik diuji dlm resources/js/lib/supplierLookup.ts).
 * Jadual cermin ada ~87 lajur wajib - baris dibina secara dinamik drpd skema (rujuk BackOfficeActionsTest).
 */
class SupplierLookupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Agregat di-cache mengikut tempoh - kosongkan supaya setiap ujian bermula bersih.
        Cache::flush();
    }

    /**
     * Isi lajur wajib jadual cermin secara dinamik drpd skema, kemudian masukkan baris.
     *
     * @param  array<string, mixed>  $values
     */
    private function insertMirror(string $table, array $values): void
    {
        $row = [];

        foreach (Schema::getColumns($table) as $column) {
            if ($column['auto_increment'] || $column['nullable'] || $column['default'] !== null) {
                continue;
            }

            $type = strtolower($column['type_name']);
            $row[$column['name']] = match (true) {
                str_contains($type, 'int'), str_contains($type, 'decimal'), str_contains($type, 'float'), str_contains($type, 'double') => 0,
                str_contains($type, 'date'), str_contains($type, 'time') => now()->toDateTimeString(),
                default => 'X',
            };
        }

        DB::table($table)->insert($values + $row);
    }

    /** @param  array<string, mixed>  $overrides */
    private function piece(string $code, array $overrides = []): void
    {
        static $sequence = 0;

        $this->insertMirror('jemisys_inventory_mirror', $overrides + [
            'InternalCode' => $code,
            'InventoryCode' => 'INV'.++$sequence,
            'StoreCode' => 'WM',
            'VendorCode' => 'GRJ',
            'Status' => '1',
            'PurchDate' => now()->subDays(10)->toDateTimeString(),
        ]);
    }

    private function vendor(string $code, string $name): void
    {
        $this->insertMirror('jemisys_vendor_mirror', ['VendorCode' => $code, 'Description' => $name]);
    }

    /** @return array<string, array<string, array{0: int, 1: int}>> cawangan => [Amik, Sold], ikut kod design */
    private function designs(string $supplier = 'GRJ', int $days = 180): array
    {
        return collect(SupplierLookup::forDays($days)->designs($supplier))->mapWithKeys(fn (array $design) => [$design['code'] => $design['stores']])->all();
    }

    public function test_each_design_lists_buy_and_sold_per_branch_from_the_suppliers_pieces_bought_in_the_period(): void
    {
        // WM: 4 piece diambil - 2 Sold ('0'), 1 SoldClosed ('9'), 1 tersedia ('1') => [4, 3]. DAMAI: 1 tersedia => [1, 0].
        $this->piece('DESIGN-A', ['Status' => '0']);
        $this->piece('DESIGN-A', ['Status' => '0']);
        $this->piece('DESIGN-A', ['Status' => '9']);
        $this->piece('DESIGN-A', ['Status' => '1']);
        $this->piece('DESIGN-A', ['StoreCode' => 'DAMAI', 'Status' => '1']);
        $this->piece('DESIGN-B', ['Status' => '5']);

        $designs = $this->designs();

        $this->assertSame(['DESIGN-A', 'DESIGN-B'], array_keys($designs));
        $this->assertSame(['DAMAI' => [1, 0], 'WM' => [4, 3]], $designs['DESIGN-A']);
        $this->assertSame(['WM' => [1, 0]], $designs['DESIGN-B']);
    }

    public function test_only_the_chosen_suppliers_pieces_of_a_shared_design_are_counted(): void
    {
        // DESIGN-A diambil daripada dua supplier - jumlah setiap supplier TIDAK bercampur.
        $this->piece('DESIGN-A', ['VendorCode' => 'GRJ', 'Status' => '0']);
        $this->piece('DESIGN-A', ['VendorCode' => 'GRJ', 'Status' => '1']);
        $this->piece('DESIGN-A', ['VendorCode' => 'MG', 'Status' => '0']);
        $this->piece('DESIGN-A', ['VendorCode' => 'MG', 'Status' => '0']);
        $this->piece('DESIGN-A', ['VendorCode' => 'MG', 'Status' => '0']);
        $this->piece('DESIGN-ONLY-MG', ['VendorCode' => 'MG']);

        $this->assertSame(['DESIGN-A' => ['WM' => [2, 1]]], $this->designs('GRJ'));
        $this->assertSame(['DESIGN-A' => ['WM' => [3, 3]], 'DESIGN-ONLY-MG' => ['WM' => [1, 0]]], $this->designs('MG'));
        $this->assertSame([], $this->designs('NOPE'));
    }

    public function test_pieces_outside_the_period_and_placeholder_vendors_are_excluded(): void
    {
        $this->piece('DESIGN-OLD', ['PurchDate' => now()->subDays(400)->toDateTimeString()]);
        $this->piece('DESIGN-NOVENDOR', ['VendorCode' => '.']);
        $this->piece('DESIGN-OK');

        $this->assertSame(['DESIGN-OK'], array_keys($this->designs()));
        $this->assertSame([], $this->designs('.'));
        // Tempoh lebih panjang memasukkan piece lama.
        $this->assertContains('DESIGN-OLD', array_keys($this->designs(days: 740)));
    }

    public function test_designs_are_sorted_by_code_and_a_padded_code_is_trimmed(): void
    {
        $this->piece('DESIGN-C');
        $this->piece('DESIGN-A');
        $this->piece('DESIGN-B   ');

        $this->assertSame(['DESIGN-A', 'DESIGN-B', 'DESIGN-C'], array_keys($this->designs()));
    }

    public function test_details_return_nickname_image_and_reference_flag_and_mark_unknown_codes_as_loaded(): void
    {
        $this->piece('DESIGN-OWN', ['image_url' => 'https://merchant9.com/products/own.jpg', 'nickname' => 'Rantai Wave']);
        $this->piece('DESIGN-REF', ['image_url' => 'https://merchant9.com/products/ref.jpg', 'image_source' => 'nearest']);
        $this->piece('DESIGN-NONE');

        $details = SupplierLookup::details(['DESIGN-OWN', 'DESIGN-REF', 'DESIGN-NONE', 'UNKNOWN']);

        $this->assertSame(['nickname' => 'Rantai Wave', 'image_url' => 'https://merchant9.com/products/own.jpg', 'image_reference' => false], $details['DESIGN-OWN']);
        $this->assertSame(['nickname' => null, 'image_url' => 'https://merchant9.com/products/ref.jpg', 'image_reference' => true], $details['DESIGN-REF']);
        $this->assertSame(['nickname' => null, 'image_url' => null, 'image_reference' => false], $details['DESIGN-NONE']);
        $this->assertSame(['nickname' => null, 'image_url' => null, 'image_reference' => false], $details['UNKNOWN']);
    }

    public function test_details_ignore_junk_input_and_cap_the_number_of_codes(): void
    {
        $this->assertSame([], SupplierLookup::details([]));
        $this->assertSame([], SupplierLookup::details(['', '   ', 123, ['x']]));
        $this->assertSame(['DESIGN-A'], array_keys(SupplierLookup::details([' DESIGN-A ', 'DESIGN-A'])));

        $many = array_map(fn (int $i) => sprintf('D%03d', $i), range(1, SupplierLookup::MAX_DETAIL_CODES + 20));
        $this->assertCount(SupplierLookup::MAX_DETAIL_CODES, SupplierLookup::details($many));
    }

    public function test_suggestions_match_suppliers_by_code_prefix_or_name(): void
    {
        $this->vendor('GRJ', 'Guan Rong Jewellery');
        $this->vendor('MG', 'Mega Gold');
        $this->vendor('AGJ', 'Agape Gold Jewellery');
        $this->vendor('.', 'Placeholder');

        $this->assertSame([['code' => 'GRJ', 'name' => 'Guan Rong Jewellery']], SupplierLookup::suggestions('grj')['suppliers']);
        $this->assertSame([['code' => 'GRJ', 'name' => 'Guan Rong Jewellery']], SupplierLookup::suggestions('rong')['suppliers']);
        $this->assertSame(['AGJ', 'MG'], array_column(SupplierLookup::suggestions('gold')['suppliers'], 'code'));
        $this->assertSame(['MG'], array_column(SupplierLookup::suggestions('MG')['suppliers'], 'code'));
        $this->assertSame([], SupplierLookup::suggestions('place')['suppliers']);
        $this->assertSame(['suppliers' => []], SupplierLookup::suggestions('   '));
    }

    public function test_suggestions_rank_code_matches_ahead_of_name_matches_and_respect_the_limit(): void
    {
        $this->vendor('ZZ', 'Gold Zone');
        $this->vendor('GOLD', 'Aaa Supplier');

        $this->assertSame(['GOLD', 'ZZ'], array_column(SupplierLookup::suggestions('gold')['suppliers'], 'code'));

        foreach (range(1, SupplierLookup::SUGGESTION_LIMIT + 3) as $i) {
            $this->vendor(sprintf('V%02d', $i), "Vendor {$i}");
        }

        $this->assertCount(SupplierLookup::SUGGESTION_LIMIT, SupplierLookup::suggestions('V')['suppliers']);
    }

    public function test_suggestions_treat_like_wildcards_literally(): void
    {
        $this->vendor('GRJ', 'Guan Rong Jewellery');

        $this->assertSame([], SupplierLookup::suggestions('G%')['suppliers']);
        $this->assertSame([], SupplierLookup::suggestions('_RJ')['suppliers']);
    }

    public function test_unknown_period_values_fall_back_to_the_default_of_90_days(): void
    {
        $this->assertSame(90, SupplierLookup::DEFAULT_DAYS);
        $this->assertSame(90, SupplierLookup::normalizeDays(5));
        $this->assertSame(90, SupplierLookup::normalizeDays(null));
        $this->assertSame(365, SupplierLookup::normalizeDays('365'));
    }

    public function test_the_warm_command_fills_the_cache_for_every_period(): void
    {
        $this->piece('DESIGN-A');

        $command = $this->artisan('supplier-lookup:warm');

        foreach (SupplierLookup::PERIOD_DAYS as $days) {
            $command->expectsOutputToContain("{$days} hari");
        }

        $command->assertSuccessful();
    }

    private function userWithRole(string $role): User
    {
        Role::findOrCreate($role, 'web');

        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_the_page_and_its_endpoints_are_limited_to_manager_ceo_and_super_admin(): void
    {
        $urls = ['/supplier-lookup', '/supplier-lookup/suggestions?q=rt', '/supplier-lookup/details?codes[]=A', '/supplier-lookup/export?supplier=GRJ', '/supplier-lookup/print?supplier=GRJ'];

        // Tetamu dahulu (actingAs kekal sepanjang ujian).
        foreach ($urls as $url) {
            $this->get($url)->assertRedirect();
        }

        foreach ($urls as $url) {
            $this->actingAs($this->userWithRole('leader_branch'))->getJson($url)->assertForbidden();
        }

        foreach (['manager', 'ceo', 'super_admin'] as $role) {
            foreach ($urls as $url) {
                $this->actingAs($this->userWithRole($role))->get($url)->assertOk();
            }
        }
    }

    public function test_without_a_supplier_the_page_shows_no_list_and_computes_nothing(): void
    {
        $this->piece('DESIGN-A');

        $this->actingAs($this->userWithRole('manager'))
            ->get('/supplier-lookup')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('SupplierLookup/Index')
                ->where('supplier', null)
                ->where('filters.days', 90)
                ->loadDeferredProps(fn (AssertableInertia $reload) => $reload
                    ->where('result.searched', false)
                    ->where('result.designs', [])
                )
            );
    }

    public function test_choosing_a_supplier_sends_all_its_designs_once_and_the_supplier_name_for_the_field(): void
    {
        $this->vendor('GRJ', 'Guan Rong Jewellery');
        $this->piece('DESIGN-A', ['VendorCode' => 'GRJ', 'Status' => '0']);
        $this->piece('DESIGN-A', ['VendorCode' => 'GRJ', 'StoreCode' => 'DAMAI', 'Status' => '1']);
        $this->piece('DESIGN-B', ['VendorCode' => 'MG']);

        $this->actingAs($this->userWithRole('manager'))
            ->get('/supplier-lookup?supplier=GRJ')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('supplier', ['code' => 'GRJ', 'name' => 'Guan Rong Jewellery'])
                ->loadDeferredProps(fn (AssertableInertia $reload) => $reload
                    ->where('result.searched', true)
                    ->has('result.designs', 1)
                    ->where('result.designs.0.code', 'DESIGN-A')
                    ->where('result.designs.0.stores', ['DAMAI' => [1, 0], 'WM' => [1, 1]])
                )
            );
    }

    public function test_the_page_normalises_an_unexpected_period(): void
    {
        $this->actingAs($this->userWithRole('manager'))
            ->get('/supplier-lookup?supplier=GRJ&days=999')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filters.supplier', 'GRJ')
                ->where('filters.days', 90)
                ->has('periodOptions', count(SupplierLookup::PERIOD_DAYS))
                ->missing('result')
            );
    }

    public function test_the_suggestions_endpoint_returns_matching_suppliers(): void
    {
        $this->vendor('GRJ', 'Guan Rong Jewellery');

        $this->actingAs($this->userWithRole('manager'))
            ->getJson('/supplier-lookup/suggestions?q=GR')
            ->assertOk()
            ->assertExactJson(['suppliers' => [['code' => 'GRJ', 'name' => 'Guan Rong Jewellery']]]);
    }

    public function test_the_details_endpoint_returns_details_for_the_requested_codes(): void
    {
        $this->piece('DESIGN-A', ['nickname' => 'Rantai Wave', 'image_url' => 'https://merchant9.com/products/a.jpg']);

        $this->actingAs($this->userWithRole('manager'))
            ->getJson('/supplier-lookup/details?codes[]=DESIGN-A&codes[]=DESIGN-X')
            ->assertOk()
            ->assertJsonPath('details.DESIGN-A.nickname', 'Rantai Wave')
            ->assertJsonPath('details.DESIGN-A.image_reference', false)
            ->assertJsonPath('details.DESIGN-X.image_url', null);
    }

    public function test_the_php_roll_up_and_sort_match_the_browser_logic(): void
    {
        // Data & jangkaan sama dgn semakan klien (resources/js/lib/supplierLookup.ts): A [4,1] B [2,2] C [4,0].
        $designs = [
            ['code' => 'A', 'stores' => ['DAMAI' => [2, 0], 'WM' => [2, 1]]],
            ['code' => 'B', 'stores' => ['WM' => [2, 2]]],
            ['code' => 'C', 'stores' => ['NILAI' => [2, 0], 'SECURITY' => [1, 0], 'WM' => [1, 0]]],
        ];

        $all = SupplierLookup::rollUp($designs, []);
        $this->assertSame([['A', null, 4, 1, 3, 25.0], ['B', null, 2, 2, 0, 100.0], ['C', null, 4, 0, 4, 0.0]], array_map(fn (array $r) => [$r['code'], $r['store'], $r['buy'], $r['sold'], $r['available'], $r['percent']], $all));

        $order = fn (string $sort, string $dir) => implode('', array_column(SupplierLookup::sorted($all, $sort, $dir), 'code'));
        $this->assertSame('ABC', $order('code', 'asc'));
        $this->assertSame('CBA', $order('code', 'desc'));
        $this->assertSame('BAC', $order('percent', 'desc'));
        $this->assertSame('CAB', $order('percent', 'asc'));
        $this->assertSame('ACB', $order('buy', 'desc'));
        $this->assertSame('BAC', $order('sold', 'desc'));
        $this->assertSame('CAB', $order('available', 'desc'));
        $this->assertSame('BAC', $order('available', 'asc'));

        $branch = SupplierLookup::rollUp($designs, ['WM', 'DAMAI']);
        $this->assertSame(['A|WM|2|1|50', 'A|DAMAI|2|0|0', 'B|WM|2|2|100', 'C|WM|1|0|0'], array_map(fn (array $r) => implode('|', [$r['code'], $r['store'], $r['buy'], $r['sold'], $r['percent']]), $branch));
        $this->assertSame('ADAMAI,AWM,BWM,CWM', implode(',', array_map(fn (array $r) => $r['code'].$r['store'], SupplierLookup::sorted($branch, 'code', 'asc'))));
    }

    public function test_the_php_column_labels_are_identical_to_the_tables_labels_in_the_browser(): void
    {
        $ts = file_get_contents(base_path('resources/js/lib/supplierLookup.ts'));

        foreach (SupplierLookup::COLUMN_LABELS as $key => $label) {
            $this->assertMatchesRegularExpression("/{$key}: '".preg_quote($label, '/')."'/", $ts, "Label lajur '{$key}' berbeza antara PHP dan TypeScript.");
        }
    }

    public function test_export_rows_cover_every_row_with_details_in_the_requested_order(): void
    {
        $this->piece('DESIGN-A', ['Status' => '0', 'nickname' => 'Rantai Wave', 'image_url' => 'https://merchant9.com/products/a.jpg']);
        $this->piece('DESIGN-A', ['StoreCode' => 'DAMAI']);
        $this->piece('DESIGN-B', ['Status' => '0', 'Status' => '0']);

        $rows = SupplierLookup::forDays(90)->exportRows('GRJ', [], 'percent', 'desc');

        $this->assertSame(['DESIGN-B', 'DESIGN-A'], array_column($rows, 'code'));
        $this->assertSame('Rantai Wave', $rows[1]['nickname']);
        $this->assertSame('https://merchant9.com/products/a.jpg', $rows[1]['image_url']);
        $this->assertNull($rows[0]['image_url']);
        $this->assertCount(3, SupplierLookup::forDays(90)->exportRows('GRJ', ['WM', 'DAMAI'], 'code', 'asc'));
    }

    private string $thumbDirectory = '';

    /** Ganti muat turun gambar sebenar dgn PNG palsu (kecuali URL mengandungi "missing" = gagal), tanpa rangkaian. */
    private function fakeThumbnails(): void
    {
        $this->thumbDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'lookup-thumbs-'.uniqid();

        $this->app->bind(RemoteThumbnails::class, fn () => new RemoteThumbnails($this->thumbDirectory, function (array $urls): array {
            $image = imagecreatetruecolor(120, 90);
            imagefill($image, 0, 0, imagecolorallocate($image, 30, 120, 200));
            ob_start();
            imagepng($image);
            $png = (string) ob_get_clean();

            return collect($urls)->mapWithKeys(fn (string $url) => [$url => str_contains($url, 'missing') ? null : $png])->all();
        }));
    }

    protected function tearDown(): void
    {
        // HANYA bila fakeThumbnails() menetapkan direktori sementara (jika kosong, glob('/*') akan menyasarkan akar cakera).
        if ($this->thumbDirectory !== '' && is_dir($this->thumbDirectory)) {
            foreach (glob($this->thumbDirectory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
                unlink($file);
            }

            rmdir($this->thumbDirectory);
        }

        parent::tearDown();
    }

    private function loadXlsx(TestResponse $response): Worksheet
    {
        return IOFactory::load($response->baseResponse->getFile()->getPathname())->getActiveSheet();
    }

    public function test_the_excel_export_has_the_same_headers_as_the_table_real_embedded_images_and_every_row(): void
    {
        $this->fakeThumbnails();
        $this->vendor('GRJ', 'Guan Rong Jewellery');
        $this->piece('DESIGN-A', ['Status' => '0', 'nickname' => 'Rantai Wave', 'image_url' => 'https://merchant9.com/products/a.jpg']);
        $this->piece('DESIGN-A', ['Status' => '1']);
        $this->piece('DESIGN-B', ['image_url' => 'https://merchant9.com/products/b.jpg', 'image_source' => 'nearest']);
        $this->piece('DESIGN-C');

        $response = $this->actingAs($this->userWithRole('manager'))->get('/supplier-lookup/export?supplier=GRJ&days=90&sort=code&dir=asc');

        $response->assertOk();
        $this->assertStringContainsString('Supplier-Lookup-grj-', (string) $response->headers->get('content-disposition'));
        $sheet = $this->loadXlsx($response);
        $rows = $sheet->toArray(null, true, false, false);

        $this->assertStringContainsString('Guan Rong Jewellery (GRJ)', $rows[0][0]);
        $this->assertStringNotContainsString('gambar dibenamkan', $rows[0][0]);
        $this->assertSame(['Image', 'Design Code', 'Total Ambil/Buy', 'Total Sold', 'Total Available', 'Percentage'], array_slice($rows[1], 0, 6));
        $this->assertEquals([null, "DESIGN-A\nRantai Wave", 2, 1, 1, 50.0], array_slice($rows[2], 0, 6));
        $this->assertEquals([null, 'DESIGN-B', 1, 0, 1, 0.0], array_slice($rows[3], 0, 6));
        $this->assertEquals([null, 'DESIGN-C', 1, 0, 1, 0.0], array_slice($rows[4], 0, 6));
        $this->assertCount(5, array_filter($rows, fn (array $row) => array_filter($row, fn ($cell) => $cell !== null && $cell !== '') !== []));

        // Gambar SEBENAR terbenam (bukan URL): satu pada baris DESIGN-A (A3) & DESIGN-B (A4); DESIGN-C tiada gambar.
        $drawings = $sheet->getDrawingCollection();
        $this->assertCount(2, $drawings);
        $this->assertSame(['A3', 'A4'], collect($drawings)->map(fn ($drawing) => $drawing->getCoordinates())->sort()->values()->all());
        $this->assertStringNotContainsString('merchant9.com', json_encode($rows));
        $this->assertSame('0.0"%"', $sheet->getStyle('F3')->getNumberFormat()->getFormatCode());
    }

    public function test_the_excel_export_in_branch_mode_adds_the_branch_column_and_follows_the_sort(): void
    {
        $this->fakeThumbnails();
        $this->piece('DESIGN-A', ['StoreCode' => 'WM', 'Status' => '0']);
        $this->piece('DESIGN-A', ['StoreCode' => 'DAMAI']);
        $this->piece('DESIGN-B', ['StoreCode' => 'WM']);
        $this->piece('DESIGN-B', ['StoreCode' => 'NILAI']);

        $response = $this->actingAs($this->userWithRole('manager'))->get('/supplier-lookup/export?supplier=GRJ&branches[]=WM&branches[]=DAMAI&sort=percent&dir=desc');
        $rows = $this->loadXlsx($response)->toArray(null, true, false, false);

        $this->assertSame(['Image', 'Design Code', 'Cawangan', 'Total Ambil/Buy', 'Total Sold', 'Total Available', 'Percentage'], array_slice($rows[1], 0, 7));
        $this->assertEquals(['DESIGN-A', 'WM', 1, 1, 0, 100.0], array_slice($rows[2], 1, 6));
        // NILAI tidak dipilih - tiada barisnya (3 baris data: A-WM, A-DAMAI, B-WM).
        $this->assertCount(2 + 3, array_filter($rows, fn (array $row) => array_filter($row, fn ($cell) => $cell !== null && $cell !== '') !== []));
        $this->assertStringContainsString('Cawangan: WM, DAMAI', $rows[0][0]);
    }

    public function test_images_that_could_not_be_downloaded_are_left_blank_and_noted_in_the_heading(): void
    {
        $this->fakeThumbnails();
        $this->piece('DESIGN-A', ['image_url' => 'https://merchant9.com/products/a.jpg']);
        $this->piece('DESIGN-B', ['image_url' => 'https://merchant9.com/products/missing.jpg']);

        $response = $this->actingAs($this->userWithRole('manager'))->get('/supplier-lookup/export?supplier=GRJ');
        $sheet = $this->loadXlsx($response);

        $this->assertCount(1, $sheet->getDrawingCollection());
        $this->assertStringContainsString('gambar dibenamkan: 1 daripada 2', (string) $sheet->getCell('A1')->getValue());
    }

    public function test_the_print_page_shows_the_same_headers_images_and_context(): void
    {
        $this->vendor('GRJ', 'Guan Rong Jewellery');
        $this->piece('DESIGN-A', ['image_url' => 'https://merchant9.com/products/a.jpg', 'nickname' => 'Rantai Wave']);
        $this->piece('DESIGN-B', ['image_url' => 'https://merchant9.com/products/b.jpg', 'image_source' => 'nearest']);

        $response = $this->actingAs($this->userWithRole('manager'))->get('/supplier-lookup/print?supplier=GRJ&days=90&sort=percent&dir=desc');

        $response->assertOk()
            ->assertSeeInOrder(['Image', 'Design Code', 'Total Ambil/Buy', 'Total Sold', 'Total Available', 'Percentage'])
            ->assertDontSee('Cawangan</th>', false)
            ->assertSee('Guan Rong Jewellery (GRJ)')
            ->assertSee('90 hari terakhir')
            ->assertSee('Percentage, menurun')
            ->assertSee('DESIGN-A')
            ->assertSee('Rantai Wave')
            ->assertSee('https://merchant9.com/products/b.jpg', false)
            ->assertSee('Rujukan');

        $this->actingAs($this->userWithRole('manager'))->get('/supplier-lookup/print?supplier=GRJ&branches[]=WM')
            ->assertSee('Cawangan</th>', false)
            ->assertSee('Cawangan: WM');
    }

    public function test_export_and_print_require_a_supplier_and_normalise_unexpected_values(): void
    {
        $manager = $this->userWithRole('manager');

        $this->actingAs($manager)->get('/supplier-lookup/export')->assertStatus(422);
        $this->actingAs($manager)->get('/supplier-lookup/print')->assertStatus(422);

        $this->piece('DESIGN-A');
        $this->actingAs($manager)->get('/supplier-lookup/print?supplier=GRJ&days=999&sort=hack&dir=up')
            ->assertOk()
            ->assertSee('90 hari terakhir')
            ->assertSee('Design Code, menaik');
    }
}
