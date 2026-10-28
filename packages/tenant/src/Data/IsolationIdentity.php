<?php

declare(strict_types=1);

namespace Tenant\Data;

use Tenant\Attributes\Api;

#[Api]
final readonly class IsolationIdentity
{
    public function __construct(public string $namespace, public string $id)
    {
        if (strlen($namespace) > 48 || !preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/D', $namespace)
            || $id === '' || strlen($id) > 96 || !preg_match('//u', $id)
            || preg_match('/[\x00-\x1f\x7f]/', $id) || !\Normalizer::isNormalized($id, \Normalizer::FORM_C)) {
            throw new \InvalidArgumentException('Invalid isolation identity');
        }
    }
}
