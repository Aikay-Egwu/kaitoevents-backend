<?php
ini_set('memory_limit', '1024M');

/**
 * One-off extractor: reads Kaito_Events_Stock_Asset_Register.xlsx using the
 * project's installed PhpSpreadsheet and prints a PHP array containing:
 *   - parents[]: 14 root inventory_categories
 *   - children[]: sub-categories keyed by parent index
 *   - items[]: inventory items with category pointers + all fields
 *
 * Usage:  php extract_inventory.php > database/seeders/_extracted_inventory_data.php
 */

require __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as SharedDate;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

$excelPath = __DIR__ . '/../Kaito_Events_Stock_Asset_Register (1).xlsx';
if (!file_exists($excelPath)) {
    fwrite(STDERR, "Excel file not found at {$excelPath}\n");
    exit(1);
}

// ---------------- helpers (mirror of DatabaseSeeder helpers) ----------------

function cell($ws, int $row, int $col): mixed {
    $val = $ws->getCell([$col, $row])->getValue();
    if ($val === null) return '';
    if (is_string($val)) {
        if (str_starts_with(trim($val), '#')) return '';
        return trim($val);
    }
    return $val;
}
function toDecimal(mixed $v): ?float {
    if ($v === '' || $v === null || $v === '-') return null;
    if (is_numeric($v)) return (float)$v;
    $clean = preg_replace('/[^0-9.\-]/', '', (string)$v);
    if ($clean === '' || $clean === '-' || $clean === '.') return null;
    return (float)$clean;
}
function toInt(mixed $v): ?int {
    if ($v === '' || $v === null || $v === '-') return null;
    if (is_numeric($v)) return (int)$v;
    $clean = preg_replace('/[^0-9\-]/', '', (string)$v);
    if ($clean === '' || $clean === '-') return null;
    return (int)$clean;
}
function toDate(mixed $v): ?string {
    if ($v === '' || $v === null || $v === '-') return null;
    if (is_numeric($v)) {
        try {
            $ts = SharedDate::excelToTimestamp((float)$v);
            return date('Y-m-d', $ts);
        } catch (\Throwable) { return null; }
    }
    $s = trim((string)$v);
    $ts = strtotime($s);
    if ($ts === false) return null;
    return date('Y-m-d', $ts);
}
function splitLocation(string $loc): array {
    $loc = trim($loc);
    if ($loc === '') return [null, null];
    $parts = preg_split('/\s*[-—–\/]\s*/', $loc, 2);
    if (count($parts) === 2) return [trim($parts[0]) ?: null, trim($parts[1]) ?: null];
    return [$loc, null];
}
function mapConditionLabelToKey(string $raw): string {
    $lower = strtolower(trim($raw));
    if ($lower === '') return 'excellent_good';
    if (str_contains($lower, 'excel') || str_contains($lower, 'good')) return 'excellent_good';
    if (str_contains($lower, 'fair') || str_contains($lower, 'wear')) return 'fair_wear';
    if (str_contains($lower, 'repair')) return 'needs_repair';
    if (str_contains($lower, 'retire') || str_contains($lower, 'written') || str_contains($lower, 'write off')) return 'retired_written_off';
    return 'excellent_good';
}
function resolveChildName($ws, int $row, string $row6Subcategory): string {
    $d = trim((string)cell($ws, $row, 4));
    if ($d !== '') return $d;
    if ($row6Subcategory !== '') return $row6Subcategory;
    return 'Uncategorized';
}
function esc($v): string {
    if ($v === null) return 'null';
    if (is_bool($v)) return $v ? 'true' : 'false';
    if (is_int($v) || is_float($v)) return var_export($v, true);
    return var_export((string)$v, true);
}

// ---------------- read ----------------

$filter = new class implements IReadFilter {
    public function readCell($columnAddress, $row, $worksheetName = ''): bool {
        if ($row > 2500) return false;
        $colIdx = Coordinate::columnIndexFromString($columnAddress);
        return $colIdx >= 1 && $colIdx <= 21;
    }
};

