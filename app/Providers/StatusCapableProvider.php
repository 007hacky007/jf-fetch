<?php

declare(strict_types=1);

namespace App\Providers;

/**
 * Optional extension interface for providers that can report status / subscription info.
 */
interface StatusCapableProvider
{
    /**
     * @return array<string,mixed>
     */
    public function status(): array;
}
