<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

class EventInvoice extends Model
{
    /** @use HasFactory<\Database\Factories\EventInvoiceFactory> */
    use HasFactory;

    protected $fillable = [
        'event_id',
        'invoice_number',
        'subtotal',
        'tax_amount',
        'discount_amount',
        'total_amount',
        'payment_status',
        'payment_method',
        'payment_date',
        'generated_at',
        'due_date',
        'notes',
        'terms_and_conditions',
        'tax_rate',
        'discount_percentage',
        'currency',
        'invoice_status',
        'sent_at',
        'viewed_at',
        'generated_by',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'tax_rate' => 'decimal:4',
        'discount_percentage' => 'decimal:2',
        'generated_at' => 'datetime',
        'due_date' => 'datetime',
        'payment_date' => 'datetime',
        'sent_at' => 'datetime',
        'viewed_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    // Relationships
    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function items()
    {
        return $this->hasMany(InvoiceItem::class, 'event_invoice_id');
    }

    public function generatedBy()
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // Scopes
    public function scopePaid(Builder $query): Builder
    {
        return $query->where('payment_status', 'paid');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('payment_status', 'pending');
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('payment_status', '!=', 'paid')
                    ->where('due_date', '<', now());
    }

    public function scopeByStatus(Builder $query, string $status): Builder
    {
        return $query->where('payment_status', $status);
    }

    public function scopeByInvoiceStatus(Builder $query, string $status): Builder
    {
        return $query->where('invoice_status', $status);
    }

    public function scopeDueWithin(Builder $query, int $days): Builder
    {
        return $query->where('due_date', '<=', now()->addDays($days))
                    ->where('payment_status', '!=', 'paid');
    }

    public function scopeGeneratedBetween(Builder $query, Carbon $startDate, Carbon $endDate): Builder
    {
        return $query->whereBetween('generated_at', [$startDate, $endDate]);
    }

    public function scopeByPaymentMethod(Builder $query, string $method): Builder
    {
        return $query->where('payment_method', $method);
    }

    // Business Logic Methods
    public function generateInvoiceNumber(): string
    {
        $year = now()->year;
        $month = now()->format('m');
        
        // Get the last invoice number for this year and month
        $lastInvoice = static::whereYear('generated_at', $year)
                           ->whereMonth('generated_at', now()->month)
                           ->orderBy('id', 'desc')
                           ->first();
        
        $nextNumber = $lastInvoice ? 
            (int) substr($lastInvoice->invoice_number, -4) + 1 : 1;
        
        $invoiceNumber = 'INV-' . $year . $month . '-' . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
        
        $this->update(['invoice_number' => $invoiceNumber]);
        
        return $invoiceNumber;
    }

    public function calculateTotals(): self
    {
        $subtotal = $this->items()->sum('total_price');
        
        // Calculate discount amount
        $discountAmount = 0;
        if ($this->discount_percentage > 0) {
            $discountAmount = $subtotal * ($this->discount_percentage / 100);
        }
        
        // Calculate tax on discounted amount
        $taxableAmount = $subtotal - $discountAmount;
        $taxRate = $this->tax_rate ?? config('invoice.default_tax_rate', 0.08);
        $taxAmount = $taxableAmount * $taxRate;
        
        $totalAmount = $subtotal - $discountAmount + $taxAmount;

        $this->update([
            'subtotal' => $subtotal,
            'discount_amount' => $discountAmount,
            'tax_amount' => $taxAmount,
            'total_amount' => $totalAmount,
            'tax_rate' => $taxRate,
        ]);

        return $this;
    }

    public function addServiceItems(): self
    {
        $event = $this->event;

        foreach ($event->services as $service) {
            $qty = $service->pivot->quantity ?? 1;
            $unit = $service->pivot->price ?? $service->price;
            $this->items()->create([
                'item_type'   => 'service',
                'item_id'     => $service->id,
                'description' => $service->name,
                'quantity'    => $qty,
                'unit_price'  => $unit,
                'total_price' => $unit * $qty,
            ]);
        }

        return $this;
    }

    public function addInventoryItems(): self
    {
        $event = $this->event;

        foreach ($event->inventories as $inventory) {
            $qty  = $inventory->pivot->quantity ?? 1;
            // Pivot price preferred, else fall back to inventory price (DB column)
            $unit = $inventory->pivot->price ?? $inventory->price;
            $this->items()->create([
                'item_type'   => 'inventory',
                'item_id'     => $inventory->id,
                'description' => $inventory->name . ' (Rental)',
                'quantity'    => $qty,
                'unit_price'  => $unit,
                'total_price' => $unit * $qty,
            ]);
        }

        return $this;
    }

    public function addCustomItem(string $description, int $quantity, float $unitPrice): InvoiceItem
    {
        return $this->items()->create([
            'item_type' => 'custom',
            'item_id' => null,
            'description' => $description,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'total_price' => $quantity * $unitPrice,
        ]);
    }

    public function isPaid(): bool
    {
        return $this->payment_status === 'paid';
    }

    public function isPending(): bool
    {
        return $this->payment_status === 'pending';
    }

    public function isOverdue(): bool
    {
        return $this->payment_status !== 'paid' && 
               $this->due_date && 
               $this->due_date->isPast();
    }

    public function isPartiallyPaid(): bool
    {
        return $this->payment_status === 'partially_paid';
    }

    public function isCancelled(): bool
    {
        return $this->payment_status === 'cancelled';
    }

    public function isRefunded(): bool
    {
        return $this->payment_status === 'refunded';
    }

    public function isDraft(): bool
    {
        return $this->invoice_status === 'draft';
    }

    public function isSent(): bool
    {
        return $this->invoice_status === 'sent';
    }

    public function isViewed(): bool
    {
        return $this->viewed_at !== null;
    }

    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }

