<?php

declare(strict_types=1);

use Tenant\Data\Context\ProfileRef;
use Tenant\Laravel\Queue\QueueRegistry;

it('keeps queue signing material out of registry debug output and serialization', function (): void {
    $key = str_repeat('queue-secret-', 3);
    $registry = new QueueRegistry([], [], [], [], $key, new ProfileRef('php-scoped', 1));

    expect($registry->sign('payload'))->toBe(hash_hmac('sha256', 'payload', $key))
        ->and(print_r($registry, true))->not->toContain($key)
        ->and(fn (): string => serialize($registry))->toThrow(LogicException::class);
});

it('redacts rejected signing material in constructor exception traces', function (): void {
    $previous = ini_set('zend.exception_ignore_args', '0');
    $key = 'short-secret';
    try {
        try {
            new QueueRegistry([], [], [], [], $key, new ProfileRef('php-scoped', 1));
            test()->fail('Expected a rejected short signing key');
        } catch (InvalidArgumentException $error) {
            expect(print_r($error->getTrace(), true))->not->toContain($key);
        }
    } finally {
        ini_set('zend.exception_ignore_args', $previous);
    }
});
