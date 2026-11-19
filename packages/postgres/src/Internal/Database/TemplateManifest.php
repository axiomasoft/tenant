<?php

declare(strict_types=1);

namespace Tenant\Postgres\Internal\Database;

use Packman\Attributes\Internal;
use stdClass;
use Tenant\Postgres\Exceptions\Provisioning\TemplateIntegrityException;

use function base64_decode;
use function hash;
use function is_array;
use function preg_match;
use function sodium_crypto_sign_verify_detached;
use function strtr;

/** @internal */
#[Internal]
final readonly class TemplateManifest
{
    /** @param array<string, mixed> $payload */
    private function __construct(
        public array $payload,
        public string $signature,
        public string $trustedKeyId,
    ) {}

    /** @param array<string, mixed> $manifest */
    public static function fromConfig(array $manifest): self
    {
        if (! isset($manifest['payload'], $manifest['signature'], $manifest['trusted_key_id'])
            || ! is_array($manifest['payload'])
            || ! is_string($manifest['signature'])
            || ! is_string($manifest['trusted_key_id'])) {
            throw new TemplateIntegrityException('The configured template manifest is malformed.');
        }

        foreach (array_keys($manifest['payload']) as $key) {
            if (! is_string($key)) {
                throw new TemplateIntegrityException('The template manifest payload must have named fields.');
            }
        }

        return new self(
            payload: array_filter($manifest['payload'], is_string(...), ARRAY_FILTER_USE_KEY),
            signature: $manifest['signature'],
            trustedKeyId: $manifest['trusted_key_id'],
        );
    }

    /** @param array<string, string> $trustedKeys */
    public function assertTrusted(array $trustedKeys, SchemaCatalogueSerializer $serializer): void
    {
        $key = $trustedKeys[$this->trustedKeyId] ?? null;

        if (! is_string($key) || $key === '') {
            throw new TemplateIntegrityException('The template manifest key is not trusted.');
        }

        $signature = self::decodeBase64Url($this->signature);
        $publicKey = self::decodeBase64Url($key);
        $payload = $serializer->encode($this->payload);
        $message = "tenant-template-manifest/v1\0".$payload;

        if (! sodium_crypto_sign_verify_detached($signature, $message, $publicKey)) {
            throw new TemplateIntegrityException('The template manifest signature is invalid.');
        }
    }

    public function digest(SchemaCatalogueSerializer $serializer): string
    {
        return hash('sha256', $serializer->encode($this->payload));
    }

    public function assertMatches(object $template, string $actualHash): void
    {
        if (! $template instanceof stdClass) {
            throw new TemplateIntegrityException('The template row is malformed.');
        }
        $expected = $this->payload['canonical_schema_sha256'] ?? null;

        if (! is_string($expected) || preg_match('/^[a-f0-9]{64}$/D', $expected) !== 1
            || ! isset($this->payload['template_id'], $this->payload['template_version'])
            || $this->payload['template_id'] !== $template->id
            || DatabaseValue::integer($this->payload['template_version']) !== DatabaseValue::integer($template->version)
            || $expected !== $template->schema_hash
            || $expected !== $actualHash) {
            throw new TemplateIntegrityException('The expected, recorded and actual template hashes must match.');
        }
    }

    /** @return non-empty-string */
    private static function decodeBase64Url(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), strict: true);

        if ($decoded === false || $decoded === '') {
            throw new TemplateIntegrityException('The template manifest has invalid base64url data.');
        }

        return $decoded;
    }
}
