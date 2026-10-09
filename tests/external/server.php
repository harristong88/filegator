<?php

// Test-only fixture transport. Bind to localhost; never deploy this router.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
$root = dirname(__DIR__, 2);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && is_file($root . '/dist' . $path)) {
    return false;
}
require $root . '/vendor/autoload.php';
define('APP_ENV', 'test');
define('APP_PUBLIC_PATH', '');
define('APP_PUBLIC_DIR', $root . '/dist');
define('APP_VERSION', 'test');
$config = require $root . '/configuration_external.php';
$config['services']['Filegator\Services\Auth\External\IdentityProviderInterface']['config']['fetch'] = static fn() => json_decode(file_get_contents(__DIR__ . '/tokens.json'), true)['jwks'];
$config['services']['Filegator\Services\Session\SessionStorageInterface']['config']['handler'] = static fn() => new Symfony\Component\HttpFoundation\Session\Storage\NativeSessionStorage(['cookie_secure' => false,'cookie_httponly' => true,'cookie_samesite' => 'Lax']);
new Filegator\App(new Filegator\Config\Config($config), Filegator\Kernel\Request::createFromGlobals(), new Filegator\Kernel\Response(), new Filegator\Kernel\StreamedResponse(), new Filegator\Container\Container());
