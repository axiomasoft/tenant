<?php

declare(strict_types=1);

namespace Tenant\Laravel\Internal\Cache;

use Closure;
use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Store;
use LogicException;
use Packman\Attributes\Internal;
use Tenant\Exceptions\TenantFailure;
use Tenant\Runtime\GenerationGuard;

/** @internal Guard at the store, including Repository convenience methods and retained locks. */
#[Internal]
final readonly class GuardedCache implements LockProvider, Store
{
    public function __construct(private Store $store, private GenerationGuard $guard) {}

    public function get($key)
    {
        $this->guard->check();

        return $this->store->get($key);
    }

    /**
     * @param  array<mixed>  $keys
     * @return array<array-key, mixed>
     */
    public function many(array $keys)
    {
        $this->guard->check();

        return $this->store->many($keys);
    }

    public function put($key, $value, $seconds)
    {
        $this->guard->check();

        return $this->store->put($key, $value, $seconds);
    }

    /** Preserve RedisStore's atomic Lua add; Repository detects this optional method. */
    public function add(string $key, mixed $value, int $seconds): bool
    {
        $this->guard->check();
        if (method_exists($this->store, 'add')) {
            $result = (Closure::fromCallable([$this->store, 'add']))($key, $value, $seconds);
            if (! is_bool($result)) {
                throw new LogicException('Invalid cache add result');
            }

            return $result;
        }
        // The qualified process-local ArrayStore has no native add operation.
        if (! $this->store instanceof ArrayStore) {
            throw new TenantFailure('unsupported_capability');
        }
        if ($this->store->get($key) !== null) {
            return false;
        }

        return $this->store->put($key, $value, $seconds);
    }

    /** @param array<mixed> $values */
    public function putMany(array $values, $seconds)
    {
        $this->guard->check();

        return $this->store->putMany($values, $seconds);
    }

    public function increment($key, $value = 1)
    {
        $this->guard->check();

        return $this->store->increment($key, $value);
    }

    public function decrement($key, $value = 1)
    {
        $this->guard->check();

        return $this->store->decrement($key, $value);
    }

    public function forever($key, $value)
    {
        $this->guard->check();

        return $this->store->forever($key, $value);
    }

    /**
     * @param  string  $key
     * @param  int  $seconds
     * @return bool
     */
    public function touch($key, $seconds)
    {
        $this->guard->check();

        return self::touchDriver($this->store, $key, $seconds);
    }

    private static function touchDriver(object $store, string $key, int $seconds): bool
    {
        if (! method_exists($store, 'touch')) {
            throw new TenantFailure('unsupported_capability');
        }
        $result = ($store->touch(...))($key, $seconds);
        if (! is_bool($result)) {
            throw new LogicException('Invalid cache touch result');
        }

        return $result;
    }

    public function forget($key)
    {
        $this->guard->check();

        return $this->store->forget($key);
    }

    public function flush(): never
    {
        $this->guard->check();
        throw new TenantFailure('unsupported_capability');
    }

    public function getPrefix()
    {
        $this->guard->check();

        return $this->store->getPrefix();
    }

    public function lock($name, $seconds = 0, $owner = null)
    {
        $this->guard->check();
        if (! $this->store instanceof LockProvider) {
            throw new TenantFailure('unsupported_capability');
        }

        return new GuardedLock($this->store->lock($name, $seconds, $owner), $this->guard);
    }

    public function restoreLock($name, $owner)
    {
        return $this->lock($name, 0, $owner);
    }
}
