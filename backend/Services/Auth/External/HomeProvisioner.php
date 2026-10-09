<?php

namespace Filegator\Services\Auth\External;

final class HomeProvisioner
{
    private string $root;
    public function __construct(string $root)
    {
        $real = realpath($root);
        if ($real === false || !is_dir($real)) {
            throw new \InvalidArgumentException('Storage root must exist');
        }
        $this->root = $real;
    }
    public function ensure(string $home): void
    {
        if (!str_starts_with($home, '/') || preg_match('#(^|/)\.\.?(/|$)|[\\\\\x00]#', $home)) {
            throw new AuthenticationException('INVALID_HOME', 403);
        }
        $current = $this->root;
        foreach (array_filter(explode('/', $home), static fn($s) => $s !== '') as $part) {
            $current .= '/' . $part;
            if (is_link($current)) {
                throw new AuthenticationException('UNSAFE_HOME', 403);
            }
            if (!is_dir($current) && !@mkdir($current, 0o700) && !is_dir($current)) {
                throw new AuthenticationException('HOME_UNAVAILABLE', 503);
            }
            $real = realpath($current);
            if ($real === false || !str_starts_with($real, $this->root . '/')) {
                throw new AuthenticationException('UNSAFE_HOME', 403);
            }
        }
    }
}
