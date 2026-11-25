<?php

declare(strict_types=1);

namespace Tenant\Internal\Validation;

use InvalidArgumentException;
use Packman\Attributes\Internal;
use Tenant\Data\Context\ExtensionRef;

#[Internal]
final class Validate
{
    /** @param array<mixed> $extensions */
    public static function extensions(array $extensions): void
    {
        if (! array_is_list($extensions) || count($extensions) > 16) {
            throw new InvalidArgumentException('Invalid extensions');
        }
        $seen = [];
        foreach ($extensions as $extension) {
            if (! $extension instanceof ExtensionRef || isset($seen[$extension->type])) {
                throw new InvalidArgumentException('Invalid extension');
            }
            $seen[$extension->type] = true;
        }
    }

    /**
     * @param  array<mixed>  $refs
     * @return array<string, string>
     */
    public static function refs(array $refs): array
    {
        foreach ($refs as $key => $value) {
            if (! is_string($key) || ! is_string($value) || strlen($key) > 48 || preg_match('/^[a-z][a-zA-Z0-9]*$/D', $key) !== 1 || $value === '' || strlen($value) > 128 || preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
                throw new InvalidArgumentException('Invalid extension reference');
            }
        }

        return $refs;
    }
}
