<?php

namespace Tests;

use App\Services\SettingsService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    protected string $systemSettingsPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->systemSettingsPath = sys_get_temp_dir().'/soloops-settings-'.Str::uuid().'.json';
        $this->app->when(SettingsService::class)->needs('$path')->give($this->systemSettingsPath);
        $this->beforeApplicationDestroyed(fn () => File::delete([$this->systemSettingsPath, $this->systemSettingsPath.'.lock']));
    }
}