$reader = IOFactory::createReaderForFile($excelPath);
$reader->setReadDataOnly(true);
$reader->setReadFilter($filter);
$spreadsheet = $reader->load($excelPath);

// ---- 2a. Dashboard: 14 parent categories ----
$dashboard = $spreadsheet->getSheetByName('📊 Dashboard');
if ($dashboard === null) {
    foreach ($spreadsheet->getSheetNames() as $n) {
        if (stripos($n, 'dashboard') !== false) {
            $dashboard = $spreadsheet->getSheetByName($n);
            break;
        }
    }
}

$parents = [];          // ordered list 0..13 of [name, sheet_match]
$parentBySheet = [];    // sheet_name => parent_index
if ($dashboard) {
    for ($r = 10; $r <= 23; $r++) {
        $name  = cell($dashboard, $r, 2);
        $sheet = cell($dashboard, $r, 3);
        if ($name === '' || strcasecmp(trim($name), 'TOTAL') === 0) continue;
        $idx = count($parents);
        $parents[] = ['name' => $name, 'sheet' => $sheet];
        if ($sheet !== '') $parentBySheet[trim($sheet)] = $idx;
    }
}

// ---- 2b+2c. Each numbered tab ----
$numbered = [];
foreach ($spreadsheet->getSheetNames() as $sn) {
    if (preg_match('/^(\d{1,2})\s+/', $sn, $m)) {
        $numbered[(int)$m[1]] = $sn;
    }
}
ksort($numbered, SORT_NUMERIC);

$children = [];   // children[parentIdx][childName] = childIndex (global)
$childrenFlat = []; // 0..N flat list of [parent_idx, name]
$items = [];
$seenAssetIds = [];
$childCounter = 0;

// init children buckets
foreach (array_keys($parents) as $pi) $children[$pi] = [];

