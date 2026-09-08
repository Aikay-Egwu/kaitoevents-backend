<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * Adds missing columns to event_invoices that the EventInvoice model
     * expects via its $fillable.
     */
    public function up(): void
    {
        Schema::table('event_invoices', function (Blueprint $table) {
            $toAdd = [
                'tax_rate' => fn() => $table->decimal('tax_rate', 10, 4)->default(0.08)->after('tax_amount'),
                'discount_percentage' => fn() => $table->decimal('discount_percentage', 10, 2)->default(0)->after('tax_rate'),
                'discount_amount' => fn() => $table->decimal('discount_amount', 10, 2)->default(0)->after('discount_percentage'),
                'payment_method' => fn() => $table->string('payment_method')->nullable()->after('payment_status'),
                'payment_date' => fn() => $table->timestamp('payment_date')->nullable()->after('payment_method'),
                'notes' => fn() => $table->text('notes')->nullable()->after('due_date'),
                'terms_and_conditions' => fn() => $table->text('terms_and_conditions')->nullable()->after('notes'),
                'currency' => fn() => $table->string('currency', 3)->default('USD')->after('terms_and_conditions'),
                'invoice_status' => fn() => $table->string('invoice_status')->default('draft')->after('currency'),
                'sent_at' => fn() => $table->timestamp('sent_at')->nullable()->after('invoice_status'),
                'viewed_at' => fn() => $table->timestamp('viewed_at')->nullable()->after('sent_at'),
            ];
            foreach ($toAdd as $col => $builder) {
                if (!Schema::hasColumn('event_invoices', $col)) {
                    $builder();
                }
            }
            // FK columns
            if (!Schema::hasColumn('event_invoices', 'generated_by')) {
                $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete()->after('viewed_at');
            }
            if (!Schema::hasColumn('event_invoices', 'approved_by')) {
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete()->after('generated_by');
            }
            if (!Schema::hasColumn('event_invoices', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('approved_by');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('event_invoices', function (Blueprint $table) {
            $dropList = [
                'approved_at',
                'approved_by',
                'generated_by',
                'viewed_at',
                'sent_at',
                'invoice_status',
                'currency',
                'terms_and_conditions',
                'notes',
                'payment_date',
                'payment_method',
                'discount_amount',
                'discount_percentage',
                'tax_rate',
            ];
            foreach ($dropList as $col) {
                if (Schema::hasColumn('event_invoices', $col)) {
                    try {
                        // Drop FK first for FK columns
                        if (in_array($col, ['generated_by', 'approved_by'])) {
                            $table->dropConstrainedForeignId($col);
                        } else {
                            $table->dropColumn($col);
                        }
                    } catch (\Throwable) {
                        // ignore during rollback if index/fk missing
                    }
                }
            }
        });
    }
};
