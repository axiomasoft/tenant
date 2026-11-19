<?php

declare(strict_types=1);

namespace Tenant\Data\Context;

use Packman\Attributes\Api;
use Tenant\Data\Verification\BindingExpectation;
use Tenant\Internal\Validation\ArraySnapshot;
use Tenant\Internal\Validation\Validate;

#[Api(since: '1.0')]
final readonly class Selection
{
    /** @var list<ExtensionRef> */
    public array $extensions;

    /** @param list<ExtensionRef> $extensions */
    private function __construct(public string $mode, public ?IsolationIdentity $identity, public ProfileRef $expectedProfile, array $extensions, public ?BindingExpectation $expectedBinding)
    {
        $this->extensions = ArraySnapshot::copy($extensions);
    }

    /** @param list<ExtensionRef> $extensions */
    public static function tenant(IsolationIdentity $identity, ProfileRef $profile, array $extensions = [], ?BindingExpectation $binding = null): self
    {
        return self::make('tenant', $identity, $profile, $extensions, $binding);
    }

    /** @param list<ExtensionRef> $extensions */
    public static function single(IsolationIdentity $identity, array $extensions = [], ?BindingExpectation $binding = null): self
    {
        return self::make('single', $identity, new ProfileRef('single', 1), $extensions, $binding);
    }

    public static function central(ProfileRef $profile): self
    {
        return new self('central', null, $profile, [], null);
    }

    /** @param list<ExtensionRef> $extensions */
    private static function make(string $mode, IsolationIdentity $identity, ProfileRef $profile, array $extensions, ?BindingExpectation $binding): self
    {
        Validate::extensions($extensions);

        return new self($mode, $identity, $profile, $extensions, $binding);
    }
}
