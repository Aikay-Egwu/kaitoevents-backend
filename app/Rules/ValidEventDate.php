<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidEventDate implements ValidationRule
{
    private int $minimumDaysInAdvance;
    private int $maximumDaysInAdvance;

    public function __construct(int $minimumDaysInAdvance = 1, int $maximumDaysInAdvance = 365)
    {
        $this->minimumDaysInAdvance = $minimumDaysInAdvance;
        $this->maximumDaysInAdvance = $maximumDaysInAdvance;
    }

    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!$value) {
            return;
        }

        $eventDate = strtotime($value);
        $today = strtotime('today');
        $daysFromToday = ($eventDate - $today) / 86400;

        if ($daysFromToday < $this->minimumDaysInAdvance) {
            $fail("The {$attribute} must be at least {$this->minimumDaysInAdvance} day(s) in the future.");
            return;
        }

        if ($daysFromToday > $this->maximumDaysInAdvance) {
            $fail("The {$attribute} cannot be more than {$this->maximumDaysInAdvance} days in the future.");
            return;
        }

        // Check if the date falls on a weekend (optional business rule)
        $dayOfWeek = date('N', $eventDate);
        if (in_array($dayOfWeek, [6, 7])) { // Saturday = 6, Sunday = 7
            // This is just a warning, not a failure - events can be on weekends
            // But we could add this as a business rule if needed
        }
    }
}