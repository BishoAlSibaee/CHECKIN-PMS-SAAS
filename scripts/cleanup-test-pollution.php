<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ClientNote;
use Illuminate\Support\Facades\DB;

$deletedNotes = ClientNote::where('description', 'Test client note body')->delete();

$dupes = DB::table('roomtype_pricingplan')
    ->select('roomtype_id', 'pricingplan_id', DB::raw('MIN(id) as keep_id'), DB::raw('COUNT(*) as c'))
    ->groupBy('roomtype_id', 'pricingplan_id')
    ->having('c', '>', 1)
    ->get();

$deletedLinks = 0;
foreach ($dupes as $row) {
    $deletedLinks += DB::table('roomtype_pricingplan')
        ->where('roomtype_id', $row->roomtype_id)
        ->where('pricingplan_id', $row->pricingplan_id)
        ->where('id', '!=', $row->keep_id)
        ->delete();
}

echo "deleted_test_client_notes={$deletedNotes}\n";
echo "deleted_duplicate_pricing_links={$deletedLinks}\n";
echo "client_1_notes_remaining=" . ClientNote::where('client_id', 1)->count() . "\n";
