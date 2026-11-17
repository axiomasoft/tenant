<?php

declare(strict_types=1);

require __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/../../packages/tenant-postgres/src/Exceptions/TemplateIntegrityException.php';
require __DIR__.'/../../packages/tenant-postgres/src/Internal/DatabaseValue.php';
require __DIR__.'/../../packages/tenant-postgres/src/Internal/Database/SchemaCatalogueSerializer.php';
require __DIR__.'/../../packages/tenant-postgres/src/Internal/Database/TemplateManifest.php';

use Tenant\Postgres\Exceptions\TemplateIntegrityException;
use Tenant\Postgres\Internal\Database\SchemaCatalogueSerializer;
use Tenant\Postgres\Internal\Database\TemplateManifest;

$serializer = new SchemaCatalogueSerializer;
$pair = sodium_crypto_sign_keypair();
$public = sodium_crypto_sign_publickey($pair);
$secret = sodium_crypto_sign_secretkey($pair);
$payload = ['template_version' => 1, 'template_id' => 't', 'canonical_schema_sha256' => str_repeat('a', 64)];
$encode = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
$signature = sodium_crypto_sign_detached("tenant-template-manifest/v1\0".$serializer->encode($payload), $secret);
$manifest = TemplateManifest::fromConfig(['payload' => $payload, 'signature' => $encode($signature), 'trusted_key_id' => 'k']);
$manifest->assertTrusted(['k' => $encode($public)], $serializer);
$manifest->assertMatches((object) ['id' => 't', 'version' => 1, 'schema_hash' => str_repeat('a', 64)], str_repeat('a', 64));
try {
    $manifest->assertMatches((object) ['id' => 't', 'version' => 1, 'schema_hash' => str_repeat('a', 64)], str_repeat('b', 64));
    throw new RuntimeException('Drift was accepted');
} catch (TemplateIntegrityException) {
    echo "Signed manifest accepted; catalogue drift refused.\n";
}
