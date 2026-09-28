<?php

namespace Database\Factories;

// ShopOrderFactory — generates shop customer orders with snapshot line items (used in tests).

use App\Models\Inventory;
use App\Models\ShopOrder;
use App\Models\ShopOrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ShopOrder>
 */
class ShopOrderFactory extends Factory
{
    protected $model = ShopOrder::class;

    public function definition(): array
    {
        $paymentMethod = $this->faker->randomElement([
            ShopOrder::PAYMENT_CARD,
            ShopOrder::PAYMENT_BANK_TRANSFER,
            ShopOrder::PAYMENT_ON_ARRIVAL,
        ]);

        $subtotal = $this->faker->randomFloat(2, 200, 3000);
        $vat      = round($subtotal * 0.2, 2);
        $shipping = $this->faker->randomFloat(2, 0, 60);
        $grand    = round($subtotal + $vat + $shipping, 2);

        return [
            'order_ref'            => ShopOrder::generateReference(),
            'status'               => ShopOrder::initialStatusFor($paymentMethod),
            'customer_full_name'   => $this->faker->name,
            'customer_email'       => $this->faker->unique()->safeEmail,
            'customer_phone'       => '+44 ' . $this->faker->numerify('##########'),
            'billing_address_line_1' => $this->faker->streetAddress,
            'billing_address_line_2' => null,
            'billing_city'           => $this->faker->city,
            'billing_postcode'       => strtoupper($this->faker->bothify('??# #??')),
            'billing_country'        => 'United Kingdom',
            'event_date'           => $this->faker->dateTimeBetween('+2 weeks', '+6 months')->format('Y-m-d'),
            'event_venue'          => $this->faker->optional(0.7, null)->company . ' Venue',
            'subtotal_ex_vat'      => $subtotal,
            'vat_amount'           => $vat,
            'shipping_amount'      => $shipping,
            'grand_total'          => $grand,
            'payment_method'       => $paymentMethod,
            'notes'                => $this->faker->optional(0.4, null)->sentence(10),
            'source_ref'           => $this->faker->randomElement(['diy', 'your-design', null]),
        ];
    }

    /**
     * Configure the factory to also create 1–3 attached snapshot line items.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (ShopOrder $order) {
            $productCount = $this->faker->numberBetween(1, 3);
            $products = Inventory::forShop()->inRandomOrder()->limit($productCount)->get();
            if ($products->isEmpty()) {
                $products = Inventory::factory()->count($productCount)->create(['for_shop' => true]);
            }

            foreach ($products as $product) {
                $qty = $this->faker->numberBetween(1, 2);
                $unitEx = (float) ($product->shop_price ?? $product->price ?? 0);
                ShopOrderItem::factory()->create([
                    'shop_order_id'       => $order->id,
                    'inventory_id'        => $product->id,
                    'snapshot_name'       => $product->name,
                    'snapshot_slug'       => $product->slug,
                    'unit_price_ex_vat'   => $unitEx,
                    'quantity'            => $qty,
                    'line_price_ex_vat'   => round($unitEx * $qty, 2),
                ]);
            }
        });
    }
}
