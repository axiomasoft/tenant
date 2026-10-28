<?php

declare(strict_types=1);
namespace Tenant\Postgres\Internal;

/** Same compact sorted UTF8 representation as the P4.1 provisioning intent. */
final class CanonicalIntent
{
    public static function reference(string $value): void
    {
        if ($value === '' || strlen($value) > 128 || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $value) !== 1 || str_contains($value, '://')) throw new \InvalidArgumentException('Invalid safe reference.');
    }
    public static function digest(array $fields): string
    {
        return hash('sha256', json_encode(self::sort($fields), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    private static function sort(mixed $value): mixed
    {
        if (is_array($value)) {
            if (!array_is_list($value)) ksort($value, SORT_STRING);
            return array_map(self::sort(...), $value);
        }
        if (is_string($value) || is_int($value) || is_bool($value) || $value === null) return $value;
        throw new \InvalidArgumentException('Unsupported canonical value.');
    }
}
