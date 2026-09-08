<?php

namespace App\Rules;

use App\Models\Event;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidStatusTransition implements ValidationRule
{
    private ?Event $event;

    public function __construct(?Event $event = null)
    {
        $this->event = $event;
    }

    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!$this->event || !$value) {
            return;
        }

        $currentStatus = $this->event->status;
        $newStatus = $value;

        // If status is not changing, allow it
        if ($currentStatus === $newStatus) {
            return;
        }

        $validTransitions = $this->getValidTransitions();

        if (!isset($validTransitions[$currentStatus]) || !in_array($newStatus, $validTransitions[$currentStatus])) {
            $allowedTransitions = isset($validTransitions[$currentStatus]) 
                ? implode(', ', $validTransitions[$currentStatus])
                : 'none';
                
            $fail("Cannot change status from '{$currentStatus}' to '{$newStatus}'. Allowed transitions: {$allowedTransitions}.");
        }
    }

    /**
     * Get valid status transitions.
     */
    private function getValidTransitions(): array
    {
        return [
            'pending' => ['confirmed', 'cancelled'],
            'confirmed' => ['in_progress', 'cancelled'],
            'in_progress' => ['completed', 'cancelled'],
            'completed' => [], // No transitions allowed from completed
            'cancelled' => [], // No transitions allowed from cancelled
        ];
    }
}