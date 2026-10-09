<?php

require dirname(__DIR__, 2) . '/vendor/autoload.php';
use Filegator\Services\Auth\External\{Identity, AccountStore};

(new AccountStore($argv[1] . '/accounts.sqlite'))->resolve(new Identity('https://fixture.cloudflareaccess.com', 'worker', 'worker@example.com', 4102444800));