    public function getDaysUntilDue(): int
    {
        if (!$this->due_date) {
            return 0;
        }
        
        return now()->diffInDays($this->due_date, false);
    }

    public function getDaysOverdue(): int
    {
        if (!$this->isOverdue()) {
            return 0;
        }
        
        return $this->due_date->diffInDays(now());
    }

    public function getAmountDue(): float
    {
        if ($this->isPaid()) {
            return 0;
        }
        
        return $this->total_amount;
    }

    public function getDiscountedSubtotal(): float
    {
        return $this->subtotal - $this->discount_amount;
    }

    public function getTaxableAmount(): float
    {
        return $this->getDiscountedSubtotal();
    }

    public function getEffectiveTaxRate(): float
    {
        if ($this->subtotal == 0) {
            return 0;
        }
        
        return ($this->tax_amount / $this->getTaxableAmount()) * 100;
    }

    public function markAsPaid(string $paymentMethod = null, Carbon $paymentDate = null): self
    {
        $this->update([
            'payment_status' => 'paid',
            'payment_method' => $paymentMethod,
            'payment_date' => $paymentDate ?? now(),
        ]);
        
        return $this;
    }

    public function markAsPartiallyPaid(float $amount, string $paymentMethod = null): self
    {
        $this->update([
            'payment_status' => 'partially_paid',
            'payment_method' => $paymentMethod,
            'payment_date' => now(),
        ]);
        
        return $this;
    }

    public function markAsCancelled(string $reason = null): self
    {
        $this->update([
            'payment_status' => 'cancelled',
            'notes' => $reason ? $this->notes . "\nCancelled: " . $reason : $this->notes,
        ]);
        
        return $this;
    }

    public function markAsRefunded(float $amount = null, string $reason = null): self
    {
        $this->update([
            'payment_status' => 'refunded',
            'notes' => $reason ? $this->notes . "\nRefunded: " . $reason : $this->notes,
        ]);
        
        return $this;
    }

    public function sendToClient(): self
    {
        $this->update([
            'invoice_status' => 'sent',
            'sent_at' => now(),
        ]);
        
        return $this;
    }

    public function markAsViewed(): self
    {
        if (!$this->viewed_at) {
            $this->update(['viewed_at' => now()]);
        }
        
        return $this;
    }

    public function approve(int $userId = null): self
    {
        $this->update([
            'approved_by' => $userId ?? auth()->id(),
            'approved_at' => now(),
            'invoice_status' => 'approved',
        ]);
        
        return $this;
    }

    public function getFormattedInvoiceNumber(): string
    {
        return $this->invoice_number ?? 'DRAFT';
    }

    public function getStatusColor(): string
    {
        return match ($this->payment_status) {
            'paid' => 'green',
            'pending' => 'yellow',
            'overdue' => 'red',
            'partially_paid' => 'orange',
            'cancelled' => 'gray',
            'refunded' => 'purple',
            default => 'blue',
        };
    }

    public function getStatusLabel(): string
    {
        return match ($this->payment_status) {
            'paid' => 'Paid',
            'pending' => 'Pending',
            'overdue' => 'Overdue',
            'partially_paid' => 'Partially Paid',
            'cancelled' => 'Cancelled',
            'refunded' => 'Refunded',
            default => ucfirst($this->payment_status),
        };
    }

    public function canBeEdited(): bool
    {
        return in_array($this->invoice_status, ['draft', 'pending']) && 
               !$this->isPaid();
    }

    public function canBeCancelled(): bool
    {
        return !in_array($this->payment_status, ['paid', 'cancelled', 'refunded']);
    }

    public function canBeRefunded(): bool
    {
        return $this->isPaid();
    }

    public function getItemsByType(string $type): \Illuminate\Database\Eloquent\Collection
    {
        return $this->items()->where('item_type', $type)->get();
    }

    public function getServiceItems(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->getItemsByType('service');
    }

    public function getInventoryItems(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->getItemsByType('inventory');
    }

    public function getCustomItems(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->getItemsByType('custom');
    }

    public function getTotalByType(string $type): float
    {
        return $this->items()->where('item_type', $type)->sum('total_price');
    }

    public function getServiceTotal(): float
    {
        return $this->getTotalByType('service');
    }

    public function getInventoryTotal(): float
    {
        return $this->getTotalByType('inventory');
    }

    public function getCustomTotal(): float
    {
        return $this->getTotalByType('custom');
    }

    // Event Listeners
    protected static function booted(): void
    {
        static::creating(function (EventInvoice $invoice) {
            // Set default values
            $invoice->currency = $invoice->currency ?? config('invoice.default_currency', 'USD');
            $invoice->invoice_status = $invoice->invoice_status ?? 'draft';
            $invoice->payment_status = $invoice->payment_status ?? 'pending';
            $invoice->generated_at = $invoice->generated_at ?? now();
            $invoice->generated_by = $invoice->generated_by ?? auth()->id();
            
            // Set due date if not provided (default 30 days)
            if (!$invoice->due_date) {
                $invoice->due_date = now()->addDays(config('invoice.default_due_days', 30));
            }
        });

        static::created(function (EventInvoice $invoice) {
            // Generate invoice number after creation
            if (!$invoice->invoice_number) {
                $invoice->generateInvoiceNumber();
            }
        });

        static::updating(function (EventInvoice $invoice) {
            // Log status changes
            if ($invoice->isDirty('payment_status')) {
                \Log::info('Invoice payment status changed', [
                    'invoice_id' => $invoice->id,
                    'old_status' => $invoice->getOriginal('payment_status'),
                    'new_status' => $invoice->payment_status,
                ]);
            }
        });
    }
}