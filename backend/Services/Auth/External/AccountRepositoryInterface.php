<?php

namespace Filegator\Services\Auth\External;

interface AccountRepositoryInterface
{
    public function resolve(Identity $identity): array;
    public function find(string $username): ?array;
    public function all(): array;
    public function add(array $user): array;
    public function update(string $username, array $user): array;
    public function disable(string $username): void;
}
