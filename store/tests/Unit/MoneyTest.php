<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_indian_grouping(): void
    {
        $this->assertSame('₹499', money(499));
        $this->assertSame('₹1,299', money(1299));
        $this->assertSame('₹12,34,567.50', money(1234567.5));
        $this->assertSame('₹54.80', money(54.8));
    }
}
