<?php

namespace App\Support;

/**
 * Thin static façade over the runtime "active branch" context.
 *
 *   - Web requests: SetActiveBranch middleware sets it from session/user.
 *   - Console / queue: unset by default, so BranchScope is a no-op
 *     unless the developer wraps work in BranchContext::forBranch().
 *
 * Implemented with simple static state (not the IoC container) so it is
 * trivially testable and has no dependency on app() bindings during boot.
 */
class BranchContext
{
    protected static ?int $branchId = null;

    /** When true, BranchScope skips entirely (used by Super Admin tools). */
    protected static bool $unscoped = false;

    public static function set(?int $branchId): void
    {
        static::$branchId = $branchId;
    }

    public static function current(): ?int
    {
        return static::$branchId;
    }

    public static function clear(): void
    {
        static::$branchId = null;
        static::$unscoped = false;
    }

    public static function isUnscoped(): bool
    {
        return static::$unscoped;
    }

    /**
     * Run a callback pinned to a specific branch, then restore the previous
     * context — useful for cross-branch jobs and Super Admin flows.
     *
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    public static function forBranch(?int $branchId, callable $callback)
    {
        $previousId       = static::$branchId;
        $previousUnscoped = static::$unscoped;

        static::$branchId = $branchId;
        static::$unscoped = false;

        try {
            return $callback();
        } finally {
            static::$branchId = $previousId;
            static::$unscoped = $previousUnscoped;
        }
    }

    /**
     * Run a callback with the BranchScope disabled — for Super Admin
     * cross-branch reports and maintenance commands.
     *
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    public static function unscoped(callable $callback)
    {
        $previousUnscoped = static::$unscoped;
        static::$unscoped = true;

        try {
            return $callback();
        } finally {
            static::$unscoped = $previousUnscoped;
        }
    }
}
