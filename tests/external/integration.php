<?php

require __DIR__ . '/accounts.php';
use Filegator\Services\Auth\External\{CloudflareIdentityProvider, LocalAccounts};
use Filegator\Services\Auth\Adapters\ExternalIdentity;
use Filegator\Kernel\Request;
use Filegator\Services\Session\SessionStorageInterface;

final class TestSession implements SessionStorageInterface
{
    public array $values = [];
    public int $invalidations = 0;
    public function set(string $key, $data)
    {
        $this->values[$key] = $data;
    }
    public function get(string $key, $default = null)
    {
        return $this->values[$key] ?? $default;
    }
    public function invalidate()
    {
        $this->values = [];
        $this->invalidations++;
    }
    public function save() {}
    public function migrate($destroy = false, $lifetime = null): bool
    {
        return true;
    }
}
test('real FileGator interface: sessions, users, permission refresh, password rejection and administration', function () use ($f) {
    $directory = tempdir();
    $session = new TestSession();
    $session->values = ['batch_download_archives' => ['old'], 'oldcsrf' => 'old'];
    $config = ['teamDomain' => $f['issuer'], 'audience' => $f['audience']] + ['cacheDirectory' => $directory,'database' => $directory . '/accounts.sqlite','storageRoot' => $directory,
        'adminEmails' => ['alice@example.com'], 'fetch' => static fn() => $f['jwks']];
    $request = new Request();
    $request->headers->set('Cf-Access-Jwt-Assertion', $f['cases']['valid']['token']);
    $provider = new CloudflareIdentityProvider();
    $provider->init($config);
    $accounts = new LocalAccounts();
    $accounts->init($config);
    $adapter = new ExternalIdentity($request, $session, $provider, $accounts);
    $adapter->init($config);
    check($adapter->user()->isAdmin());
    check($session->invalidations === 1);
    check(!isset($session->values['batch_download_archives']));
    $adapter->user();
    check($session->invalidations === 1);
    check(!$adapter->authenticate('Alice@example.com', 'anything'));
    denied(fn() => $adapter->getGuest());
    check(!$adapter->capabilities()["passwordLogin"] && !$adapter->capabilities()["passwordChanges"]);
    $user = $adapter->user();
    denied(fn() => $adapter->update($user->getUsername(), $user, 'password'));
    $user->setPermissions(['read']);
    $adapter->update($user->getUsername(), $user);
    check($adapter->user()->getPermissions() === ['read']);
    $request2 = new Request();
    $request2->headers->set('Cf-Access-Jwt-Assertion', $f['cases']['bob']['token']);
    $bob = new ExternalIdentity($request2, $session, $provider, $accounts);
    $bob->init($config);
    check($session->invalidations === 3);
    check(!$bob->user()->isAdmin());
    denied(fn() => $bob->allUsers());
    $bob->forget();
    check($session->invalidations === 4);
    $backup = new Filegator\Services\Auth\User();
    $backup->setUsername("backup@example.com");
    $backup->setName("Backup");
    $backup->setRole("admin");
    $backup->setHomedir("/backup/");
    $backup->setPermissions(["read"]);
    $adapter->add($backup, "");
    $adapter->delete($adapter->user());
    denied(fn() => $adapter->user());
});
test('last active administrator cannot be disabled or demoted', function () use ($f) {
    $store = new Filegator\Services\Auth\External\AccountStore(':memory:', ['alice@example.com']);
    $identity = new Filegator\Services\Auth\External\Identity($f['issuer'], 'last-admin', 'alice@example.com', 4102444800);
    $admin = $store->resolve($identity);
    foreach ([fn() => $store->disable($admin['username']), fn() => $store->update($admin['username'], ['role' => 'user'])] as $operation) {
        try {
            $operation();
            throw new RuntimeException('Expected last-admin rejection');
        } catch (InvalidArgumentException $expected) {
        }
    }
    check($store->resolve($identity)['role'] === 'admin');
});
test('another identity provider plugs into the same FileGator account adapter', function () {
    $provider = new class implements Filegator\Services\Auth\External\IdentityProviderInterface {
        public function authenticateRequest(Filegator\Kernel\Request $request): Filegator\Services\Auth\External\Identity
        {
            return new Filegator\Services\Auth\External\Identity('https://other.example', 'subject', 'other@example.com', 4102444800);
        }
        public function label(): string
        {
            return 'Other identity';
        }
        public function logoutUrl(): string
        {
            return '/identity/logout';
        }
    };
    $directory = tempdir();
    $accounts = new LocalAccounts();
    $accounts->init(['database' => $directory . '/accounts.sqlite', 'storageRoot' => $directory]);
    $adapter = new ExternalIdentity(new Request(), new TestSession(), $provider, $accounts);
    $adapter->init();
    check($adapter->user()->getUsername() === 'other@example.com');
    check($adapter->capabilities()['provider'] === 'Other identity' && $adapter->capabilities()['logoutUrl'] === '/identity/logout');
});
test('application CLI dry-run, import, disable, enable, and relink', function () use ($oldUser) {
    $directory = tempdir();
    $database = $directory . '/accounts.sqlite';
    $source = $directory . '/users.json';
    $map = $directory . '/map.json';
    file_put_contents($source, json_encode([array_replace($oldUser, ['username' => 'legacy-admin', 'role' => 'admin'])]));
    file_put_contents($map, json_encode(['legacy-admin' => 'owner@example.com']));
    $run = static function (string $command, array $arguments): array {
        $process = proc_open(array_merge([PHP_BINARY, dirname(__DIR__, 2) . '/bin/' . $command], $arguments), [1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $stdout, $stderr];
    };
    $args = ['--source=' . $source, '--email-map=' . $map, '--database=' . $database];
    check($run('import-users', $args)[0] === 0 && !file_exists($database));
    check($run('import-users', array_merge($args, ['--apply']))[0] === 0);
    $before = hash_file('sha256', $database);
    check($run('import-users', $args)[0] === 1 && hash_file('sha256', $database) === $before);
    check($run('account', ['--database=' . $database, '--username=legacy-admin', '--disable'])[0] === 1);
    $store = new Filegator\Services\Auth\External\AccountStore($database);
    $store->add(array_replace($oldUser, ['username' => 'backup@example.com', 'role' => 'admin']));
    check($run('account', ['--database=' . $database, '--username=legacy-admin', '--disable'])[0] === 0);
    check($run('account', ['--database=' . $database, '--username=legacy-admin', '--enable'])[0] === 0);
    check($run('account', ['--database=' . $database, '--username=legacy-admin', '--relink', '--issuer=https://other.example', '--subject=verified'])[0] === 0);
    check($store->resolve(new Filegator\Services\Auth\External\Identity('https://other.example', 'verified', 'owner@example.com', 4102444800))['username'] === 'legacy-admin');
});
echo "$count external authentication checks passed\n";
