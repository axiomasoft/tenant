<?php

declare(strict_types=1);

namespace Tenant\Laravel\Internal;

use Closure;
use Illuminate\Contracts\Config\Repository;

/** @internal CoreX dynamic configuration seam; never captures a resident repository. */
final readonly class FrameworkConfig
{
    /** @param Closure(): Repository $repository */
    public function __construct(private Closure $repository) {}
    public function get(string $key, mixed $default = null): mixed { return ($this->repository)()->get($key, $default); }
    public function set(string $key, mixed $value): void { ($this->repository)()->set($key, $value); }

    /** @return array<string, mixed> */
    public function map(string $key): array { return self::asMap($this->get($key)); }
    public function text(string $key): string
    {
        $value = $this->get($key);
        if (!is_string($value)) throw new \Tenant\Exceptions\TenantFailure('facility_configuration');
        return $value;
    }
    /** @return list<string> */
    public function strings(string $key): array
    {
        $value = $this->get($key, []);
        if (!is_array($value) || !array_is_list($value)) throw new \Tenant\Exceptions\TenantFailure('facility_configuration');
        foreach ($value as $item) if (!is_string($item)) throw new \Tenant\Exceptions\TenantFailure('facility_configuration');
        return $value;
    }
    /** @return array<string, mixed> */
    public static function asMap(mixed $value): array
    {
        if (!is_array($value)) throw new \Tenant\Exceptions\TenantFailure('facility_configuration');
        foreach ($value as $key => $_) if (!is_string($key)) throw new \Tenant\Exceptions\TenantFailure('facility_configuration');
        return $value;
    }
    /**
     * @template T of object
     * @param class-string<T> $type
     * @return T
     */
    public static function service(\Illuminate\Contracts\Container\Container $app, string $name, string $type): object
    {
        $service = $app->make($name);
        if (!$service instanceof $type) throw new \Tenant\Exceptions\TenantFailure('facility_configuration');
        return $service;
    }
    /** @return array<string, \Illuminate\Session\Store> */
    public static function stores(\Illuminate\Session\SessionManager $manager): array
    {
        $stores = self::asMap($manager->getDrivers());
        foreach ($stores as $store) {
            if (!$store instanceof \Illuminate\Session\Store) throw new \Tenant\Exceptions\TenantFailure('facility_configuration');
        }
        return $stores;
    }
}
