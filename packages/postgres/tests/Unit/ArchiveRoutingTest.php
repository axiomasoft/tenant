<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Tenant\Postgres\Contracts\Provisioning\ResourceBindings;
use Tenant\Postgres\FileRecoveryAnchor;
use Tenant\Postgres\PgDumpArchiveStore;
use Tenant\Postgres\PostgresFactory;

it('preserves explicit TLS requirements when starting the native archive command', function (): void {
    $directory = sys_get_temp_dir().'/tenant-archive-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $executable = $directory.'/dump-environment';
    file_put_contents($executable, '#!'.PHP_BINARY."\n<?php\necho json_encode(getenv(), JSON_THROW_ON_ERROR);\n");
    chmod($executable, 0700);
    $route = [
        'driver' => 'pgsql',
        'database' => 'archive_test',
        'host' => 'database.invalid',
        'port' => 5432,
        'username' => 'archive_user',
        'password' => 'test-password',
        'sslmode' => 'verify-full',
        'sslcert' => '/private/client.pem',
        'sslkey' => '/private/client.key',
        'sslrootcert' => '/private/ca.pem',
    ];
    $resource = (object) [
        'database_name' => 'archive_test',
        'resource_ref' => 'resource:archive',
        'incarnation' => 'incarnation:1',
        'route_revision' => 'route:1',
        'recovery_generation' => 'generation:1',
    ];
    $store = new PgDumpArchiveStore($directory, 'archive:private', static fn (object $row): array => $route, $executable);
    $exportId = '11111111-1111-4111-8111-111111111111';
    try {
        $manifest = $store->write($resource, 'operation:archive', $exportId, 'archive:private');
        $environment = json_decode(file_get_contents($directory.'/'.$exportId.'.dump'), true, flags: JSON_THROW_ON_ERROR);
        expect($store->verify($manifest))->toBeTrue()
            ->and($environment['PGSSLMODE'] ?? null)->toBe('verify-full')
            ->and($environment['PGSSLCERT'] ?? null)->toBe('/private/client.pem')
            ->and($environment['PGSSLKEY'] ?? null)->toBe('/private/client.key')
            ->and($environment['PGSSLROOTCERT'] ?? null)->toBe('/private/ca.pem');
    } finally {
        foreach (glob($directory.'/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($directory);
    }
});

it('refuses connector overrides on the owner catalog before any connection or anchor access', function (string $override, mixed $value): void {
    $catalog = new Connection(static fn (): never => throw new RuntimeException('No catalog connection expected.'), 'catalog_test', '', [
        'driver' => 'pgsql',
        'database' => 'catalog_test',
        $override => $value,
    ]);
    expect(fn (): ResourceBindings => PostgresFactory::bindings(
        $catalog,
        static fn (object $row): never => throw new RuntimeException('No runtime route expected.'),
        new FileRecoveryAnchor('/unavailable/anchor.json'),
    ))->toThrow(InvalidArgumentException::class, 'Owner catalog requires a direct PostgreSQL route.');
})->with([
    ['url', 'pgsql://alternate.invalid/database'],
    ['read', ['host' => 'alternate.invalid']],
    ['write', ['host' => 'alternate.invalid']],
    ['connect_via_database', 'alternate_test'],
    ['connect_via_port', 5433],
]);
