<?php

declare(strict_types=1);

namespace Tenant\Internal\Validation;

use InvalidArgumentException;
use Packman\Attributes\Internal;
use ReflectionReference;

/** Detach PHP array references without cloning process-local service objects. */
#[Internal]
final class ArraySnapshot
{
    /**
     * @template T of array<array-key, mixed>
     *
     * @param  T  $values
     * @param  array<string, true>  $ancestors
     * @return T
     */
    public static function copy(array $values, array $ancestors = []): array
    {
        $snapshot = [];
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $reference = ReflectionReference::fromArrayElement($values, $key)?->getId();
                $path = $ancestors;
                if ($reference !== null) {
                    if (isset($path[$reference])) {
                        throw new InvalidArgumentException('Recursive arrays cannot be frozen');
                    }
                    $path[$reference] = true;
                }
                $value = self::copy($value, $path);
            }
            $snapshot[$key] = $value;
        }

        /** @var T $snapshot Keys and value types are unchanged; only reference aliases are removed. */
        return $snapshot;
    }
}
