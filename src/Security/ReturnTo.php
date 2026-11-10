<?php

namespace App\Security;

/**
 * v15 safe_return_to(): only same-site relative paths are followed after sign-in.
 */
final class ReturnTo
{
    public static function sanitize(mixed $path): ?string
    {
        $path = trim(\is_string($path) ? $path : '');

        if (
            '' === $path
            || !str_starts_with($path, '/')
            || str_starts_with($path, '//')
            || str_contains($path, '\\')
            // any control character: browsers silently drop tabs and newlines,
            // so "/\t/evil.com" would otherwise be followed as "//evil.com"
            || preg_match('/[\x00-\x1F\x7F]/', $path)
        ) {
            return null;
        }

        return $path;
    }
}
