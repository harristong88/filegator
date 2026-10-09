<?php

namespace Filegator\Services\Auth\External;

use Filegator\Services\Service;

final class LocalAccounts implements AccountRepositoryInterface, Service
{
    private AccountStore $store;
    private HomeProvisioner $homes;
    public function init(array $config = [])
    {
        foreach ([$config['database'] ?? '', $config['storageRoot'] ?? ''] as $path) {
            if (!$path) {
                throw new \InvalidArgumentException('Account database and storage root are required');
            }
        }
        $directory = dirname($config['database']);
        if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Cannot create account directory');
        }
        $this->store = new AccountStore($config['database'], $config['adminEmails'] ?? []);
        $this->homes = new HomeProvisioner($config['storageRoot']);
    }
    public function resolve(Identity $identity): array
    {
        $user = $this->store->resolve($identity);
        $this->homes->ensure($user['homedir']);
        return $user;
    }
    public function find(string $username): ?array
    {
        return $this->store->find($username);
    }
    public function all(): array
    {
        return $this->store->all();
    }
    public function add(array $user): array
    {
        $this->homes->ensure($user['homedir']);
        return $this->store->add($user);
    }
    public function update(string $username, array $user): array
    {
        $this->homes->ensure($user['homedir']);
        return $this->store->update($username, $user);
    }
    public function disable(string $username): void
    {
        $this->store->disable($username);
    }
}
