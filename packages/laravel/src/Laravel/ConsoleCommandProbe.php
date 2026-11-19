<?php

declare(strict_types=1);

namespace Tenant\Laravel\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Packman\Attributes\Internal;

/**
 * Detects Artisan diagnostic/inspection commands so provider boot avoids crashing before diagnostics run.
 */
#[Internal]
final class ConsoleCommandProbe
{
    /** @var list<string> */
    private const array DIAGNOSTIC_COMMANDS = [
        'packman:doctor',
        'packman:config:validate',
        'list',
    ];

    public static function isDiagnosticCommand(Application $app): bool
    {
        if (! $app->runningInConsole()) {
            return false;
        }

        $command = self::consoleCommandName();

        return $command !== null && in_array($command, self::DIAGNOSTIC_COMMANDS, true);
    }

    private static function consoleCommandName(): ?string
    {
        $rawArgv = $_SERVER['argv'] ?? null;

        if (! is_array($rawArgv)) {
            return null;
        }

        $argv = array_values($rawArgv);
        $count = count($argv);

        for ($index = 1; $index < $count; $index++) {
            $token = $argv[$index];

            if (! is_string($token) || $token === '') {
                continue;
            }

            if ($token === '--') {
                $next = $argv[$index + 1] ?? null;

                return is_string($next) && $next !== '' ? $next : null;
            }

            if ($token === '--env') {
                $next = $argv[$index + 1] ?? null;

                if (is_string($next) && ! str_starts_with($next, '-')) {
                    $index++;
                }

                continue;
            }

            if (str_starts_with($token, '-')) {
                continue;
            }

            return $token;
        }

        return null;
    }
}
