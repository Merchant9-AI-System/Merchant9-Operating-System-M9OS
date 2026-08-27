<?php

namespace App\Filament\Pages\Concerns;

use Guava\FilamentMcp\McpPlugin;

/**
 * Dikongsi oleh semua halaman "API Docs" yg rujuk /mcp/admin (guava/filament-mcp) - baca
 * status BENAR drpd panel (hasPlugin), bukan andaian statik, supaya bila plugin
 * didaftar/dinyahdaftar semula di AdminPanelProvider, halaman docs auto ikut - elak masalah
 * dokumentasi terlapuk yg dah beberapa kali jadi punca kekeliruan sesi ni.
 */
trait HasApiDocsStatus
{
    public function adminMcpIsActive(): bool
    {
        return filament()->getPanel('admin')->hasPlugin(McpPlugin::ID);
    }
}
