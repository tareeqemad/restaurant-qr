<?php

namespace Tests;

use App\Models\Setting;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        try {
            if (Schema::hasTable('settings') && ! Setting::query()->where('key', 'setup_completed')->exists()) {
                Setting::put('setup_completed', true, 'system', 'bool');
            }
        } catch (\Throwable) {
            //
        }
    }
}
