<?php

namespace Filegator\Services\Auth\External;

use CfAccess\AccessException;
use CfAccess\AccessVerifier;
use Filegator\Kernel\Request;
use Filegator\Services\Service;

final class CloudflareIdentityProvider implements IdentityProviderInterface, Service
{
    private AccessVerifier $verifier;
    public function init(array $config = [])
    {
        $this->verifier = new AccessVerifier($config);
    }
    public function authenticateRequest(Request $request): Identity
    {
        try {
            $identity = $this->verifier->authenticateRequest($request->headers->all());
            return new Identity($identity->issuer, $identity->subject, $identity->email, $identity->expiresAt);
        } catch (AccessException $error) {
            throw new AuthenticationException($error->reason, $error->status);
        }
    }
    public function label(): string
    {
        return 'Cloudflare Access';
    }
    public function logoutUrl(): string
    {
        return '/cdn-cgi/access/logout';
    }
}
