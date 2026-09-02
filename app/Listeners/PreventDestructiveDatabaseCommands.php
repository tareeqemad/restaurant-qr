<?php

namespace App\Listeners;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Foundation\Application;
use RuntimeException;

/**
 * Refuse whole-database reset commands on a live restaurant installation.
 *
 * Laravel's production confirmation is intentionally generic and still lets
 * an operator erase business data by answering "yes". Restaurant data needs
 * a harder guard that requires an explicit environment change.
 */
class PreventDestructiveDatabaseCommands
{
    private const BLOCKED_COMMANDS = [
        'db:wipe',
        'migrate:fresh',
        'migrate:refresh',
        'migrate:reset',
    ];

    public function __construct(private readonly Application $app) {}

    public function handle(CommandStarting $event): void
    {
        if (! $this->app->environment('production')) {
            return;
        }

        if ((bool) $this->app['config']->get('app.allow_destructive_database_commands', false)) {
            return;
        }

        if (! in_array($event->command, self::BLOCKED_COMMANDS, true)) {
            return;
        }

        throw new RuntimeException(
            "Blocked [{$event->command}] in production because it can erase restaurant data. "
            .'Use [php artisan app:deploy] for updates. For an intentional disposable reset, '
            .'set ALLOW_DESTRUCTIVE_DATABASE_COMMANDS=true temporarily and take an external backup first.'
        );
    }
}
