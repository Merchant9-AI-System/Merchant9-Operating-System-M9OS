<?php

namespace App\Filament\Pages\Concerns;

use App\Mcp\Servers\InventoryServer;
use ReflectionClass;

/**
 * Dikongsi oleh halaman "API Docs" yg perlu senarai tool InventoryServer BENAR2 wujud
 * (Overview - kiraan sahaja, Available Tools - senarai penuh + schema) - SATU sumber, elak
 * drift bila tool baharu ditambah/nama/description/schema diubah. Asalnya "HasRestockToolsList"
 * - dinamakan semula selari dgn RestockServer -> InventoryServer (skop dilanjutkan
 * melangkaui restock sahaja).
 */
trait HasInventoryToolsList
{
    /**
     * InventoryServer::__construct() perlukan Laravel\Mcp\Server\Contracts\Transport (disahkan
     * BindingResolutionException sebenar via tinker) - binding tsb HANYA wujud dlm konteks
     * permintaan MCP betul (Mcp::web()/Mcp::local() dispatch), bukan halaman Filament biasa ni.
     * newInstanceWithoutConstructor() elak dependency tsb terus - selamat sbb createContext()
     * (rujuk source) x sentuh $this->transport langsung, cuma baca $tools/$resources/$prompts.
     *
     * @return array<int, array{name: string, description: string, inputSchema: array<string, mixed>}>
     */
    public function getInventoryTools(): array
    {
        $server = (new ReflectionClass(InventoryServer::class))->newInstanceWithoutConstructor();

        return $server->createContext()->tools()
            ->map(fn ($tool) => [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'inputSchema' => $tool->toArray()['inputSchema'] ?? ['properties' => (object) [], 'required' => []],
            ])
            ->values()
            ->all();
    }
}
