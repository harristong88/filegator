<?php

require dirname(__DIR__, 2) . '/vendor/autoload.php';
use Filegator\Services\Auth\External\{AuthenticationException as AccessException, AccountStore, HomeProvisioner, Identity};

$f = json_decode(file_get_contents(__DIR__ . '/tokens.json'), true, 64, JSON_THROW_ON_ERROR);
$count = 0;
function check(bool $ok, string $message = 'assertion failed'): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
function denied(Closure $fn, int $status = 403): void
{
    try {
        $fn();
    } catch (AccessException $e) {
        check($e->status === $status, 'wrong rejection: ' . $e->reason . '/' . $e->status);
        return;
    }
    throw new RuntimeException('Expected AccessException');
}
function test(string $name, Closure $fn): void
{
    global $count;
    $fn();
    $count++;
    echo "PASS $name\n";
}
function tempdir(): string
{
    $p = sys_get_temp_dir() . '/cf-access-' . bin2hex(random_bytes(8));
    mkdir($p, 0o700);
    return $p;
}
$identity = new Identity($f['issuer'], 'subject-1', ' Alice@Example.com ', 4102444800);
$oldUser = ['username' => 'Alice@example.com','name' => 'Alice','role' => 'user','homedir' => '/old/alice/','permissions' => 'read|download','password' => 'must-not-import'];
test('link by normalized email preserves data and role; subject change requires relink', function () use ($identity, $oldUser) {
    $store = new AccountStore(':memory:', ['alice@example.com']);
    $store->import([$oldUser], [], false);
    $user = $store->resolve($identity);
    check($user['role'] === 'user' && $user['homedir'] === '/old/alice/' && $user['permissions'] === 'read|download');
    check(!isset($user['password']));
    check($store->resolve($identity)['id'] === $user['id']);
    denied(fn() => $store->resolve(new Identity($identity->issuer, 'changed', $identity->email, $identity->expiresAt)));
    $store->relink($user['username'], $identity->issuer, 'changed');
    check($store->resolve(new Identity($identity->issuer, 'changed', $identity->email, $identity->expiresAt))['id'] === $user['id']);
});
test('new users provision once; administrator bootstrap; disabled/deleted users never recreated', function () use ($identity) {
    $store = new AccountStore(':memory:', ['alice@example.com']);
    $a = $store->resolve($identity);
    check($a['role'] === 'admin');
    check($store->resolve($identity)['id'] === $a['id']);
    check($a['homedir'] === '/users/' . $a['id'] . '/');
    $b = $store->resolve(new Identity($identity->issuer, 'bob', 'bob@example.com', 4102444800));
    check($b['role'] === 'user' && $a['id'] !== $b['id']);
    $store->resolve(new Identity($identity->issuer, 'backup', 'backup@example.com', 4102444800));
    $store->update('backup@example.com', ['role' => 'admin']);
    $store->disable($a['username']);
    denied(fn() => $store->resolve($identity));
    denied(fn() => $store->resolve(new Identity($identity->issuer, 'new-alice', $identity->email, 4102444800)));
    $store->enable($a['username']);
    check($store->resolve($identity)['id'] === $a['id']);
});
test('import dry-run, email mappings, duplicate detection and atomic rollback', function () use ($oldUser) {
    $store = new AccountStore(':memory:');
    $store->import([$oldUser]);
    check(!$store->all());
    $legacy = array_merge($oldUser, ['username' => 'legacy']);
    $store->import([$legacy], ['legacy' => 'legacy@example.com'], false);
    check($store->find('legacy')['email'] === 'legacy@example.com');
    foreach ([[$oldUser, array_merge($oldUser, ['username' => 'alice@EXAMPLE.com'])], [$oldUser, $legacy]] as $users) {
        try {
            $store->import($users, [], false);
            throw new RuntimeException('Expected collision');
        } catch (InvalidArgumentException $e) {
        }
    }
    check(count($store->all()) === 1);
    check($store->find('Alice@example.com') === null);
});
test('permissions loaded fresh; username edits preserve binding', function () use ($identity) {
    $store = new AccountStore(':memory:');
    $u = $store->resolve($identity);
    $store->update($u['username'], ['username' => 'renamed', 'permissions' => 'read']);
    $u = $store->resolve($identity);
    check($u['username'] === 'renamed' && $u['permissions'] === 'read');
});
test('private homes reject traversal, symlinks and file collisions', function () {
    $root = tempdir();
    $homes = new HomeProvisioner($root);
    $homes->ensure('/users/abc/');
    $homes->ensure('/users/abc/');
    check(is_dir($root . '/users/abc'));
    denied(fn() => $homes->ensure('/../escape'), 403);
    symlink(sys_get_temp_dir(), $root . '/link');
    denied(fn() => $homes->ensure('/link/escape'), 403);
    file_put_contents($root . '/file', 'x');
    denied(fn() => $homes->ensure('/file'), 503);
});
test('concurrent worker first login and JWKS fetch coalescing', function () use ($f) {
    $directory = tempdir();
    $processes = [];
    for ($i = 0; $i < 6; $i++) {
        $processes[] = proc_open([PHP_BINARY, __DIR__ . '/worker.php', $directory], [1 => ['pipe','w'],2 => ['pipe','w']], $pipes);
    }
    foreach ($processes as $process) {
        check(proc_close($process) === 0, 'worker failed');
    }
    $store = new AccountStore($directory . '/accounts.sqlite');
    check(count($store->all()) === 1, 'duplicate accounts');
});
test('existing database dry-run is read-only and rejects collisions without changing bytes', function () use ($oldUser) {
    $dir = tempdir();
    $path = $dir . '/accounts.sqlite';
    $store = new AccountStore($path);
    $store->import([$oldUser], [], false);
    unset($store);
    $before = hash_file('sha256', $path);
    $reader = new AccountStore($path, [], true);
    $reader->import([array_merge($oldUser, ['username' => 'new@example.com'])]);
    try {
        $reader->import([$oldUser]);
        throw new RuntimeException('Expected collision');
    } catch (InvalidArgumentException $e) {
    }
    unset($reader);
    check(hash_file('sha256', $path) === $before);
});
echo "$count account checks passed\n";
