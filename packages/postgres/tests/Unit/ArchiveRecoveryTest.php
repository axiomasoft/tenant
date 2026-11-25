<?php

declare(strict_types=1);

use Tenant\Postgres\ArchiveManifest;
use Tenant\Postgres\PgDumpArchiveStore;

it('never recovers a different export through a substituted manifest', function (bool $symlink): void {
    $directory = sys_get_temp_dir().'/tenant-archive-recovery-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $requestedExport = '11111111-1111-4111-8111-111111111111';
    $storedExport = '22222222-2222-4222-8222-222222222222';
    $bytes = 'original archived bytes';
    $manifest = new ArchiveManifest(
        $storedExport,
        'operation:original',
        'resource:original',
        'incarnation:1',
        'route:1',
        'generation:1',
        'archive:private',
        hash('sha256', $bytes),
        strlen($bytes),
    );
    $store = new PgDumpArchiveStore($directory, 'archive:private', static fn (object $row): never => throw new RuntimeException('Recovery must perform no database IO.'), '/unavailable/pg_dump');
    try {
        file_put_contents($directory.'/'.$storedExport.'.dump', $bytes);
        file_put_contents($directory.'/'.$storedExport.'.dump.manifest', json_encode(get_object_vars($manifest), JSON_THROW_ON_ERROR));
        if ($symlink) {
            symlink($directory.'/'.$storedExport.'.dump.manifest', $directory.'/'.$requestedExport.'.dump.manifest');
        } else {
            copy($directory.'/'.$storedExport.'.dump.manifest', $directory.'/'.$requestedExport.'.dump.manifest');
        }

        expect($store->recover($storedExport))->toEqual($manifest)
            ->and($store->recover($requestedExport))->toBeNull();
    } finally {
        foreach (glob($directory.'/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($directory);
    }
})->with([false, true]);
