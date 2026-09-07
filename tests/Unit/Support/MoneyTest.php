<?php

namespace Tests\Unit\Support;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_it_is_integer_cents_in_aud(): void
    {
        $this->assertSame(450, Money::fromDecimal('4.50')->cents);
        $this->assertSame(123060, Money::fromDecimal(1230.6)->cents);
        $this->assertSame(0, Money::zero()->cents);
        $this->assertSame('AUD', Money::CURRENCY);
        $this->assertSame(['cents' => 450, 'currency' => 'AUD'], Money::cents(450)->jsonSerialize());
    }

    public function test_arithmetic_rounds_half_up_to_the_cent(): void
    {
        $rate = Money::cents(450);

        $this->assertSame(1350, $rate->multiply(3)->cents);
        $this->assertSame(675, $rate->multiply(1.5)->cents);
        $this->assertSame(1000, Money::cents(10000)->percent(10)->cents);
        $this->assertSame(500, Money::cents(450)->add(Money::cents(50))->cents);
        $this->assertSame(-50, Money::cents(450)->subtract(Money::cents(500))->cents);
        $this->assertSame(500, Money::cents(450)->max(Money::cents(500))->cents);
        $this->assertTrue(Money::cents(-1)->isNegative());
        $this->assertTrue(Money::cents(7)->equals(Money::cents(7)));
    }

    public function test_it_formats_for_blade_and_exports(): void
    {
        $this->assertSame('$1,230.60', Money::cents(123060)->format());
        $this->assertSame('-$4.50', (string) Money::cents(-450));
        $this->assertSame('4.50', Money::cents(450)->toDecimal());
    }

    public function test_it_rejects_non_numeric_input(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::fromDecimal('four dollars');
    }
}
