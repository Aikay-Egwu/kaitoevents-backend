<?php

namespace Tests\Unit\Rules;

use App\Models\Event;
use App\Rules\ValidStatusTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ValidStatusTransitionTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_allows_valid_status_transitions()
    {
        $validTransitions = [
            'pending' => ['confirmed', 'cancelled'],
            'confirmed' => ['in_progress', 'cancelled'],
            'in_progress' => ['completed', 'cancelled'],
        ];

        foreach ($validTransitions as $currentStatus => $allowedStatuses) {
            $event = Event::factory()->make(['status' => $currentStatus]);
            
            foreach ($allowedStatuses as $newStatus) {
                $rule = new ValidStatusTransition($event);
                $fails = false;
                
                $rule->validate('status', $newStatus, function ($message) use (&$fails) {
                    $fails = true;
                });
                
                $this->assertFalse($fails, "Should allow transition from {$currentStatus} to {$newStatus}");
            }
        }
    }

    /** @test */
    public function it_prevents_invalid_status_transitions()
    {
        $invalidTransitions = [
            'pending' => ['in_progress', 'completed'],
            'confirmed' => ['pending', 'completed'],
            'in_progress' => ['pending', 'confirmed'],
            'completed' => ['pending', 'confirmed', 'in_progress', 'cancelled'],
            'cancelled' => ['pending', 'confirmed', 'in_progress', 'completed'],
        ];

        foreach ($invalidTransitions as $currentStatus => $invalidStatuses) {
            $event = Event::factory()->make(['status' => $currentStatus]);
            
            foreach ($invalidStatuses as $newStatus) {
                $rule = new ValidStatusTransition($event);
                $fails = false;
                $message = '';
                
                $rule->validate('status', $newStatus, function ($msg) use (&$fails, &$message) {
                    $fails = true;
                    $message = $msg;
                });
                
                $this->assertTrue($fails, "Should prevent transition from {$currentStatus} to {$newStatus}");
                $this->assertStringContains("Cannot change status from '{$currentStatus}' to '{$newStatus}'", $message);
            }
        }
    }

    /** @test */
    public function it_allows_same_status_transition()
    {
        $event = Event::factory()->make(['status' => 'pending']);
        $rule = new ValidStatusTransition($event);
        $fails = false;
        
        $rule->validate('status', 'pending', function ($message) use (&$fails) {
            $fails = true;
        });
        
        $this->assertFalse($fails);
    }

    /** @test */
    public function it_passes_when_no_event_provided()
    {
        $rule = new ValidStatusTransition(null);
        $fails = false;
        
        $rule->validate('status', 'confirmed', function ($message) use (&$fails) {
            $fails = true;
        });
        
        $this->assertFalse($fails);
    }

    /** @test */
    public function it_passes_when_no_status_provided()
    {
        $event = Event::factory()->make(['status' => 'pending']);
        $rule = new ValidStatusTransition($event);
        $fails = false;
        
        $rule->validate('status', null, function ($message) use (&$fails) {
            $fails = true;
        });
        
        $this->assertFalse($fails);
    }

    /** @test */
    public function it_provides_helpful_error_messages_with_allowed_transitions()
    {
        $event = Event::factory()->make(['status' => 'pending']);
        $rule = new ValidStatusTransition($event);
        $message = '';
        
        $rule->validate('status', 'completed', function ($msg) use (&$message) {
            $message = $msg;
        });
        
        $this->assertStringContains("Cannot change status from 'pending' to 'completed'", $message);
        $this->assertStringContains('Allowed transitions: confirmed, cancelled', $message);
    }

    /** @test */
    public function it_handles_completed_status_with_no_allowed_transitions()
    {
        $event = Event::factory()->make(['status' => 'completed']);
        $rule = new ValidStatusTransition($event);
        $message = '';
        
        $rule->validate('status', 'pending', function ($msg) use (&$message) {
            $message = $msg;
        });
        
        $this->assertStringContains("Cannot change status from 'completed' to 'pending'", $message);
        $this->assertStringContains('Allowed transitions: none', $message);
    }
}