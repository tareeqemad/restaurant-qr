<?php

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\TableSession;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Cashier-side panel for linking a portal Customer to the current table
 * session — covers two paths from the customer scenario doc:
 *
 *   • Path 2/3: walk-in customer wants an account. Cashier looks them up
 *     by phone; if not found, fills in name + phone (+optional email) and
 *     the component creates a Customer with a 6-digit PIN that displays
 *     ONCE at the top of the panel for the cashier to read aloud / write
 *     on the receipt.
 *
 *   • Path 4: customer was logged into the portal at QR-scan time and
 *     accepted the link prompt. The session arrives here pre-linked; the
 *     panel just shows their identity + an "unlink" button for the rare
 *     mistaken-identity case.
 *
 * Future orders placed against this session inherit `customer_id` via
 * Order::creating — see the boot hook on the Order model.
 */
new class extends Component
{
    public int $sessionId;

    /** Phone or email being typed into the search box. */
    public string $query = '';

    /** Form state used when no match is found. */
    public string $newName  = '';
    public string $newEmail = '';

    /** Plaintext PIN — populated for one render after a successful create. */
    public ?string $generatedPin = null;
    public ?int    $generatedFor = null;   // customer id, used to keep the banner up-to-date

    public function mount(int $sessionId): void
    {
        $this->sessionId = $sessionId;
    }

    #[Computed]
    public function session(): ?TableSession
    {
        return TableSession::with('customer')->find($this->sessionId);
    }

    /**
     * Customer rows that match the typed query. Limited to 8 because
     * cashiers want a quick scan, not a paginated table. Empty query =
     * no results (we don't show "everyone" by default).
     */
    #[Computed]
    public function matches(): array
    {
        $q = trim($this->query);
        if (mb_strlen($q) < 3) {
            return [];
        }

        $digits = preg_replace('/\D+/', '', $q) ?? '';

        return Customer::query()
            ->where(function ($w) use ($q, $digits) {
                $w->where('name', 'like', "%{$q}%")
                  ->orWhere('phone', 'like', "%{$q}%")
                  ->orWhere('email', 'like', "%{$q}%");
                if ($digits !== '' && $digits !== $q) {
                    $w->orWhere('phone', 'like', "%{$digits}%");
                }
            })
            ->orderBy('name')
            ->limit(8)
            ->get()
            ->all();
    }

    public function link(int $customerId): void
    {
        $session = $this->session;
        if (! $session) return;

        $customer = Customer::find($customerId);
        if (! $customer) return;

        $session->update(['customer_id' => $customer->id]);

        // Stamp downstream rows that already exist for this session so the
        // owner sees a unified history (orders + invoice picked up the link
        // retroactively, not just future ones).
        $session->orders()->whereNull('customer_id')->update(['customer_id' => $customer->id]);
        if ($session->invoice && ! $session->invoice->customer_id) {
            $session->invoice->update(['customer_id' => $customer->id]);
        }

        ActivityLog::log('session.customer_linked',
            "ربط جلسة طاولة {$session->table?->number} بالزبون {$customer->name}",
            $session
        );

        $this->reset(['query', 'newName', 'newEmail', 'generatedPin', 'generatedFor']);
        $this->dispatch('flash', type: 'success', message: "تم ربط الجلسة بالزبون {$customer->name}.");
        unset($this->session);
    }

    public function unlink(): void
    {
        $session = $this->session;
        if (! $session?->customer_id) return;

        $previousName = $session->customer?->name ?? '—';
        $session->update(['customer_id' => null]);
        $session->orders()->update(['customer_id' => null]);
        if ($session->invoice) {
            $session->invoice->update(['customer_id' => null]);
        }

        ActivityLog::log('session.customer_unlinked',
            "إلغاء ربط جلسة طاولة {$session->table?->number} (كانت مرتبطة بـ {$previousName})",
            $session
        );

        $this->dispatch('flash', type: 'info', message: "تم إلغاء الربط.");
        unset($this->session);
    }

    public function createCustomer(): void
    {
        $data = $this->validate([
            'newName' => ['required', 'string', 'max:120'],
            'query'   => ['required', 'string', 'max:32'],
            'newEmail' => ['nullable', 'email', 'max:255', 'unique:customers,email'],
        ], [
            'newName.required' => 'اكتب اسم الزبون.',
            'query.required'   => 'اكتب رقم هاتف الزبون.',
            'newEmail.email'   => 'البريد الإلكتروني غير صالح.',
            'newEmail.unique'  => 'البريد مستخدم مسبقاً — استخدم البحث لربط الحساب القائم.',
        ]);

        $session = $this->session;
        if (! $session) return;

        // Defensive uniqueness on phone — Customer's `unique` validator on
        // register catches this for the portal flow, but here the cashier
        // typed the phone freely so we re-check here.
        $normalized = preg_replace('/\D+/', '', $data['query']) ?? '';
        if ($existing = Customer::where('phone', $normalized)->orWhere('phone', $data['query'])->first()) {
            $this->dispatch('flash', type: 'info',
                message: "وجدنا زبون بهذا الرقم: {$existing->name}. تم ربطه تلقائياً.");
            $this->link($existing->id);
            return;
        }

        [$customer, $pin] = Customer::createFromCashier(
            name:  $data['newName'],
            phone: $data['query'],
            email: $data['newEmail'] ?: null,
            defaultBranchId: $session->branch_id,
        );

        // Surface the plaintext PIN once — kept on the component until the
        // cashier links/dismisses, then cleared from memory.
        $this->generatedPin = $pin;
        $this->generatedFor = $customer->id;

        $this->link($customer->id);
        // link() reset the PIN — restore so the banner stays visible.
        $this->generatedPin = $pin;
        $this->generatedFor = $customer->id;
    }

    public function dismissPin(): void
    {
        $this->generatedPin = null;
        $this->generatedFor = null;
    }
}
?>

