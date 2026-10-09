<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Filegator\Controllers;

use Filegator\Config\Config;
use Filegator\Services\Auth\AuthInterface;
use Filegator\Services\Auth\AuthCapabilities;
use Filegator\Kernel\Response;
use Filegator\Services\View\ViewInterface;

class ViewController
{
    public function index(Response $response, ViewInterface $view)
    {
        return $response->html($view->getIndexPage());
    }

    public function getFrontendConfig(Response $response, Config $config, AuthInterface $auth)
    {
        return $response->json(array_replace($config->get('frontend_config'), ['auth' => AuthCapabilities::for($auth)]));
    }
}
