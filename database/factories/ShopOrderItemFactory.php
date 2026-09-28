<?php

namespace Database\Factories;

// ShopOrderItemFactory — single line item (typically created from OrderFactory configure hook).

use App\Models\ShopOrder;
use App\Models\ShopOrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ShopOrderItem>
 */
class ShopOrderItemFactory extends Factory
{
    protected $model = ShopOrderItem::class;

    public function definition(): array
    {
        $unit = $this->faker->randomFloat(2, 50, 2500);
        $qty  = $this->faker->numberBetween(1, 3);

        return [
            'shop_order_id'         => ShopOrder::factory(),
            'inventory_id'          => null,
            'snapshot_name'         => $this->faker->words(3, true),
            'snapshot_slug'         => $this->faker->slug,
            'snapshot_variant_label' => $this->faker->optional(0.5, null)->randomElement(['Intimate', 'Classic', 'Signature']),
            'unit_price_ex_vat'     => $unit,
            'quantity'              => $qty,
            'line_price_ex_vat'     => round($unit * $qty, 2),
            'customisations'        => null,
        ];
    }
}
