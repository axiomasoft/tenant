<?php

declare(strict_types=1);

namespace Tenant\Postgres;

use InvalidArgumentException;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class OperationKey
{
    public function __construct(public string $namespace, public string $key)
    {
        foreach ([$namespace, $key] as $part) {
            if (strlen($part) > 128 || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $part) !== 1) {
                throw new InvalidArgumentException('Invalid operation key.');
            }
        }
    }

    public function storageKey(): string
    {
        return hash('sha256', "tenant-operation-key/v1\0".strlen($this->namespace).':'.$this->namespace.$this->key);
    }
}
