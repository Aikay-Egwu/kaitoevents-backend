<?php

// Quick debug script — do not commit. Run via: php backend/debug-lazy.php

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\ShopProduct;
use App\Models\ShopCategory;
use Illuminate\Database\Eloquent\Model;

// Run in-memory DB with migrations
DB::purge('sqlite');
config(['database.connections.sqlite.database' => ':memory:']);
DB::reconnect();
\Artisan::call('migrate:fresh', ['--no-interaction' => true]);

ShopProduct::preventLazyLoading(true);
ShopCategory::preventLazyLoading(true);
Model::preventSilentlyDiscardingAttributes(true);

$cat = ShopCategory::factory()->create();
$prod = ShopProduct::factory()->create();
$prod->categories()->attach($cat);
echo "Created product {$prod->id}, category {$cat->id}\n";

// Query fresh without eager loading
$fresh = ShopProduct::find($prod->id);
try {
    $count = $fresh->categories->count();
    echo "NO EXCEPTION — count={$count}\n";
    echo "Product uses __get. Checking hasRelationLoaded...\n";
    var_dump($fresh->relationLoaded('categories'));
} catch (Throwable $e) {
    echo "EXCEPTION: " . get_class($e) . ": {$e->getMessage()}\n";
}
