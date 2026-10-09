<?php

namespace Filegator\Services\Auth\External;

use Filegator\Kernel\Request;

interface IdentityProviderInterface
{
    public function authenticateRequest(Request $request): Identity;
    public function label(): string;
    public function logoutUrl(): string;
}
