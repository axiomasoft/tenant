<?php

declare(strict_types=1);

namespace Tenant\Internal\Runtime;

use Tenant\Runtime\GenerationGuard as PublicGenerationGuard;

class_alias(PublicGenerationGuard::class, __NAMESPACE__.'\\GenerationGuard');
