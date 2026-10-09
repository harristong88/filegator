<?php

namespace Filegator\Services\Auth\External;

final class Identity
{
    public function __construct(public readonly string $issuer, public readonly string $subject, public readonly string $email, public readonly int $expiresAt) {}
    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email), 'UTF-8');
    }
}
