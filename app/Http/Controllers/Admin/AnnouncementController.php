<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Branch;
use App\Models\Discount;
use App\Services\AnnouncementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AnnouncementController extends Controller
{
    public function __construct(protected AnnouncementService $service) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Announcement::class);

        $query = Announcement::with(['branch', 'creator', 'publisher'])
            ->latest();

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }
        if ($search = trim((string) $request->get('search'))) {
            $query->where(fn ($q) => $q->where('title', 'like', "%{$search}%")
                ->orWhere('body', 'like', "%{$search}%"));
        }

        $announcements = $query->paginate(20)->withQueryString();

        $stats = [
            'total'     => Announcement::count(),
            'published' => Announcement::where('status', 'published')->count(),
            'draft'     => Announcement::where('status', 'draft')->count(),
            'expired'   => Announcement::whereIn('status', ['expired', 'archived'])->count(),
        ];

        return view('admin.announcements.index', compact('announcements', 'stats'));
    }

    public function create(Request $request)
    {
        $this->authorize('create', Announcement::class);

        $user = $request->user();
        // Branch admins and managers are locked to their own branch — they
        // can't broadcast on behalf of another branch. Owner-level (super-
        // admin/partner) and the global Admin role get the full picker, so
        // they can either pick a single branch or post a global "all
        // branches" announcement (branch_id = null).
        [$branches, $defaultBranchId, $branchLocked] = $this->branchOptionsFor($user);

        return view('admin.announcements.form', [
            'announcement' => new Announcement([
                'audience_type' => 'all',
                'icon'          => 'bi-megaphone-fill',
                'color'         => '#F9A825',
                'branch_id'     => $defaultBranchId,
            ]),
            'branches'      => $branches,
            'branchLocked'  => $branchLocked,
            'canPostGlobal' => $this->canPostGlobal($user),
            'discounts'     => Discount::where('active', true)->get(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', Announcement::class);
        $data = $this->validateAnnouncement($request);
        $data['created_by_user_id'] = $request->user()->id;
        $data['status'] = 'draft';

        if ($request->hasFile('image')) {
            $data['image_path'] = $request->file('image')->store('announcements', 'public');
        }

        $announcement = Announcement::create($data);

        return redirect()->route('admin.announcements.edit', $announcement)
            ->with('success', 'تم حفظ الإعلان كمسوّدة. اضغط "نشر" لإرساله للزبائن.');
    }

    public function edit(Announcement $announcement, Request $request)
    {
        $this->authorize('view', $announcement);

        $user = $request->user();
        [$branches, $defaultBranchId, $branchLocked] = $this->branchOptionsFor($user);

        return view('admin.announcements.form', [
            'announcement'      => $announcement,
            'branches'          => $branches,
            'branchLocked'      => $branchLocked,
            'canPostGlobal'     => $this->canPostGlobal($user),
            'discounts'         => Discount::where('active', true)->get(),
            'estimatedAudience' => $this->service->buildAudience($announcement)->count(),
        ]);
    }

    public function update(Request $request, Announcement $announcement)
    {
        $this->authorize('update', $announcement);

        $data = $this->validateAnnouncement($request);

        // POST-PUBLISH editing rules:
        //   - Notifications already sent are immutable; the in-app banner
        //     and any FUTURE notifications are what changes.
        //   - Allow content tweaks (title fix, typo, color, icon, image, CTA).
        //   - Lock audience + schedule changes — re-targeting after a send
        //     would be misleading ("you got this because we picked a new
        //     filter today"). For a re-target, the user creates a new
        //     announcement.
        //   - Lock branch_id too — moving a published item between branches
        //     would lose the original audience signal.
        if ($announcement->status === 'published') {
            unset(
                $data['audience_type'],
                $data['audience_filter'],
                $data['starts_at'],
                $data['ends_at'],
                $data['branch_id'],
            );
        }

        if ($request->hasFile('image')) {
            if ($announcement->image_path) {
                Storage::disk('public')->delete($announcement->image_path);
            }
            $data['image_path'] = $request->file('image')->store('announcements', 'public');
        }

        $announcement->update($data);

        $msg = $announcement->status === 'published'
            ? 'تم تحديث الإعلان. (الجمهور والجدولة محميّان لأن الإعلان منشور.)'
            : 'تم تحديث الإعلان.';

        return back()->with('success', $msg);
    }

    public function publish(Announcement $announcement, Request $request)
    {
        $this->authorize('publish', $announcement);
        try {
            $this->service->publish($announcement, $request->user());
            return redirect()->route('admin.announcements.index')
                ->with('success', "نُشر الإعلان وأُرسل لـ {$announcement->refresh()->recipients_count} زبون.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function unpublish(Announcement $announcement, Request $request)
    {
        $this->authorize('publish', $announcement);
        $this->service->unpublish($announcement, $request->user());
        return back()->with('success', 'تم إلغاء نشر الإعلان.');
    }

    public function destroy(Announcement $announcement)
    {
        $this->authorize('delete', $announcement);
        if ($announcement->image_path) {
            Storage::disk('public')->delete($announcement->image_path);
        }
        $announcement->delete();
        return redirect()->route('admin.announcements.index')
            ->with('success', 'تم حذف الإعلان.');
    }

    /**
     * Branch picker options for the current user.
     *
     * Returns [branches, defaultBranchId, locked]:
     *   - locked = true  → user gets a read-only badge showing their branch
     *   - locked = false → user gets the full select with optional "all"
     *
     * Decision matrix:
     *   - Owner-level (super_admin, partner) and `admin` role: every active
     *     branch + the "all branches" option (handled separately via
     *     `canPostGlobal()`).
     *   - Manager (and any other restricted role): only the branch(es)
     *     they're a member of, with their primary branch pre-selected.
     */
    protected function branchOptionsFor($user): array
    {
        if ($user->isOwnerLevel() || $user->isAdmin()) {
            return [\App\Models\Branch::active()->get(), null, false];
        }

        // Pull the user's assigned branches via the pivot. Primary first.
        $branches = $user->branches()
                        ->orderByDesc('branch_user.is_primary')
            ->get();
        $defaultId = $user->primaryBranch()?->id ?? $branches->first()?->id;

        return [$branches, $defaultId, true];
    }

    /**
     * Can this user broadcast an announcement to ALL branches at once
     * (i.e., set branch_id = NULL)? Reserved for owner-level + admin to
     * keep cross-branch marketing centrally controlled.
     */
    protected function canPostGlobal($user): bool
    {
        return $user->isOwnerLevel() || $user->isAdmin();
    }

    protected function validateAnnouncement(Request $request): array
    {
        // When editing a PUBLISHED announcement the form locks audience +
        // schedule + branch (disabled inputs aren't submitted by the
        // browser). The update() handler explicitly strips those fields
        // from the payload anyway, so the rules for them must be `nullable`
        // to let the request through; otherwise the user gets a misleading
        // "audience type is required" error on what was supposed to be a
        // simple typo fix.
        $route = $request->route();
        $editingPublished = false;
        if ($route && $route->hasParameter('announcement')) {
            $existing = $route->parameter('announcement');
            $editingPublished = $existing instanceof Announcement
                && $existing->status === 'published';
        }
        // `guests` is a special audience type — it doesn't fan out to the
        // customer notifications table (guests have no Customer row). It's
        // rendered live on the public menu screen for anonymous diners as
        // a conversion banner. Treated as a valid value here; the service
        // and the menu view handle the distinct distribution.
        $audienceTypeRule = $editingPublished
            ? ['nullable', 'in:all,tier,inactive_days,specific,guests']
            : ['required', 'in:all,tier,inactive_days,specific,guests'];

        $data = $request->validate([
            'branch_id'              => ['nullable', 'exists:branches,id'],
            'title'                  => ['required', 'string', 'max:200'],
            'body'                   => ['required', 'string', 'max:2000'],
            'image'                  => ['nullable', 'image', 'max:2048'],
            'icon'                   => ['nullable', 'string', 'max:64'],
            'color'                  => ['nullable', 'string', 'max:16'],
            'cta_text'               => ['nullable', 'string', 'max:80'],
            'cta_url'                => ['nullable', 'string', 'max:500'],
            'discount_id'            => ['nullable', 'exists:discounts,id'],
            'audience_type'          => $audienceTypeRule,
            'audience_filter.min_tier'    => ['nullable', 'in:bronze,silver,gold'],
            'audience_filter.days'        => ['nullable', 'integer', 'min:1', 'max:365'],
            'audience_filter.customer_ids'=> ['nullable', 'array'],
            'starts_at'              => ['nullable', 'date'],
            'ends_at'                => ['nullable', 'date', 'after_or_equal:starts_at'],
        ], [
            'title.required' => 'عنوان الإعلان مطلوب.',
            'body.required'  => 'نص الإعلان مطلوب.',
            'image.image'    => 'الملف يجب أن يكون صورة.',
            'image.max'      => 'حجم الصورة يتجاوز 2 ميجا.',
            'ends_at.after_or_equal' => 'تاريخ الانتهاء يجب أن يكون بعد تاريخ البدء.',
            'audience_type.required' => 'اختر الجمهور المستهدف.',
        ]);

        // Strip the audience_filter to only the keys relevant to its type.
        $type = $data['audience_type'] ?? 'all';
        $filter = $data['audience_filter'] ?? [];
        $data['audience_filter'] = match ($type) {
            'tier'           => ['min_tier' => $filter['min_tier'] ?? 'silver'],
            'inactive_days'  => ['days' => (int) ($filter['days'] ?? 30)],
            'specific'       => ['customer_ids' => array_map('intval', $filter['customer_ids'] ?? [])],
            // `guests` carries no filter dimensions — the banner targets
            // every anonymous session on the menu, narrowed only by branch.
            'guests'         => null,
            default          => null,
        };

        // ─── Branch-scope guard ─────────────────────────────────────────
        // Don't trust the form: re-derive what's allowed from the user's
        // role and silently rewrite branch_id to a permitted value. A
        // branch manager who hand-crafts a POST with another branch_id
        // (or with branch_id null) lands on their own branch — never
        // leaks an announcement to a branch they don't manage.
        $user = $request->user();
        $submitted = $data['branch_id'] ?? null;

        if (! $this->canPostGlobal($user)) {
            // Restricted: must equal one of the user's assigned branches.
            //
            // BUG FIX: previously called `wherePivot('is_active', true)` —
            // but `branch_user` has NO `is_active` column (verify against
            // database/migrations/2026_04_20_000003_create_branch_user_table.php).
            // The query silently returned an empty collection, so $accessible
            // was always [], the `in_array` check always failed, and a
            // restricted manager hitting this code with branch_id=null kept
            // branch_id=null → announcement leaked to ALL branches as global.
            //
            // Use `accessibleBranchIds()` (the canonical helper that pulls
            // every assigned branch + filters to active branches) instead.
            $accessible = $user->accessibleBranchIds();
            if (! $submitted || ! in_array((int) $submitted, $accessible, true)) {
                $data['branch_id'] = $user->primaryBranch()?->id ?? ($accessible[0] ?? null);
            }
        }
        // Owner-level / admin: keep whatever they sent (incl. null = all).

        unset($data['image']);
        return $data;
    }
}
