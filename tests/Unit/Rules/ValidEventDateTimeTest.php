<?php

namespace Tests\Unit\Rules;

use App\Rules\ValidEventDateTime;
use Tests\TestCase;

class ValidEventDateTimeTest extends TestCase
{
    /** @test */
    public function it_passes_for_valid_future_datetime()
    {
        $eventDate = now()->addDays(2)->format('Y-m-d');
        $startTime = '14:00';
        
        $rule = new ValidEventDateTime($eventDate, $startTime, 24);
        $fails = false;
        
        $rule->validate('event_datetime', null, function ($message) use (&$fails) {
            $fails = true;
        });
        
        $this->assertFalse($fails);
    }

    /** @test */
    public function it_fails_for_datetime_too_soon()
    {
        $eventDate = now()->format('Y-m-d');
        $startTime = now()->addHours(12)->format('H:i'); // Only 12 hours in advance
        
        $rule = new ValidEventDateTime($eventDate, $startTime, 24); // Requires 24 hours
        $fails = false;
        $message = '';
        
        $rule->validate('event_datetime', null, function ($msg) use (&$fails, &$message) {
            $fails = true;
            $message = $msg;
        });
        
        $this->assertTrue($fails);
        $this->assertStringContains('must be scheduled at least 24 hours in advance', $message);
    }

    /** @test */
    public function it_allows_custom_minimum_hours()
    {
        $eventDate = now()->format('Y-m-d');
        $startTime = now()->addHours(6)->format('H:i');
        
        // Should fail with 12 hour requirement
        $rule = new ValidEventDateTime($eventDate, $startTime, 12);
        $fails = false;
        
        $rule->validate('event_datetime', null, function ($message) use (&$fails) {
            $fails = true;
        });
        
        $this->assertTrue($fails);
        
        // Should pass with 4 hour requirement
        $rule = new ValidEventDateTime($eventDate, $startTime, 4);
        $fails = false;
        
        $rule->validate('event_datetime', null, function ($message) use (&$fails) {
            $fails = true;
        });
        
        $this->assertFalse($fails);
    }

    /** @test */
    public function it_handles_invalid_date_time_format()
    {
        $rule = new ValidEventDateTime('invalid-date', 'invalid-time', 24);
        $fails = false;
        $message = '';
        
        $rule->validate('event_datetime', null, function ($msg) use (&$fails, &$message) {
            $fails = true;
            $message = $msg;
        });
        
        $this->assertTrue($fails);
        $this->assertStringContains('invalid date/time combination', $message);
    }

    /** @test */
    public function it_passes_when_date_or_time_is_missing()
    {
        // Missing date
        $rule = new ValidEventDateTime('', '14:00', 24);
        $fails = false;
        
        $rule->validate('event_datetime', null, function ($message) use (&$fails) {
            $fails = true;
        });
        
        $this->assertFalse($fails);
        
        // Missing time
        $rule = new ValidEventDateTime(now()->addDays(1)->format('Y-m-d'), '', 24);
        $fails = false;
        
        $rule->validate('event_datetime', null, function ($message) use (&$fails) {
            $fails = true;
        });
        
        $this->assertFalse($fails);
    }

    /** @test */
    public function it_correctly_calculates_hours_until_event()
    {
        // Test with exactly the minimum hours
        $eventDate = now()->addHours(24)->format('Y-m-d');
        $startTime = now()->addHours(24)->format('H:i');
        
        $rule = new ValidEventDateTime($eventDate, $startTime, 24);
        $fails = false;
        
        $rule->validate('event_datetime', null, function ($message) use (&$fails) {
            $fails = true;
        });
        
        // Should pass as it's exactly 24 hours (or very close due to execution time)
        $this->assertFalse($fails);
    }

    /** @test */
    public function it_handles_cross_day_events()
    {
        // Event tomorrow at 10 AM
        $eventDate = now()->addDay()->format('Y-m-d');
        $startTime = '10:00';
        
        $rule = new ValidEventDateTime($eventDate, $startTime, 12);
        $fails = false;
        
        $rule->validate('event_datetime', null, function ($message) use (&$fails) {
            $fails = true;
        });
        
        $this->assertFalse($fails);
    }
}