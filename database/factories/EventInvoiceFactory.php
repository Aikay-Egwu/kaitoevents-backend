<?php

namespace Database\Factories;

use App\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EventInvoice>
 */
class EventInvoiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $subtotal = $this->faker->randomFloat(2, 100, 10000);
        $discountPct = $this->faker->randomElement([0, 5, 10, 15]);
        $taxRate = 0.08;
        $discountAmount = $subtotal * ($discountPct / 100);
        $taxable = $subtotal - $discountAmount;
        $taxAmount = $taxable * $taxRate;
        $total = $subtotal - $discountAmount + $taxAmount;

        return [
            'event_id' => Event::factory(),
            'invoice_number' => 'INV-' . $this->faker->date('Ymd') . '-' . $this->faker->unique()->numberBetween(1000, 9999),
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'discount_amount' => $discountAmount,
            'total_amount' => $total,
            'tax_rate' => $taxRate,
            'discount_percentage' => $discountPct,
            'payment_status' => $this->faker->randomElement(['pending', 'paid', 'overdue', 'cancelled']),
            'generated_at' => now(),
            'due_date' => now()->addDays(30),
        ];
    }
}
