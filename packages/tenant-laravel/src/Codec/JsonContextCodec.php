<?php

declare(strict_types=1);

namespace Tenant\Laravel\Codec;

use InvalidArgumentException;
use Packman\Attributes\Api;
use stdClass;
use Tenant\Contracts\Context\ContextCodec;
use Tenant\Data\Context\ContextReference;
use Tenant\Laravel\Internal\Codec\StrictJson;

/** Strict tenant.context/1 codec. Registry, origin and admission remain host responsibilities. */
#[Api(since: '1.0')]
final readonly class JsonContextCodec implements ContextCodec
{
    public function decode(string $json): ContextReference
    {
        $object = new StrictJson($json)->object();
        $data = [];
        foreach (get_object_vars($object) as $key => $value) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('Invalid context key');
            } $data[$key] = $value;
        }
        if (! is_array($data['extensions'] ?? null) || ! array_is_list($data['extensions'])) {
            throw new InvalidArgumentException('Invalid extensions array');
        }
        $extensions = [];
        foreach ($data['extensions'] as $extension) {
            if (! $extension instanceof stdClass || ! ($extension->refs ?? null) instanceof stdClass) {
                throw new InvalidArgumentException('Invalid extension object');
            }
            $refObject = $extension->refs;
            $row = get_object_vars($extension);
            $row['refs'] = get_object_vars($refObject);
            $extensions[] = $row;
        }
        $data['extensions'] = $extensions;

        return new ContextReference($data);
    }

    public function encode(ContextReference $reference): string
    {
        $data = $reference->envelope;
        $extensions = [];
        if (! is_array($data['extensions'])) {
            throw new InvalidArgumentException('Invalid extensions');
        }
        foreach ($data['extensions'] as $extension) {
            if (! is_array($extension) || ! is_string($extension['type'] ?? null) || ! is_array($extension['refs'] ?? null)) {
                throw new InvalidArgumentException('Invalid extension');
            }
            ksort($extension['refs'], SORT_STRING);
            $extension['refs'] = (object) $extension['refs'];
            ksort($extension, SORT_STRING);
            $extensions[] = $extension;
        }
        usort($extensions, static fn (array $a, array $b): int => strcmp($a['type'], $b['type']));
        $data['extensions'] = $extensions;
        ksort($data, SORT_STRING);
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->decode($json);

        return $json;
    }
}
