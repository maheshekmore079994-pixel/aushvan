<?php

namespace App\Domain\Shared;

final readonly class TaxBreakdown
{
    public function __construct(
        public int $taxablePaise,
        public int $cgstPaise,
        public int $sgstPaise,
        public int $igstPaise,
        public int $cessPaise,
    ) {}

    public function taxPaise(): int
    {
        return $this->cgstPaise + $this->sgstPaise + $this->igstPaise + $this->cessPaise;
    }

    public function totalPaise(): int
    {
        return $this->taxablePaise + $this->taxPaise();
    }
}