<div class="ccl-wrap">
    {{-- Plaintext PIN banner — shown ONCE right after creation. --}}
    @if($this->generatedPin)
        <div class="alert alert-success ccl-pin">
            <div class="ccl-pin__head">
                <i class="bi bi-key-fill"></i>
                <strong>كلمة المرور المؤقتة للزبون</strong>
            </div>
            <div class="ccl-pin__code">{{ $this->generatedPin }}</div>
            <div class="ccl-pin__hint">
                اقرأها للزبون أو اكتبها على الفاتورة. ستختفي بعد الإغلاق ولن تظهر مرة أخرى.
                يقدر الزبون تغييرها من حسابه على البورتال.
            </div>
            <button type="button" wire:click="dismissPin" class="btn btn-sm btn-light mt-2">
                <i class="bi bi-check2"></i> فهمت — أخفِ الرمز
            </button>
        </div>
    @endif

    @if($this->session?->customer)
        {{-- Already linked --}}
        @php $c = $this->session->customer; @endphp
        <div class="ccl-linked">
            <div class="ccl-linked__avatar"
                 style="background: hsl({{ ($c->id * 47) % 360 }} 55% 48%);">
                {{ $c->initial }}
            </div>
            <div class="ccl-linked__body">
                <div class="ccl-linked__name">
                    <i class="bi bi-person-check-fill text-success"></i>
                    {{ $c->name }}
                </div>
                <div class="ccl-linked__meta">
                    <span dir="ltr">{{ $c->phone }}</span>
                    @if($c->email)<span>· {{ $c->email }}</span>@endif
                </div>
            </div>
            <button type="button" wire:click="unlink"
                    wire:confirm="إلغاء ربط الزبون من الجلسة؟"
                    class="btn btn-sm btn-outline-danger ccl-linked__unlink"
                    title="إلغاء الربط">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
    @else
        {{-- Search/create form --}}
        <div class="ccl-search">
            <label class="form-label fw-bold mb-1">
                <i class="bi bi-person-vcard"></i> ربط زبون
            </label>
            <input type="text" wire:model.live.debounce.500ms="query"
                   class="form-control" placeholder="🔍 رقم الهاتف أو الإيميل أو الاسم">

            @if(count($this->matches) > 0)
                <div class="ccl-results">
                    @foreach($this->matches as $m)
                        <button type="button" wire:click="link({{ $m->id }})" class="ccl-result">
                            <span class="ccl-result__avatar"
                                  style="background: hsl({{ ($m->id * 47) % 360 }} 55% 48%);">
                                {{ $m->initial }}
                            </span>
                            <span class="ccl-result__body">
                                <span class="ccl-result__name">{{ $m->name }}</span>
                                <span class="ccl-result__meta" dir="ltr">{{ $m->phone }}</span>
                            </span>
                            <i class="bi bi-link-45deg"></i>
                        </button>
                    @endforeach
                </div>
            @elseif(mb_strlen(trim($query)) >= 3)
                <div class="ccl-noresults">
                    <i class="bi bi-info-circle text-muted"></i>
                    لا يوجد زبون بهذا البحث. أنشئ حساب جديد:
                </div>

                <div class="ccl-create">
                    <input type="text" wire:model="newName"
                           class="form-control form-control-sm mb-2"
                           placeholder="اسم الزبون">
                    @error('newName') <div class="text-danger small mb-2">{{ $message }}</div> @enderror

                    <input type="email" wire:model="newEmail"
                           class="form-control form-control-sm mb-2"
                           placeholder="بريد إلكتروني (اختياري)">
                    @error('newEmail') <div class="text-danger small mb-2">{{ $message }}</div> @enderror

                    <button type="button" wire:click="createCustomer" class="btn btn-primary btn-sm w-100">
                        <i class="bi bi-person-plus-fill"></i>
                        إنشاء حساب وربطه بالجلسة
                    </button>
                </div>
            @else
                <div class="ccl-hint">
                    اكتب 3 أحرف على الأقل للبحث، أو رقم هاتف للمطابقة الذكية.
                </div>
            @endif
        </div>
    @endif
