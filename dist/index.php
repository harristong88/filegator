<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);

if (version_compare(PHP_VERSION, '8.1', '<')) {
    echo 'Minimum requirement is PHP 8.1 You are using: ' . PHP_VERSION . "\n";
    die;
}

if (! is_writable(__DIR__ . '/../private/logs/')) {
    echo 'Folder not writable: /private/logs/' . "\n";
    die;
}

$externalAuth = (getenv('FILEGATOR_AUTH_MODE') ?: 'local') === 'external';
if (! $externalAuth && ! file_exists(__DIR__ . '/../configuration.php')) {
    copy(__DIR__ . '/../configuration_sample.php', __DIR__ . '/../configuration.php');
}

require __DIR__ . '/../vendor/autoload.php';

if (! defined('APP_ENV')) {
    define('APP_ENV', getenv('FILEGATOR_ENV') === 'development' ? 'development' : 'production');
}

if (! defined('APP_PUBLIC_PATH')) {
    define('APP_PUBLIC_PATH', '');
}

define('APP_PUBLIC_DIR', __DIR__);
define('APP_VERSION', '7.16.5');

use Filegator\App;
use Filegator\Config\Config;
use Filegator\Container\Container;
use Filegator\Kernel\Request;
use Filegator\Kernel\Response;
use Filegator\Kernel\StreamedResponse;

try {
    $mode = getenv('FILEGATOR_AUTH_MODE') ?: 'local';
    if (!in_array($mode, ['local', 'external'], true)) throw new RuntimeException('Invalid FILEGATOR_AUTH_MODE');
    $config = require __DIR__ . ($externalAuth ? '/../configuration_external.php' : '/../configuration.php');
} catch (Throwable $error) {
    error_log('FileGator configuration unavailable');
    http_response_code(503);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(['data' => 'Application configuration unavailable']);
    exit;
}

new App(new Config($config), Request::createFromGlobals(), new Response(), new StreamedResponse(), new Container());
