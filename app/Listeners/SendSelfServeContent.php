<?php

namespace App\Listeners;

use App\Events\ClientRoutedToSelfServe;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendSelfServeContent
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(ClientRoutedToSelfServe $event): void
    {
        //
    }
}
