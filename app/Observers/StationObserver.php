<?php

namespace App\Observers;

use App\Models\Permission;
use App\Models\Station;
use Illuminate\Support\Facades\Cache;

/**
 * Keeps a dedicated "view station" Permission in sync with each Station row.
 *
 * Why: the roles/permissions UI already works with discrete permissions
 * (orders.approve, users.create, …). Giving every station its own permission
 * (`station.kitchen.view`, `station.bar.view`, …) slots cleanly into that
 * existing system — an owner can then tick "الوصول لشاشة البار" on any role
 * from the normal role-edit page, no custom UI needed.
 *
 * Branch model: Stations themselves are PER-BRANCH, but the permission codes
 * stay GLOBAL by station code. The reasoning: a chef's role specifies "can
 * access the kitchen screen" once and that grant carries them into whichever
 * branch's kitchen they're currently working at — the BelongsToBranch trait
 * scopes the actual station lookup to the active branch. So Khan Yunis's
 * `kitchen` station and Gaza's `kitchen` station SHARE one permission row.
 *
 * Lifecycle (mind the dedup checks — unrelated branches must not be
 * collateral damage):
 *   • Station created  → upsert permission (idempotent across branches that
 *                        share the same code).
 *   • Station renamed  → permission label follows (the `station.{code}.view`
 *                        key stays stable unless the CODE changes).
 *   • Station code changed → upsert new permission. Old permission is only
 *                            removed if no other branch still uses the old
 *                            code; assigned roles are migrated.
 *   • Station deleted  → permission is removed only if no other branch still
 *                        has a station with this code.
 */
class StationObserver
{
    public function created(Station $station): void
    {
        $this->upsertPermission($station);
    }

    public function updated(Station $station): void
    {
        // Code changed → move the permission + preserve role assignments
        if ($station->wasChanged('code')) {
            $oldCode = $station->getOriginal('code');
            // withoutGlobalScopes — the dedup check spans EVERY branch, not
            // just the one the actor is currently switched into.
            $stillUsed = Station::query()
                ->withoutGlobalScopes()
                ->where('code', $oldCode)
                ->where('id', '!=', $station->id)
                ->exists();

            $old = Permission::where('name', $this->nameFor($oldCode))->first();

            if ($old && ! $stillUsed) {
                // Migrate role assignments to the new permission and drop the old.
                $roleIds = $old->roles()->pluck('roles.id')->all();
                $old->delete();
                $new = $this->upsertPermission($station);
                if ($roleIds) $new->roles()->sync($roleIds);
            } else {
                $this->upsertPermission($station);
            }
        } elseif ($station->wasChanged('name')) {
            // Just refresh the Arabic label on the existing permission row.
            Permission::where('name', $this->nameFor($station->code))->update([
                'label'       => $this->labelFor($station),
                'group_label' => 'شاشات المحطات',
            ]);
            Cache::forget('setting.'.$this->nameFor($station->code));
        }
    }

    public function deleted(Station $station): void
    {
        // Only retire the permission if no other branch still has a station
        // with this code — otherwise we'd silently revoke kitchen access from
        // chefs in unrelated branches. withoutGlobalScopes so the check sees
        // stations across every branch, not just the actor's active one.
        $stillUsed = Station::query()
            ->withoutGlobalScopes()
            ->where('code', $station->code)
            ->where('id', '!=', $station->id)
            ->exists();

        if (! $stillUsed) {
            Permission::where('name', $this->nameFor($station->code))->delete();
        }
    }

    protected function upsertPermission(Station $station): Permission
    {
        return Permission::updateOrCreate(
            ['name' => $this->nameFor($station->code)],
            [
                'group'         => 'stations_access',
                'group_label'   => 'شاشات المحطات',
                'label'         => $this->labelFor($station),
                'description'   => 'السماح بعرض وإدارة طلبات محطة ' . $station->name,
                'display_order' => 100 + $station->display_order,
            ]
        );
    }

    public static function nameFor(string $code): string
    {
        return 'station.'.$code.'.view';
    }

    protected function labelFor(Station $station): string
    {
        return 'شاشة: ' . $station->name;
    }
}
