<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\HasApiDocsStatus;
use App\Filament\Pages\Concerns\HasRestockToolsList;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Laravel\Mcp\Client;
use Throwable;

/**
 * Menu "API Docs" > Available Tools - senarai tool BENAR2 wujud, dibaca terus drpd
 * RestockServer::$tools (rujuk Tool::toArray() - laman ni auto ikut kalau tool baharu
 * ditambah/nama/description/schema diubah, bukan senarai statik yg boleh terlapuk).
 *
 * Turut sediakan "Try it out" sebenar - guna Laravel\Mcp\Client (client RASMI package
 * laravel/mcp, BUKAN Laravel\Mcp\Facades\Mcp yg dipakai utk daftar SERVER di routes/ai.php,
 * dua kelas berbeza walaupun sama-sama ada kaedah web()) utk panggil tools/call SEBENAR ke
 * /mcp/restock guna token bearer pengguna sendiri - sama protokol/laluan spt client luar.
 */
class ApiDocsTools extends Page
{
    use HasApiDocsStatus, HasPageShield, HasRestockToolsList;

    protected string $view = 'filament.pages.api-docs-tools';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCommandLine;

    protected static ?string $navigationLabel = 'Available Tools';

    protected static ?string $title = 'API Docs - Available Tools';

    protected static string|\UnitEnum|null $navigationGroup = 'API Docs';

    protected static ?int $navigationSort = 2;

    public ?string $bearerToken = null;

    public ?string $expandedTool = null;

    /** @var array<string, array<string, mixed>> */
    public array $toolInputs = [];

    /** @var array<string, array{isError: bool, structuredContent: ?array<string, mixed>, text: ?string}> */
    public array $toolResponses = [];

    public function getSubheading(): ?string
    {
        return 'Senarai tool MCP yg tersedia sekarang, dibaca terus drpd definisi server (sentiasa terkini).';
    }

    public function toggleTool(string $name): void
    {
        if ($this->expandedTool === $name) {
            $this->expandedTool = null;

            return;
        }

        $this->expandedTool = $name;

        if (isset($this->toolInputs[$name])) {
            return;
        }

        // Pra-isi default drpd schema (cth. only_actionable => true) - padan tingkah laku tool
        // sebenar bila field tsb ditinggalkan kosong.
        $tool = collect($this->getRestockTools())->firstWhere('name', $name);
        $properties = (array) ($tool['inputSchema']['properties'] ?? []);

        $this->toolInputs[$name] = collect($properties)
            ->mapWithKeys(fn (array $property, string $field) => [$field => $property['default'] ?? null])
            ->all();
    }

    public function sendToolRequest(string $name): void
    {
        if (blank($this->bearerToken)) {
            $this->toolResponses[$name] = [
                'isError' => true,
                'structuredContent' => null,
                'text' => 'Sila masukkan bearer token dahulu (rujuk menu "Authentication & Tokens").',
            ];

            return;
        }

        $arguments = collect($this->toolInputs[$name] ?? [])
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->all();

        try {
            $result = Client::web(url('/mcp/restock'))
                ->withToken($this->bearerToken)
                ->connect()
                ->callTool($name, $arguments);

            $this->toolResponses[$name] = [
                'isError' => $result->isError,
                'structuredContent' => $result->structuredContent,
                'text' => $result->text(),
            ];
        } catch (Throwable $exception) {
            $this->toolResponses[$name] = [
                'isError' => true,
                'structuredContent' => null,
                'text' => $exception->getMessage(),
            ];
        }
    }
}
