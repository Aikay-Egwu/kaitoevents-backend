<?php

namespace App\Providers;

use App\Contracts\PaymentProcessorInterface;
use App\Services\Payments\SimulatedPaymentProcessor;
use App\Services\Payments\StripePaymentProcessor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PaymentProcessorInterface::class, function ($app) {
            $processor = env('SHOP_PAYMENT_PROCESSOR', 'simulated');
            return match ($processor) {
                'stripe'  => $app->make(StripePaymentProcessor::class),
                default   => $app->make(SimulatedPaymentProcessor::class),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // PCI strict guard: any attempt to fill a model with fields not in
        // $fillable throws MassAssignmentException instead of being silently
        // discarded. Security U-3: prevents stray card_number / cvc writes.
        Model::preventSilentlyDiscardingAttributes($this->app->environment('local') || $this->app->runningUnitTests());
        // Also enforce lazy loading guard during tests.
        Model::preventLazyLoading($this->app->runningUnitTests());
    }
}
