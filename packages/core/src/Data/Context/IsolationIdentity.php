<?php

declare(strict_types=1);

namespace Tenant\Data\Context;

use InvalidArgumentException;
use Normalizer;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class IsolationIdentity
{
    public function __construct(public string $namespace, public string $id)
    {
        if (strlen($namespace) > 48 || preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/D', $namespace) !== 1
            || $id === '' || strlen($id) > 96 || preg_match('//u', $id) !== 1
            || preg_match('/[\x00-\x1f\x7f]/', $id) === 1 || Normalizer::isNormalized($id, Normalizer::FORM_C) !== true) {
            throw new InvalidArgumentException('Invalid isolation identity');
        }
    }
}
