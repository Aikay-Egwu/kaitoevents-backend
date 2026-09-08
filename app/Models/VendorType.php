<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VendorType extends Model
{
    /** @use HasFactory<\Database\Factories\VendorTypeFactory> */
    use HasFactory;

    protected $fillable = ['vendor_category_id', 'name', 'slug'];

    public function vendorCategory()
    {
        return $this->belongsTo(VendorCategory::class);
    }
}
