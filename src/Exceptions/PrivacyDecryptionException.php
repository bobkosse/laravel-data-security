<?php

declare(strict_types=1);

namespace BobKosse\DataSecurity\Exceptions;

use RuntimeException;
use Throwable;

class PrivacyDecryptionException extends RuntimeException
{
    /**
     * Create a new PrivacyDecryptionException instance.
     */
    public static function forAttribute(string $attribute, string $modelClass, ?Throwable $previous = null): self
    {
        return new self(
            sprintf(
                'Failed to decrypt privacy attribute "%s" on model "%s".',
                $attribute,
                $modelClass
            ),
            0,
            $previous
        );
    }
}
