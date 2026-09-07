<?php

namespace App\Support;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * Money value object: integer cents, AUD only, immutable (ERP_PLAN §0.2 rule 7).
 * Never store or compute money as floats; convert at the edges with fromDecimal() / toDecimal().
 */
final class Money implements JsonSerializable, Stringable
{
    public const CURRENCY = 'AUD';

    private function __construct(public readonly int $cents) {}

    public static function cents(int $cents): self
    {
        return new self($cents);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /** From a decimal amount such as "4.50" or 1230.6 — rounds half up to the cent. */
    public static function fromDecimal(string|float|int $amount): self
    {
        if (is_string($amount) && ! is_numeric(trim($amount))) {
            throw new InvalidArgumentException("Not a numeric amount: {$amount}");
        }

        return new self((int) round(((float) $amount) * 100, 0, PHP_ROUND_HALF_UP));
    }

    public function add(Money $other): self
    {
        return new self($this->cents + $other->cents);
    }

    public function subtract(Money $other): self
    {
        return new self($this->cents - $other->cents);
    }

    /** Multiply by a quantity or factor; rounds half up to the cent. */
    public function multiply(int|float $factor): self
    {
        return new self((int) round($this->cents * $factor, 0, PHP_ROUND_HALF_UP));
    }

    /** e.g. percent(10) on $100.00 → $10.00 (GST, markup). */
    public function percent(float $percent): self
    {
        return $this->multiply($percent / 100);
    }

    public function max(Money $other): self
    {
        return $this->cents >= $other->cents ? $this : $other;
    }

    public function isZero(): bool
    {
        return $this->cents === 0;
    }

    public function isNegative(): bool
    {
        return $this->cents < 0;
    }

    public function equals(Money $other): bool
    {
        return $this->cents === $other->cents;
    }

    /** "4.50" — for exports and tests. */
    public function toDecimal(): string
    {
        return number_format($this->cents / 100, 2, '.', '');
    }

    /** "$1,230.60" / "-$4.50" — for Blade. */
    public function format(): string
    {
        return ($this->cents < 0 ? '-' : '').'$'.number_format(abs($this->cents) / 100, 2);
    }

    public function __toString(): string
    {
        return $this->format();
    }

    /** @return array{cents:int, currency:string} */
    public function jsonSerialize(): array
    {
        return ['cents' => $this->cents, 'currency' => self::CURRENCY];
    }
}
