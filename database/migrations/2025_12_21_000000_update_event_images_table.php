<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('event_images', function (Blueprint $table) {
            // Add new columns
            $table->string('filename')->after('event_id');
            $table->string('original_filename')->nullable()->after('filename');
            $table->string('file_path')->after('original_filename');
            $table->integer('file_size')->default(0)->after('file_path');
            $table->string('mime_type')->nullable()->after('file_size');
            $table->string('category')->default('other')->after('mime_type');
            $table->text('description')->nullable()->after('category');
            $table->json('tags')->nullable()->after('description');
            $table->boolean('is_primary')->default(false)->after('tags');
            $table->integer('sort_order')->default(0)->after('is_primary');
            $table->json('thumbnails')->nullable()->after('sort_order');
            $table->string('dimensions')->nullable()->after('thumbnails');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete()->after('dimensions');

            // Drop old columns that are replaced/obsolete
            $table->dropColumn(['image_path', 'caption']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('event_images', function (Blueprint $table) {
            // Restore old columns
            $table->string('image_path')->nullable();
            $table->text('caption')->nullable();

            // Drop new columns
            $table->dropColumn([
                'filename',
                'original_filename',
                'file_path',
                'file_size',
                'mime_type',
                'category',
                'description',
                'tags',
                'is_primary',
                'sort_order',
                'thumbnails',
                'dimensions',
                'uploaded_by'
            ]);
        });
    }
};
