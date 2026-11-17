<?php

declare(strict_types=1);

namespace Tenant\Postgres\Internal\Database;

use Packman\Attributes\Internal;

/** The owner profile pins one database and server without connector overrides. */
#[Internal]
final class PostgresRoute
{
    /** @param array<string, mixed> $route */
    public static function isDirect(array $route): bool
    {
        if (($route['driver'] ?? null) !== 'pgsql') {
            return false;
        }

        return array_all(['url', 'read', 'write', 'connect_via_database', 'connect_via_port'], fn (string $override): bool => ! isset($route[$override]));
    }
}
