<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vendor extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'vendor_type_id',
        'name',
        'email',
        'phone',
        'category',
        'website',
        'pricing_model',
        'base_price'
    ];

    public function vendorType(): BelongsTo
    {
        return $this->belongsTo(VendorType::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tasks(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'task_vendor')
            ->withTimestamps();
    }

    public function events()
    {
        return $this->belongsToMany(Event::class, 'event_vendors')
            ->withPivot([
                'status',
                'agreed_price',
                'advance_paid',
                'balance_due',
                'service_start',
                'service_end',
                'contract_path',
                'invoice_number',
                'notes'
            ])
            ->withTimestamps();
    }
}
