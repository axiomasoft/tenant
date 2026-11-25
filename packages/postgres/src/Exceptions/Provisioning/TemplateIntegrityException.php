<?php

declare(strict_types=1);

namespace Tenant\Postgres\Exceptions\Provisioning;

use Packman\Attributes\Api;
use RuntimeException;

#[Api(since: '1.0')]
final class TemplateIntegrityException extends RuntimeException {}
