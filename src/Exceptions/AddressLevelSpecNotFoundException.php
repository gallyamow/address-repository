<?php

declare(strict_types=1);

namespace Addresser\AddressRepository\Exceptions;

class AddressLevelSpecNotFoundException extends \RuntimeException implements Exception
{
    public static function withIdentifier(int $addressLevel, $identifier, string $message): self
    {
        return new static(
            \sprintf(
                'Failed to resolve spec for level "%s" and identifier "%s" ("%s").',
                $addressLevel,
                $identifier,
                $message
            )
        );
    }
}