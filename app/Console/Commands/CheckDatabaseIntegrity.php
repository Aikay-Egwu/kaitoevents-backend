<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Client;
use App\Models\Event;
use App\Models\EventInvoice;
use App\Models\InvoiceItem;
use App\Models\Inventory;
use App\Models\Service;

class CheckDatabaseIntegrity extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:check-integrity';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check database integrity and relationships';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Checking database integrity...');

        $issues = [];

        // Check for orphaned events
        $orphanedEvents = Event::whereDoesntHave('client')->count();
        if ($orphanedEvents > 0) {
            $issues[] = "Found {$orphanedEvents} events without clients";
        }

        // Check for invoices without events
        $orphanedInvoices = EventInvoice::whereDoesntHave('event')->count();
        if ($orphanedInvoices > 0) {
            $issues[] = "Found {$orphanedInvoices} invoices without events";
        }

        // Check for invoice items without invoices
        $orphanedItems = InvoiceItem::whereDoesntHave('invoice')->count();
        if ($orphanedItems > 0) {
            $issues[] = "Found {$orphanedItems} invoice items without invoices";
        }

        // Check for inactive inventory/services assigned to active events
        $activeEventsWithInactiveInventory = Event::where('status', 'confirmed')
            ->whereHas('inventories', function($query) {
                $query->where('is_active', false);
            })->count();
        
        if ($activeEventsWithInactiveInventory > 0) {
            $issues[] = "Found {$activeEventsWithInactiveInventory} active events with inactive inventory";
        }

        $activeEventsWithInactiveServices = Event::where('status', 'confirmed')
            ->whereHas('services', function($query) {
                $query->where('is_active', false);
            })->count();
        
        if ($activeEventsWithInactiveServices > 0) {
            $issues[] = "Found {$activeEventsWithInactiveServices} active events with inactive services";
        }

        if (empty($issues)) {
            $this->info('✅ Database integrity check passed! No issues found.');
        } else {
            $this->error('❌ Database integrity issues found:');
            foreach ($issues as $issue) {
                $this->line("  - {$issue}");
            }
        }

        // Display summary statistics
        $this->info("\n📊 Database Summary:");
        $this->line("  Clients: " . Client::count());
        $this->line("  Events: " . Event::count());
        $this->line("  Invoices: " . EventInvoice::count());
        $this->line("  Invoice Items: " . InvoiceItem::count());
        $this->line("  Inventory Items: " . Inventory::count());
        $this->line("  Services: " . Service::count());

        return empty($issues) ? 0 : 1;
    }
}