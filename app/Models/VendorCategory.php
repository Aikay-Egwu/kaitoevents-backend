<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VendorCategory extends Model
{
    /** @use HasFactory<\Database\Factories\VendorCategoryFactory> */
    use HasFactory;

    protected $fillable = ['name', 'slug', 'description'];

    public function vendorTypes()
    {
        return $this->hasMany(VendorType::class);
    }
}
