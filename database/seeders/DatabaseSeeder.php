<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Event;
use App\Models\Client;
use App\Models\Service;
use App\Models\EventType;
use App\Models\Inventory;
use App\Models\EventVenue;
use App\Models\InventoryCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Database Seeder
 *
 * Order of operations (inside a DB transaction so failures roll back cleanly):
 *   1. Admin user + event types + services + sample clients/events
 *   2. INVENTORY — loaded from pre-extracted static PHP array:
 *        a. 14 parent inventory_categories (parent_id = null)
 *        b. 148 child categories with parent_idx → parent_id mapping
 *        c. 440 inventory items with child_idx → inventory_category_id
 *   3. PropSeeder + RestrictionTypeSeeder (unchanged)
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $this->seedAdminAndBaseData();
            $this->seedInventoryFromArrays();
            $this->seedSampleClientsAndEvents();

            try {
                $this->call([
                    PropSeeder::class,
                    RestrictionTypeSeeder::class,
                ]);
            } catch (\Throwable $e) {
                $this->command->warn('Skipping unrelated pre-existing seeders: ' . $e->getMessage());
            }
        });

        $this->command->info('Database seeded successfully!');
    }

    /* -------------------- Part 1: admin + services + event types -------------------- */

    private function seedAdminAndBaseData(): void
    {
        /* User::factory()->create([
            'name'     => 'Admin User',
            'email'    => 'justaikay@gmail.com',
            'type'     => 'Admin',
            'password' => Hash::make('SecurePassword'),
        ]); */
        User::create([
            'name'     => 'Admin User',
            'email'    => 'justaikay@gmail.com',
            'type'     => 'Admin',
            'password' => Hash::make('SecurePassword'),
        ]);



        $eventTypes = [
            ['title' => 'Wedding',         'description' => 'Wedding ceremonies and receptions',               'slug' => 'wedding',         'status' => 'active'],
            ['title' => 'Corporate Event', 'description' => 'Business meetings and corporate gatherings',     'slug' => 'corporate-event', 'status' => 'active'],
            ['title' => 'Birthday Party',  'description' => 'Birthday celebrations and parties',              'slug' => 'birthday-party',  'status' => 'active'],
            ['title' => 'Anniversary',     'description' => 'Anniversary celebrations',                        'slug' => 'anniversary',     'status' => 'active'],
            ['title' => 'Conference',      'description' => 'Professional conferences and seminars',          'slug' => 'conference',      'status' => 'active'],
        ];
        foreach ($eventTypes as $et) {
            EventType::create($et);
        }

        $services = [
            ['name' => 'Logistics',                      'description' => 'Complete styling and arranging',               'price' => 500.00],
            ['name' => 'Design & Setup',                 'description' => 'Complete event planning and coordination',    'price' => 500.00],
            ['name' => 'Print works',                    'description' => 'Full catering service with menu options',     'price' => 25.00],
            ['name' => 'Print Menu Cards',               'description' => 'Professional event photography',             'price' => 300.00],
            ['name' => 'Print Thank You Card',           'description' => 'Professional DJ and music services',         'price' => 200.00],
            ['name' => 'Install and remove dance floor', 'description' => 'Custom floral designs and arrangements',     'price' => 150.00],
        ];
        foreach ($services as $svc) {
            Service::create($svc);
        }
    }

    /* -------------------------- Part 2: Static inventory -------------------------- */

    /**
     * Load inventory data from the pre-extracted static PHP array file.
     * Replaces the runtime PhpSpreadsheet-based Excel parser (fragile in production
     * where the .xlsx file may be missing, memory-limited, or PhpSpreadsheet unavailable).
     *
     * Data file structure:
     *   parents  — [idx => ['name' => string]]                      (14 root categories)
     *   children — [idx => ['parent_idx' => int, 'name' => string]] (148 subcategories)
     *   items    — [idx => [...all Inventory columns except color + child_idx]]
     */
    private function seedInventoryFromArrays(): void
    {
        $dataPath = __DIR__ . '/_extracted_inventory_data.php';
        if (!file_exists($dataPath)) {
            $this->command->warn("Inventory data file not found at: {$dataPath} — skipping inventory seed.");
            return;
        }

        $data = require $dataPath;
        if (!is_array($data) || !isset($data['parents'], $data['children'], $data['items'])) {
            $this->command->warn('Inventory data file has invalid structure — skipping inventory seed.');
            return;
        }

        // 2a. Create all 14 parent categories (parent_id = null)
        $parentModels = []; // original array index => InventoryCategory
        foreach ($data['parents'] as $idx => $parent) {
            $parentModels[$idx] = InventoryCategory::firstOrCreate(
                ['category_name' => $parent['name']],
                ['parent_id' => null]
            );
        }

        // 2b. Create all 148 child categories — each has parent_idx pointing at $parentModels
        $childModels = []; // original child index => InventoryCategory
        foreach ($data['children'] as $idx => $child) {
            $parentId = $parentModels[$child['parent_idx']]->id ?? null;
            if ($parentId === null) continue;
            $childModels[$idx] = InventoryCategory::firstOrCreate([
                'parent_id'     => $parentId,
                'category_name' => $child['name'],
            ]);
        }

        // 2c. Create all 440 inventory items, each referencing a child category
        $seenAssetIds = [];
        $totalItems = 0;
        foreach ($data['items'] as $item) {
            $assetKey = strtoupper(trim($item['asset_id'] ?? ''));
            if ($assetKey === '' || isset($seenAssetIds[$assetKey])) continue;

            $childIdx = $item['child_idx'] ?? null;
            $categoryId = $childModels[$childIdx]->id ?? null;
            if ($categoryId === null) continue;

            $createData = $item;
            unset($createData['child_idx']);
            $createData['asset_id']              = $assetKey;
            $createData['inventory_category_id'] = $categoryId;
            $createData['color']                 = null;
            $createData['cost_price'] = 1.0;

            Inventory::create($createData);
            $seenAssetIds[$assetKey] = true;
            $totalItems++;
        }

        $parentsCount  = InventoryCategory::rootCategories()->count();
        $childrenCount = InventoryCategory::childCategories()->count();
        $itemsCount    = Inventory::count();
        $this->command->info("✅ Inventory seeded: {$parentsCount} parents, {$childrenCount} children, {$itemsCount} inventory items (inserted {$totalItems}).");
    }

    /* -------------------- Part 3: sample clients + events -------------------- */

    private function seedSampleClientsAndEvents(): void
    {
        $client1 = Client::create([
            'firstname' => 'Ikenna',
            'lastname'  => 'Egwu',
            'email'     => 'justaikay@gmail.com',
            'phone'     => '07442025899',
        ]);
        $this->createEvent($client1->id, [
            'client_id'            => $client1->id,
            'event_type_id'        => 1,
            'event_date'           => '2025-08-21',
            'start_time'           => null,
            'end_time'             => null,
            'number_of_guests'     => 20,
            'budget'               => 2000,
            'special_instructions' => null,
            'status'               => 'inquiry',
            'venue_name'           => 'Grand Place',
            'venue_address'        => 'Place near other place in the place',
        ]);

        $client2 = Client::create([
            'firstname' => 'Sarah',
            'lastname'  => 'Johnson',
            'email'     => 'sarah.johnson@example.com',
            'phone'     => '08123456789',
        ]);
        $this->createEvent($client2->id, [
            'client_id'            => $client2->id,
            'event_type_id'        => 3,
            'event_date'           => '2025-09-15',
            'start_time'           => '18:00:00',
            'end_time'             => '23:00:00',
            'number_of_guests'     => 50,
            'budget'               => 150000.00,
            'special_instructions' => 'Birthday celebration with live music',
            'status'               => 'inquiry',
            'venue_name'           => 'Rooftop Lounge',
            'venue_address'        => '789 Skyline Avenue, Ikoyi, Lagos',
        ]);
    }

    public function createEvent(mixed $clientId, array $event): void
    {
        $venue = [
            'venue_name'    => $event['venue_name'],
            'venue_address' => $event['venue_address'],
        ];
        unset($event['venue_name'], $event['venue_address']);

        $createdEvent = Event::create(array_merge(['client_id' => $clientId], $event));
        EventVenue::create(array_merge(['event_id' => $createdEvent->id], $venue));
    }
}
