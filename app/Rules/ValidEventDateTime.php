<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidEventDateTime implements ValidationRule
{
    private string $eventDate;
    private string $startTime;
    private int $minimumHoursInAdvance;

    public function __construct(string $eventDate, string $startTime, int $minimumHoursInAdvance = 24)
    {
        $this->eventDate = $eventDate;
        $this->startTime = $startTime;
        $this->minimumHoursInAdvance = $minimumHoursInAdvance;
    }

    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!$this->eventDate || !$this->startTime) {
            return;
        }

        $eventDateTime = $this->eventDate . ' ' . $this->startTime;
        $eventTimestamp = strtotime($eventDateTime);
        $currentTimestamp = time();

        if ($eventTimestamp === false) {
            $fail("The {$attribute} contains an invalid date/time combination.");
            return;
        }

        $hoursUntilEvent = ($eventTimestamp - $currentTimestamp) / 3600;

        if ($hoursUntilEvent < $this->minimumHoursInAdvance) {
            $fail("The event must be scheduled at least {$this->minimumHoursInAdvance} hours in advance.");
        }
    }
}