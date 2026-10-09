<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Filegator;

use Filegator\Config\Config;
use Filegator\Services\Auth\External\AuthenticationException;
use Filegator\Services\Auth\External\IdentityProviderInterface;
use Filegator\Services\Auth\External\AccountRepositoryInterface;
use Filegator\Services\Auth\AuthInterface;
use Filegator\Services\Session\SessionStorageInterface;
use Filegator\Services\Security\Security;
use Filegator\Services\Router\Router;
use Filegator\Container\Container;
use Filegator\Kernel\Request;
use Filegator\Kernel\Response;
use Filegator\Kernel\StreamedResponse;

class App
{
    private $container;

    public function __construct(Config $config, Request $request, Response $response, StreamedResponse $sresponse, Container $container)
    {
        $container->set(Config::class, $config);
        $container->set(Container::class, $container);
        $container->set(Request::class, $request);
        $container->set(Response::class, $response);
        $container->set(StreamedResponse::class, $sresponse);

        $apiRequest = $request->query->has('r') || $request->getContentType() === 'json';
        try {
            $services = $config->get('services', []);
            if (isset($services[IdentityProviderInterface::class])) {
                $response->headers->set('Cache-Control', 'no-store');
                $order = array_flip(array_keys($services));
                $previous = -1;
                foreach ([SessionStorageInterface::class, IdentityProviderInterface::class, AccountRepositoryInterface::class, AuthInterface::class, Security::class, Router::class] as $key) {
                    if (!isset($order[$key]) || $order[$key] <= $previous) {
                        throw new \LogicException('External authentication services must initialize before security and routing');
                    }
                    $previous = $order[$key];
                }
            }
            foreach ($config->get('services', []) as $key => $service) {
                $container->set($key, $container->get($service['handler']));
                $container->get($key)->init($service['config'] ?? []);
            }
        } catch (AuthenticationException $e) {
            $response->headers->set('Cache-Control', 'no-store');
            error_log(json_encode(['code' => $e->reason, 'status' => $e->status]));
            if ($apiRequest) {
                $response->json($e->getMessage(), $e->status);
            } else {
                $message = [401 => 'Your identity could not be verified. Sign in again.', 403 => 'Your local account is unavailable. Contact the administrator.', 503 => 'Authentication is temporarily unavailable. Try again shortly.'][$e->status] ?? 'Access unavailable.';
                $provider = $container->has(IdentityProviderInterface::class) ? $container->get(IdentityProviderInterface::class) : null;
                $logout = $provider ? '<p><a href="' . htmlspecialchars($provider->logoutUrl(), ENT_QUOTES, 'UTF-8') . '">Sign out</a></p>' : '';
                $response->html('<!doctype html><html lang="en"><meta charset="utf-8"><title>Access unavailable</title><h1>Access unavailable</h1><p>' . $message . '</p><a href="">Retry</a>' . $logout . '</html>', $e->status);
            }
            $response->send();
            $this->container = $container;
            return;
        } catch (\Throwable $e) {

            if (headers_sent()) {
                throw $e; // re-throw exceptions for PHPUnit
            }

            // unhandled exceptions should be logged to stdout and shown as generic internal server errors
            error_log($e);
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Internal Server Error']);
            die;
        }

        $response->send();

        $this->container = $container;

    }

    public function resolve($name)
    {
        return $this->container->get($name);
    }
}
