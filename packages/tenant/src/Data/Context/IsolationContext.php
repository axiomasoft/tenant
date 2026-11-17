<?php

declare(strict_types=1);

namespace Tenant\Data\Context;

use InvalidArgumentException;
use Packman\Attributes\Api;
use Tenant\Data\Verification\BindingExpectation;
use Tenant\Internal\Validation\Validate;

#[Api(since: '1.0')]
final readonly class IsolationContext
{
    /** @param list<ExtensionRef> $extensions */
    public function __construct(public string $mode, public IsolationIdentity $identity, public ProfileRef $profile, public BindingExpectation $binding, public array $extensions = [])
    {
        if (! in_array($mode, ['tenant', 'single'], true)) {
            throw new InvalidArgumentException('Invalid context');
        }
        Validate::extensions($extensions);
    }
}
