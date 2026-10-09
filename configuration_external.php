<?php

// Selected by FILEGATOR_AUTH_MODE=external. No local-password fallback.
$config = require __DIR__ . '/configuration_sample.php';
$required = static function (string $name): string {
    $value = trim(getenv($name) ?: '');
    if ($value === '') {
        throw new RuntimeException('Required setting missing: ' . $name);
    }
    return $value;
};
$admins = array_map('trim', explode(',', $required('CF_ADMIN_EMAILS')));
foreach ($admins as $email) {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('CF_ADMIN_EMAILS must contain valid emails');
    }
}
$root = getenv('FILEGATOR_STORAGE_ROOT') ?: __DIR__ . '/repository';
$private = getenv('FILEGATOR_PRIVATE_ROOT') ?: __DIR__ . '/private';
foreach ([$private, $private . '/cf-access-cache', $private . '/sessions', $private . '/tmp'] as $directory) {
    if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
        throw new RuntimeException('Cannot create private directory');
    }
}
$config['services']['Filegator\Services\Session\SessionStorageInterface']['config']['handler'] = static function () use ($private) {
    return new Symfony\Component\HttpFoundation\Session\Storage\NativeSessionStorage([
        'cookie_samesite' => 'Lax', 'cookie_secure' => true, 'cookie_httponly' => true,
    ], new Symfony\Component\HttpFoundation\Session\Storage\Handler\NativeFileSessionHandler($private . '/sessions'));
};
$config['services']['Filegator\Services\Tmpfs\TmpfsInterface']['config']['path'] = $private . '/tmp';
$config['services']['Filegator\Services\Storage\Filesystem']['config']['adapter'] = static fn() => new League\Flysystem\Adapter\Local($root);
$config['services']['Filegator\Services\Security\Security']['config']['csrf_key'] = $required('CF_CSRF_KEY');
$external = [
    'Filegator\Services\Auth\External\IdentityProviderInterface' => [
        'handler' => '\\Filegator\Services\Auth\External\CloudflareIdentityProvider',
        'config' => ['teamDomain' => $required('CF_TEAM_DOMAIN'), 'audience' => $required('CF_ACCESS_AUD'),
            'cacheDirectory' => $private . '/cf-access-cache'],
    ],
    'Filegator\Services\Auth\External\AccountRepositoryInterface' => [
        'handler' => '\\Filegator\Services\Auth\External\LocalAccounts',
        'config' => ['database' => $private . '/accounts.sqlite', 'storageRoot' => $root, 'adminEmails' => $admins],
    ],
    'Filegator\Services\Auth\AuthInterface' => ['handler' => '\\Filegator\Services\Auth\Adapters\ExternalIdentity'],
];
$services = [];
foreach ($config['services'] as $key => $service) {
    if ($key === 'Filegator\Services\Auth\AuthInterface') {
        continue;
    }
    $services[$key] = $service;
    if ($key === 'Filegator\Services\Session\SessionStorageInterface') {
        $services += $external;
    }
}
$config['services'] = $services;
return $config;
