<?php

declare(strict_types=1);

namespace Tenant\Laravel\Internal\Queue;

use Illuminate\Contracts\Config\Repository;
use Packman\Attributes\Internal;

#[Internal]
final class QueueConfiguration
{
    /** @return array<string, string|false> */
    public static function mismatches(Repository $config, string $centralConnection, bool $databaseCommitOnly = false): array
    {
        $expected = ['queue.connections.database.after_commit' => false];

        if (! $databaseCommitOnly) {
            $expected += [
                'queue.failed.database' => $centralConnection,
                'queue.connections.database.driver' => 'database',
                'queue.connections.database.connection' => $centralConnection,
                'queue.connections.sync.after_commit' => false,
            ];
        }

        return array_filter(
            $expected,
            static fn (string|false $value, string $key): bool => $config->get($key) !== $value,
            ARRAY_FILTER_USE_BOTH,
        );
    }
}
