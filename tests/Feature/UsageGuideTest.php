<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\User;
use App\Support\BranchContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UsageGuideTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create([
            'code' => 'guide',
            'name' => 'فرع الدليل',
            'is_active' => true,
        ]);
        BranchContext::set($this->branch->id);

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    public function test_every_staff_role_can_open_the_built_in_guide(): void
    {
        foreach (UserRole::cases() as $index => $role) {
            $user = User::create([
                'name' => $role->label(),
                'username' => 'guide-user-'.$index,
                'role' => $role->value,
                'status' => 'active',
                'password' => bcrypt('password'),
                'primary_branch_id' => $this->branch->id,
            ]);
            $user->branches()->attach($this->branch->id, ['is_primary' => true, 'joined_at' => now()]);

            $this->actingAs($user)
                ->withSession(['active_branch_id' => $this->branch->id])
                ->get(route('admin.usage-guide'))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Admin/Guide/Index')
                    ->where('viewer.role', $role->value)
                    ->where('viewer.roleLabel', $role->label())
                    ->has('roles', count(UserRole::cases()))
                    ->where('shell.urls.usageGuide', route('admin.usage-guide'))
                    ->where('shell.nav', fn ($nav) => collect($nav)->doesntContain('label', 'دليل الاستخدام'))
                );
        }
    }

    public function test_guide_is_presented_under_profile_in_the_user_menu(): void
    {
        $header = file_get_contents(resource_path('js/Components/AdminShell/AdminHeader.vue'));
        $componentsCss = file_get_contents(public_path('assets/dashtic/css/relax-components.css'));
        $profilePosition = strpos($header, 'الملف الشخصي');
        $guidePosition = strpos($header, 'دليل الاستخدام');
        $logoutPosition = strpos($header, 'تسجيل الخروج');

        $this->assertNotFalse($profilePosition);
        $this->assertNotFalse($guidePosition);
        $this->assertNotFalse($logoutPosition);
        $this->assertTrue($profilePosition < $guidePosition);
        $this->assertTrue($guidePosition < $logoutPosition);
        $this->assertStringContainsString('top: calc(100% + 10px) !important;', $header);
        $this->assertStringNotContainsString('top: auto !important;', $header);
        $this->assertStringContainsString('html body .app-header .profile-dd .profile-menu', $componentsCss);
    }

    public function test_guide_only_exposes_quick_links_the_viewer_can_open(): void
    {
        $waiter = User::create([
            'name' => 'جرسون الدليل',
            'username' => 'guide-waiter',
            'role' => UserRole::Waiter->value,
            'status' => 'active',
            'password' => bcrypt('password'),
            'primary_branch_id' => $this->branch->id,
        ]);
        $waiter->branches()->attach($this->branch->id, ['is_primary' => true, 'joined_at' => now()]);

        $this->actingAs($waiter)
            ->withSession(['active_branch_id' => $this->branch->id])
            ->get(route('admin.usage-guide'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('urls.tables', route('admin.tables.index'))
                ->where('urls.serviceBoard', route('admin.orders.index'))
                ->where('urls.accounting', null)
                ->where('urls.settings', null)
                ->where('urls.cashier', null)
            );
    }

    public function test_accountant_receives_the_accounting_shortcuts(): void
    {
        $accountant = User::create([
            'name' => 'محاسب الدليل',
            'username' => 'guide-accountant',
            'role' => UserRole::Accountant->value,
            'status' => 'active',
            'password' => bcrypt('password'),
            'primary_branch_id' => $this->branch->id,
        ]);
        $accountant->branches()->attach($this->branch->id, ['is_primary' => true, 'joined_at' => now()]);

        $this->actingAs($accountant)
            ->withSession(['active_branch_id' => $this->branch->id])
            ->get(route('admin.usage-guide'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('urls.accounting', route('admin.accounting.index'))
                ->where('urls.accountingGuide', route('admin.accounting.guide'))
                ->where('urls.openingBalances', route('admin.accounting.opening-balances'))
                ->where('urls.cashier', route('admin.cashier.index'))
            );
    }

    public function test_guest_is_redirected_from_the_guide(): void
    {
        $this->get(route('admin.usage-guide'))->assertRedirect();
    }

    public function test_guide_source_contains_the_critical_operating_scenarios(): void
    {
        $content = file_get_contents(resource_path('js/Pages/Admin/Guide/guideContent.js'));

        $this->assertStringContainsString('طاولة تقول عليها 4 شيكل', $content);
        $this->assertStringContainsString('يوجد نداء للجرسون', $content);
        $this->assertStringContainsString('المخازن والوحدات والرصيد الافتتاحي', $content);
        $this->assertStringContainsString('الديون والاسترداد والإلغاء والشطب', $content);
        $this->assertStringContainsString('المحاسبة من الافتتاح إلى الإقفال', $content);
        $this->assertStringContainsString('كيف يصنع النظام القيد ويسجله؟', $content);
        $this->assertStringContainsString('دليل الترحيل التلقائي الكامل', $content);
        $this->assertStringContainsString('مثال رقمي: بيع وتحصيل ومخزون', $content);
        $this->assertStringContainsString('مثال رقمي: إشعار دائن واسترداد', $content);
        $this->assertStringContainsString('مثال رقمي: شراء مخزون من مورد', $content);
        $this->assertStringContainsString('كيف يدقق المحاسب قيداً من البداية للنهاية؟', $content);
        $this->assertStringContainsString('قائمة مراجعة المحاسب اليومية', $content);
        $this->assertStringContainsString('إيراد المبيعات 100 + إيراد الخدمة 5', $content);
        $this->assertStringContainsString('الصندوق 60', $content);
        $this->assertStringContainsString('ذمم العملاء 35', $content);
    }

    public function test_guide_has_a_complete_a4_print_document_and_reading_controls(): void
    {
        $page = file_get_contents(resource_path('js/Pages/Admin/Guide/Index.vue'));

        $this->assertStringContainsString('class="print-cover"', $page);
        $this->assertStringContainsString('class="print-toc"', $page);
        $this->assertStringContainsString('size: A4 portrait;', $page);
        $this->assertStringContainsString('@bottom-center', $page);
        $this->assertStringContainsString("window.addEventListener('beforeprint', preparePrint)", $page);
        $this->assertStringContainsString("window.addEventListener('afterprint', finishPrint)", $page);
        $this->assertStringContainsString('setAllChapters(true)', $page);
        $this->assertStringContainsString('.guide-chapter:not([open]) > .chapter-body { display: block !important;', $page);
        $this->assertStringContainsString('طباعة / حفظ PDF', $page);
    }

    public function test_guide_starts_with_a_role_based_reading_path_instead_of_the_full_reference(): void
    {
        $page = file_get_contents(resource_path('js/Pages/Admin/Guide/Index.vue'));

        $this->assertStringContainsString('const roleReadingPaths = {', $page);
        $this->assertStringContainsString("waiter: ['service-flow'", $page);
        $this->assertStringContainsString("chef: ['service-flow'", $page);
        $this->assertStringContainsString("bartender: ['service-flow'", $page);
        $this->assertStringContainsString("cashier: ['cashier-table'", $page);
        $this->assertStringContainsString("accountant: ['accounting'", $page);
        $this->assertStringContainsString('ابدأ القراءة من هنا', $page);
        $this->assertStringContainsString('لا تقرأ كل الدليل', $page);
        $this->assertStringContainsString('مساري فقط', $page);
        $this->assertStringContainsString('v-if="canSeeSetupChecklist && !query"', $page);
        $this->assertStringContainsString('الفصل التالي:', $page);

        $chapterList = strpos($page, 'class="chapter-list"');
        $optionalPermissions = strpos($page, 'class="permissions-card permissions-card--reference"');
        $this->assertNotFalse($chapterList);
        $this->assertNotFalse($optionalPermissions);
        $this->assertTrue($chapterList < $optionalPermissions);
    }
}
