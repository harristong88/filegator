<?php

namespace Filegator\Services\Auth\External;

final class AuthenticationException extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly int $status = 401)
    {
        parent::__construct(match ($status) {
            403 => 'Forbidden', 503 => 'Authentication unavailable', default => 'Unauthorized'
        });
    }
}
