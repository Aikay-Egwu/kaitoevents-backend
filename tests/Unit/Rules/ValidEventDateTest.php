<?php

namespace Tests\Unit\Rules;

use App\Rules\ValidEventDate;
use Tests\TestCase;

class ValidEventDateTest extends TestCase
{
    /** @test */
    public function it_passes_for_valid_future_date()
    {
        $rule = new ValidEventDate();
        $fails = false;
        
        $rule->validate('event_date', now()->addDays(7)->format('Y-m-d'), function ($message) use (&$fails) {
            $fails = true;
        });
        
        $this->assertFalse($fails);
    }

    /** @test */
    public function it_fails_for_past_date()
    {
        $rule = new ValidEventDate();
        $fails = false;
        $message = '';
        
        $rule->validate('event_date', now()->subDays(1)->format('Y-m-d'), function ($msg) use (&$fails, &$message) {
            $fails = true;
            $message = $msg;
        });
        
        $this->assertTrue($fails);
        $this->assertStringContains('must be at least 1 day(s) in the future', $message);
    }

    /** @test */
    public function it_fails_for_today()
    {
        $rule = new ValidEventDate();
        $fails = false;
        
        $rule->validate('event_date', now()->format('Y-m-d'), function ($message) use (&$fails) {
            $fails = true;
        });
        
        $this->assertTrue($fails);
    }

    /** @test */
    public function it_fails_for_date_too_far_in_future()
    {
        $rule = new ValidEventDate(1, 30); // Max 30 days
        $fails = false;
        $message = '';
        
        $rule->validate('event_date', now()->addDays(60)->format('Y-m-d'), function ($msg) use (&$fails, &$message) {
            $fails = true;
            $message = $msg;
        });
        
        $this->assertTrue($fails);
        $this->assertStringContains('cannot be more than 30 days in the future', $message);
    }

    /** @test */
    public function it_allows_custom_minimum_days()
    {
        $rule = new ValidEventDate(7); // Minimum 7 days
        $fails = false;
        
        // Should fail for 3 days in advance
        $rule->validate('event_date', now()->addDays(3)->format('Y-m-d'), function ($message) use (&$fails) {
            $fails = true;
        });
        
        $this->assertTrue($fails);
        
        // Should pass for 10 days in advance
        $fails = false;
        $rule->validate('event_date', now()->addDays(10)->format('Y-m-d'), function ($message) use (&$fails) {
            $fails = true;
        });
        
        $this->assertFalse($fails);
    }

    /** @test */
    public function it_passes_for_null_or_empty_value()
    {
        $rule = new ValidEventDate();
        $fails = false;
        
        $rule->validate('event_date', null, function ($message) use (&$fails) {
            $fails = true;
        });
        
        $this->assertFalse($fails);
        
        $rule->validate('event_date', '', function ($message) use (&$fails) {
            $fails = true;
        });
        
        $this->assertFalse($fails);
    }
}