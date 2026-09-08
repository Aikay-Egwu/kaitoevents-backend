<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class InvoiceItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_invoice_id',
        'item_type',
        'item_id',
        'description',
        'quantity',
        'unit_price',
        'total_price',
        'discount_percentage',
        'discount_amount',
        'tax_rate',
        'tax_amount',
        'notes',
        'sort_order',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'total_price' => 'decimal:2',
        'discount_percentage' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_rate' => 'decimal:4',
        'tax_amount' => 'decimal:2',
        'sort_order' => 'integer',
    ];

    // Relationships
    public function invoice()
    {
        return $this->belongsTo(EventInvoice::class, 'event_invoice_id');
    }

    public function service()
    {
        return $this->belongsTo(Service::class, 'item_id');
    }

    public function inventory()
    {
        return $this->belongsTo(Inventory::class, 'item_id');
    }

    public function item()
    {
        return match ($this->item_type) {
            'service' => $this->service(),
            'inventory' => $this->inventory(),
            default => null,
        };
    }

    // Scopes
    public function scopeByType(Builder $query, string $type): Builder
    {
        return $query->where('item_type', $type);
    }

    public function scopeServices(Builder $query): Builder
    {
        return $query->where('item_type', 'service');
    }

    public function scopeInventory(Builder $query): Builder
    {
        return $query->where('item_type', 'inventory');
    }

    public function scopeCustom(Builder $query): Builder
    {
        return $query->where('item_type', 'custom');
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    // Business Logic Methods
    public function calculateTotal(): self
    {
        $subtotal = $this->quantity * $this->unit_price;
        
        // Calculate discount
        $discountAmount = 0;
        if ($this->discount_percentage > 0) {
            $discountAmount = $subtotal * ($this->discount_percentage / 100);
        }
        
        // Calculate tax on discounted amount
        $taxableAmount = $subtotal - $discountAmount;
        $taxAmount = 0;
        if ($this->tax_rate > 0) {
            $taxAmount = $taxableAmount * $this->tax_rate;
        }
        
        $totalPrice = $subtotal - $discountAmount + $taxAmount;
        
        $this->update([
            'discount_amount' => $discountAmount,
            'tax_amount' => $taxAmount,
            'total_price' => $totalPrice,
        ]);
        
        return $this;
    }

    public function applyDiscount(float $percentage): self
    {
        $this->update(['discount_percentage' => $percentage]);
        return $this->calculateTotal();
    }

    public function applyTax(float $rate): self
    {
        $this->update(['tax_rate' => $rate]);
        return $this->calculateTotal();
    }

    public function getSubtotal(): float
    {
        return $this->quantity * $this->unit_price;
    }

    public function getDiscountedSubtotal(): float
    {
        return $this->getSubtotal() - $this->discount_amount;
    }

    public function getTaxableAmount(): float
    {
        return $this->getDiscountedSubtotal();
    }

    public function isService(): bool
    {
        return $this->item_type === 'service';
    }

    public function isInventory(): bool
    {
        return $this->item_type === 'inventory';
    }

    public function isCustom(): bool
    {
        return $this->item_type === 'custom';
    }

    public function hasDiscount(): bool
    {
        return $this->discount_percentage > 0 || $this->discount_amount > 0;
    }

    public function hasTax(): bool
    {
        return $this->tax_rate > 0 || $this->tax_amount > 0;
    }

    public function getFormattedDescription(): string
    {
        $description = $this->description;
        
        if ($this->notes) {
            $description .= "\n" . $this->notes;
        }
        
        return $description;
    }

    public function getItemDetails(): ?array
    {
        if ($this->isCustom()) {
            return null;
        }

        // Use property access (not method call) to retrieve related model
        // from the BelongsTo relation; `$this->item()` would return the
        // Relation builder object and cause an undefined property error.
        $item = $this->item;

        if (!$item) {
            return null;
        }

        if ($this->isService()) {
            return [
                'id'             => $item->getKey(),
                'name'           => $item->name ?? $item->service_name ?? null,
                'description'    => $item->description ?? $item->service_description ?? null,
                'category'       => $item->category?->category_name ?? $item->category?->name ?? null,
                'duration_hours' => $item->duration_hours ?? null,
            ];
        }

        if ($this->isInventory()) {
            return [
                'id'          => $item->getKey(),
                'name'        => $item->name ?? $item->inventory_name ?? null,
                'description' => $item->description ?? $item->inventory_description ?? null,
                'category'    => $item->category?->category_name ?? $item->category?->name ?? null,
                'sku'         => $item->sku ?? $item->asset_id ?? null,
            ];
        }

        return null;
    }

    public function updateQuantity(int $quantity): self
    {
        $this->update(['quantity' => $quantity]);
        return $this->calculateTotal();
    }

    public function updateUnitPrice(float $price): self
    {
        $this->update(['unit_price' => $price]);
        return $this->calculateTotal();
    }

    public function updateDescription(string $description): self
    {
        $this->update(['description' => $description]);
        return $this;
    }

    public function addNotes(string $notes): self
    {
        $existingNotes = $this->notes ? $this->notes . "\n" : '';
        $this->update(['notes' => $existingNotes . $notes]);
        return $this;
    }

    public function setSortOrder(int $order): self
    {
        $this->update(['sort_order' => $order]);
        return $this;
    }

    public function duplicate(): self
    {
        $duplicate = $this->replicate();
        $duplicate->save();
        
        return $duplicate;
    }

    public function getEffectiveDiscountRate(): float
    {
        if ($this->getSubtotal() == 0) {
            return 0;
        }
        
        return ($this->discount_amount / $this->getSubtotal()) * 100;
    }

    public function getEffectiveTaxRate(): float
    {
        if ($this->getTaxableAmount() == 0) {
            return 0;
        }
        
        return ($this->tax_amount / $this->getTaxableAmount()) * 100;
    }

    public function getFormattedUnitPrice(): string
    {
        return number_format($this->unit_price, 2);
    }

    public function getFormattedTotalPrice(): string
    {
        return number_format($this->total_price, 2);
    }

    public function getFormattedSubtotal(): string
    {
        return number_format($this->getSubtotal(), 2);
    }

    public function getFormattedDiscountAmount(): string
    {
        return number_format($this->discount_amount, 2);
    }

    public function getFormattedTaxAmount(): string
    {
        return number_format($this->tax_amount, 2);
    }

    // Event Listeners
    protected static function booted(): void
    {
        static::creating(function (InvoiceItem $item) {
            // Set default sort order
            if (!$item->sort_order) {
                $maxOrder = static::where('event_invoice_id', $item->event_invoice_id)
                                 ->max('sort_order') ?? 0;
                $item->sort_order = $maxOrder + 1;
            }
        });

        static::created(function (InvoiceItem $item) {
            // Calculate totals after creation
            $item->calculateTotal();
            
            // Update invoice totals
            $item->invoice->calculateTotals();
        });

        static::updated(function (InvoiceItem $item) {
            // Update invoice totals when item is updated
            if ($item->isDirty(['quantity', 'unit_price', 'discount_percentage', 'tax_rate'])) {
                $item->invoice->calculateTotals();
            }
        });

        static::deleted(function (InvoiceItem $item) {
            // Update invoice totals when item is deleted
            $item->invoice->calculateTotals();
        });
    }
}