</div>

@once
<style>
    .ccl-wrap { display: flex; flex-direction: column; gap: 8px; }

    .ccl-pin {
        border-radius: 10px;
        border: 1px solid rgba(34,197,94,.4);
        background: linear-gradient(180deg, #ecfdf5, #ffffff);
    }
    .ccl-pin__head { font-size: .82rem; display: flex; align-items: center; gap: 6px; }
    .ccl-pin__code {
        font-family: 'Menlo', 'Consolas', monospace;
        font-size: 2rem;
        font-weight: 800;
        letter-spacing: .25em;
        color: #166534;
        text-align: center;
        margin: 8px 0;
        padding: 8px 0;
        background: #fff;
        border: 1px dashed #86efac;
        border-radius: 8px;
    }
    .ccl-pin__hint { font-size: .76rem; color: #166534; }

    .ccl-linked {
        display: flex; align-items: center; gap: 10px;
        padding: 10px 12px;
        background: #f0fdf4;
        border: 1px solid #bbf7d0;
        border-radius: 10px;
    }
    .ccl-linked__avatar {
        width: 38px; height: 38px;
        border-radius: 9px;
        color: #fff; font-weight: 800;
        display: inline-flex; align-items: center; justify-content: center;
        flex-shrink: 0;
    }
    .ccl-linked__body { flex: 1; min-width: 0; }
    .ccl-linked__name { font-weight: 700; color: #14532d; }
    .ccl-linked__meta { font-size: .76rem; color: #4b5563; }

    .ccl-search { padding: 8px 0; }
    .ccl-results { display: flex; flex-direction: column; gap: 4px; margin-top: 8px; }
    .ccl-result {
        display: flex; align-items: center; gap: 10px;
        width: 100%; padding: 8px 10px;
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        text-align: start; cursor: pointer;
        transition: background .12s, border-color .12s;
    }
    .ccl-result:hover { background: #f9fafb; border-color: rgba(var(--primary-rgb),.3); }
    .ccl-result__avatar {
        width: 30px; height: 30px;
        border-radius: 8px;
        color: #fff; font-weight: 800;
        display: inline-flex; align-items: center; justify-content: center;
        font-size: .82rem;
        flex-shrink: 0;
    }
    .ccl-result__body { flex: 1; min-width: 0; display: flex; flex-direction: column; }
    .ccl-result__name { font-size: .88rem; font-weight: 700; color: #1f2937; }
    .ccl-result__meta { font-size: .72rem; color: #6b7280; }
    .ccl-result .bi-link-45deg { color: #9ca3af; }

    .ccl-noresults {
        margin-top: 10px;
        padding: 8px 10px;
        background: #f9fafb;
        border-radius: 8px;
        font-size: .82rem;
    }
    .ccl-create {
        margin-top: 8px;
        padding: 10px;
        border: 1px dashed #d1d5db;
        border-radius: 8px;
    }

    .ccl-hint { margin-top: 6px; font-size: .76rem; color: #9ca3af; }
</style>
@endonce
