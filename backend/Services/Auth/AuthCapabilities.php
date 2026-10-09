<?php

namespace Filegator\Services\Auth;

final class AuthCapabilities
{
    public static function for(AuthInterface $auth): array
    {
        return array_replace(
            ['externalIdentity' => false, 'passwordLogin' => true, 'passwordChanges' => true, 'guests' => true,
                'accountManagement' => true, 'provider' => 'Local account', 'logoutUrl' => ''],
            $auth instanceof AuthCapabilitiesInterface ? $auth->capabilities() : []
        );
    }
}