foreach ($numbered as $idx => $sheetName) {
    if (isset($parentBySheet[$sheetName])) {
        $parentIdx = $parentBySheet[$sheetName];
    } elseif (isset($parents[$idx - 1])) {
        $parentIdx = $idx - 1;
    } else {
        $fallbackName = preg_replace('/^\d{1,2}\s+/', '', $sheetName);
        $parents[] = ['name' => $fallbackName, 'sheet' => $sheetName];
        $parentIdx = count($parents) - 1;
        $children[$parentIdx] = [];
        $parentBySheet[$sheetName] = $parentIdx;
    }

    $ws = $spreadsheet->getSheetByName($sheetName);
    if (!$ws) continue;

    $row6Subcategory = trim((string)cell($ws, 6, 2));

    $maxRow = $ws->getHighestRow();
    for ($r = 7; $r <= $maxRow; $r++) {
        $assetId = cell($ws, $r, 2);
        $qtyRaw  = cell($ws, $r, 5);
        if ($assetId === '' || $qtyRaw === '' || $qtyRaw === null || !is_numeric($qtyRaw)) {
            continue;
        }

        $childName = resolveChildName($ws, $r, $row6Subcategory);

        // find/create child in flat list
        if (!isset($children[$parentIdx][$childName])) {
            $children[$parentIdx][$childName] = $childCounter;
            $childrenFlat[$childCounter] = ['parent_idx' => $parentIdx, 'name' => $childName];
            $childCounter++;
        }
        $childIdx = $children[$parentIdx][$childName];

        $assetKey = strtoupper(trim($assetId));
        if (isset($seenAssetIds[$assetKey])) continue;
        $seenAssetIds[$assetKey] = true;

        $description       = cell($ws, $r, 3);
        $unit              = cell($ws, $r, 6);
        $conditionRaw      = trim((string)cell($ws, $r, 7));
        $unitReplCost      = toDecimal(cell($ws, $r, 8));
        $currentValue      = toDecimal(cell($ws, $r, 9));
        $purchaseDate      = toDate(cell($ws, $r, 10));
        $lastChecked       = toDate(cell($ws, $r, 11));
        $storageLoc        = trim((string)cell($ws, $r, 12));
        $supplier          = cell($ws, $r, 13);
        $patDue            = toDate(cell($ws, $r, 14));
        $eventsUsed        = toInt(cell($ws, $r, 15));
        $notes             = cell($ws, $r, 16);
        $costPrice         = toDecimal(cell($ws, $r, 17));
        $usageFee          = toDecimal(cell($ws, $r, 18));
        $totalRevenue      = toDecimal(cell($ws, $r, 19));
        $breakEven         = toInt(cell($ws, $r, 20));
        $profitDeficit     = toDecimal(cell($ws, $r, 21));

        [$locName, $locZone] = splitLocation($storageLoc);
        $price = $usageFee !== null ? $usageFee : ($costPrice !== null ? $costPrice * 1.5 : 0);
        $condition = mapConditionLabelToKey($conditionRaw);

        $items[] = [
            'asset_id'                => $assetKey,
            'child_idx'               => $childIdx,
            'name'                    => $description !== '' ? $description : "Item {$assetKey}",
            'description'             => $description !== '' ? $description : null,
            'unit'                    => $unit !== '' ? $unit : null,
            'condition'               => $condition,
            'unit_replacement_cost'   => $unitReplCost,
            'current_value'           => $currentValue,
            'purchase_date'           => $purchaseDate,
            'last_checked'            => $lastChecked,
            'location_name'           => $locName,
            'location_zone'           => $locZone,
            'supplier'                => $supplier !== '' ? $supplier : null,
            'pat_service_due'         => $patDue,
            'events_used_count'       => $eventsUsed ?? 0,
            'notes'                   => $notes !== '' ? $notes : null,
            'cost_price'              => $costPrice,
            'price'                   => $price,
            'total_revenue_generated' => $totalRevenue ?? 0,
            'break_even_events'       => $breakEven,
            'profit_deficit'          => $profitDeficit,
            'total_quantity'          => max(1, (int)$qtyRaw),
            'quantity_available'      => max(0, (int)$qtyRaw),
            'is_active'               => true,
        ];
    }

    // Ensure row-6 subcategory header is registered even if unused
    if ($row6Subcategory !== '' && !isset($children[$parentIdx][$row6Subcategory])) {
        $children[$parentIdx][$row6Subcategory] = $childCounter;
        $childrenFlat[$childCounter] = ['parent_idx' => $parentIdx, 'name' => $row6Subcategory];
        $childCounter++;
    }
}

// ---------------- print as PHP array file ----------------

echo "<?php\n";
echo "/**\n";
echo " * Auto-extracted from Kaito_Events_Stock_Asset_Register.xlsx via extract_inventory.php.\n";
echo " * Contains " . count($parents) . " parent categories, " . count($childrenFlat) . " child categories, and " . count($items) . " inventory items.\n";
echo " * DO NOT EDIT manually — regenerate by running: php extract_inventory.php > database/seeders/_extracted_inventory_data.php\n";
echo " */\n\n";

echo "return [\n";

// parents
echo "    'parents' => [\n";
foreach ($parents as $i => $p) {
    echo "        {$i} => ['name' => " . esc($p['name']) . "],\n";
}
echo "    ],\n\n";

// children (flat)
echo "    'children' => [\n";
foreach ($childrenFlat as $i => $c) {
    echo "        {$i} => ['parent_idx' => " . esc($c['parent_idx']) . ", 'name' => " . esc($c['name']) . "],\n";
}
echo "    ],\n\n";

// items
echo "    'items' => [\n";
foreach ($items as $i => $it) {
    echo "        {$i} => [\n";
    foreach ($it as $k => $v) {
        echo "            '" . $k . "' => " . esc($v) . ",\n";
    }
    echo "        ],\n";
}
echo "    ],\n";

echo "];\n";

fwrite(STDERR, "Done: " . count($parents) . " parents, " . count($childrenFlat) . " children, " . count($items) . " items.\n");
