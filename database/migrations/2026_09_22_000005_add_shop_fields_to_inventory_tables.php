<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Adds the shop-facing columns to the inventory tables so the shop can be
 * sourced from inventory rather than a duplicate catalogue.
 *
 * Written as ALTER migrations (not edits to the 2025 create files) so the
 * existing inventories / inventory_categories rows are upgraded in place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_categories', function (Blueprint $table) {
            $table->string('slug', 160)->nullable()->after('category_name');
            $table->tinyInteger('for_shop')->default(0)->after('category_image');
        });

        Schema::table('inventories', function (Blueprint $table) {
            $table->string('slug', 240)->nullable()->after('name');
            $table->text('tagline')->nullable()->after('description');
            $table->decimal('shop_price', 10, 2)->nullable()->after('price');
            $table->tinyInteger('for_shop')->default(0)->after('description');
            $table->unsignedInteger('suitable_for_guests_min')->nullable()->after('tagline');
            $table->unsignedInteger('suitable_for_guests_max')->nullable()->after('suitable_for_guests_min');
            $table->string('suitable_for_guests_label', 80)->nullable()->after('suitable_for_guests_max');
        });

        $this->backfillSlugs();

        Schema::table('inventory_categories', function (Blueprint $table) {
            $table->unique('slug');
        });

        Schema::table('inventories', function (Blueprint $table) {
            $table->unique('slug');
            $table->index(['for_shop', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('inventories', function (Blueprint $table) {
            $table->dropIndex(['for_shop', 'is_active']);
            $table->dropUnique(['slug']);
            $table->dropColumn([
                'slug',
                'tagline',
                'shop_price',
                'for_shop',
                'suitable_for_guests_min',
                'suitable_for_guests_max',
                'suitable_for_guests_label',
            ]);
        });

        Schema::table('inventory_categories', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn(['slug', 'for_shop']);
        });
    }

    private function backfillSlugs(): void
    {
        foreach (DB::table('inventory_categories')->orderBy('id')->get() as $row) {
            $base = Str::slug((string) $row->category_name) ?: 'category';
            DB::table('inventory_categories')
                ->where('id', $row->id)
                ->update(['slug' => $this->uniqueSlug('inventory_categories', $base, (int) $row->id)]);
        }

        foreach (DB::table('inventories')->orderBy('id')->get() as $row) {
            $base = Str::slug((string) $row->name) ?: 'item';
            DB::table('inventories')
                ->where('id', $row->id)
                ->update(['slug' => $this->uniqueSlug('inventories', $base, (int) $row->id)]);
        }
    }

    private function uniqueSlug(string $table, string $base, int $id): string
    {
        $slug = $base;
        $suffix = 2;

        while (DB::table($table)->where('slug', $slug)->where('id', '<>', $id)->exists()) {
            $slug = $base . '-' . $suffix++;
        }

        return $slug;
    }
};