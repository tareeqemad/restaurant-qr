<?php

namespace Tests\Feature;

use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

class PaginationUiTest extends TestCase
{
    public function test_laravel_paginator_serializes_real_arabic_endpoint_labels(): void
    {
        app()->setLocale('ar');
        LengthAwarePaginator::currentPageResolver(fn () => 1);

        $links = (new LengthAwarePaginator(range(1, 5), 20, 5))->toArray()['links'];

        $this->assertSame('السابق', $links[0]['label']);
        $this->assertSame('التالي', $links[array_key_last($links)]['label']);
        $this->assertSame('السابق', __('pagination.previous'));
        $this->assertSame('التالي', __('pagination.next'));
    }

    public function test_shared_pagination_component_owns_rtl_copy_and_accessibility(): void
    {
        $source = file_get_contents(resource_path('js/Components/Ui/Pagination.vue'));

        $this->assertStringContainsString('dir="rtl"', $source);
        $this->assertStringContainsString('aria-current="page"', $source);
        $this->assertStringContainsString('الصفحة الحالية', $source);
        $this->assertStringContainsString('السابق', $source);
        $this->assertStringContainsString('التالي', $source);
        $this->assertStringContainsString('@media (max-width: 720px)', $source);
        $this->assertStringNotContainsString('v-html', $source);
    }

    public function test_vue_pages_do_not_ship_a_second_bootstrap_paginator(): void
    {
        $files = collect(glob(resource_path('js/Pages/**/*.vue')))
            ->merge(glob(resource_path('js/Pages/**/**/*.vue')))
            ->unique();

        foreach ($files as $file) {
            $source = file_get_contents($file);
            $this->assertStringNotContainsString(
                'class="pagination"',
                $source,
                str_replace(resource_path(), '', $file).' must use Components/Ui/Pagination.vue'
            );
            $this->assertStringNotContainsString(
                'class="pagination ',
                $source,
                str_replace(resource_path(), '', $file).' must use Components/Ui/Pagination.vue'
            );
        }
    }
}
