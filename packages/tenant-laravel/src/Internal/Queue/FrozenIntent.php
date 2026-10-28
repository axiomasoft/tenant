<?php

declare(strict_types=1);
namespace Tenant\Laravel\Internal\Queue;

use Tenant\Contracts\ContextCodec;
use Tenant\Data\FrozenDispatch;
use Tenant\Laravel\Queue\{QueueRegistry, ScalarJob};

/** @internal No serialised job object, routes, credentials or runtime state. */
final class FrozenIntent
{
    /** @return array{wire: string, purpose: string, producer: string, handler: string, data: array<string, scalar|null>, signature: string} */
    public static function payload(FrozenDispatch $intent, ScalarJob $job, ContextCodec $codec, QueueRegistry $registry): array
    {
        $payload = ['wire'=>$codec->encode($intent->reference), 'purpose'=>$intent->purpose->id, 'producer'=>$job->producer, 'handler'=>$job->handler, 'data'=>$job->data];
        $payload['signature'] = $registry->sign(self::bytes($payload));
        return $payload;
    }
    /** @param array<array-key, mixed> $payload */
    public static function bytes(array $payload): string
    {
        unset($payload['signature']);
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
