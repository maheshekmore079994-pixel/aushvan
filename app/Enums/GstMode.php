<?php

namespace App\Enums;

/** How the shop stands under GST. Decides bill title, tax display and statutory wording. */
enum GstMode: string
{
    case Unregistered = 'unregistered';
    case Composition = 'composition';
    case Regular = 'regular';

    public function requiresGstin(): bool
    {
        return $this !== self::Unregistered;
    }

    public function showsTaxOnBill(): bool
    {
        return $this === self::Regular;
    }
}
