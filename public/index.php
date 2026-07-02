<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

// Naikkan had memori drpd php.ini lalai (128M) - halaman analisis (RestockBySize dll) hydrate
// beribu InventoryPiece model sekali gus utk sokong sort/filter Filament, boleh capai had lalai
// pada dataset besar. Ini setara dgn `<ini name="memory_limit" value="512M"/>` dlm phpunit.xml
// (utk ujian) tapi utk PERMINTAAN SEBENAR (php artisan serve/production).
ini_set('memory_limit', '512M');

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
