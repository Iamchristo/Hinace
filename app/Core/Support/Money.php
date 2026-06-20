<?php

declare(strict_types=1);

namespace App\Core\Support;

/**
 * Fixed-scale decimal money value object backed by bcmath. Never use float
 * for currency math - DECIMAL(20,8) columns round-trip through this class.
 */
final class Money
{
    private const SCALE = 8;

    private readonly string $amount;

    private function __construct(string $amount)
    {
        $this->amount = bcadd($amount, '0', self::SCALE);
    }

    public static function fromString(string $amount): self
    {
        return new self($amount);
    }

    public static function zero(): self
    {
        return new self('0');
    }

    public function add(Money $other): self
    {
        return new self(bcadd($this->amount, $other->amount, self::SCALE));
    }

    public function subtract(Money $other): self
    {
        return new self(bcsub($this->amount, $other->amount, self::SCALE));
    }

    public function isPositive(): bool
    {
        return bccomp($this->amount, '0', self::SCALE) > 0;
    }

    public function isNegative(): bool
    {
        return bccomp($this->amount, '0', self::SCALE) < 0;
    }

    public function isZero(): bool
    {
        return bccomp($this->amount, '0', self::SCALE) === 0;
    }

    public function greaterThan(Money $other): bool
    {
        return bccomp($this->amount, $other->amount, self::SCALE) > 0;
    }

    public function greaterThanOrEqual(Money $other): bool
    {
        return bccomp($this->amount, $other->amount, self::SCALE) >= 0;
    }

    public function equals(Money $other): bool
    {
        return bccomp($this->amount, $other->amount, self::SCALE) === 0;
    }

    public function toString(): string
    {
        return $this->amount;
    }

    public function __toString(): string
    {
        return $this->amount;
    }
}
