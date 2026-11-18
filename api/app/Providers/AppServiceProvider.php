<?php

namespace App\Providers;

use App\Services\Erp\ErpManager;
use App\Services\Llm\LlmManager;
use App\Services\Ocr\OcrManager;
use App\Support\Tenant;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Tenant::class);
        $this->app->singleton(OcrManager::class);
        $this->app->singleton(LlmManager::class);
        $this->app->singleton(ErpManager::class);
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Per user when signed in, per IP otherwise. Limits in config/extractiq.php (security.rate_limits).
        $by = fn (Request $r) => $r->user()?->id ? 'u:'.$r->user()->id : 'ip:'.$r->ip();
        foreach (['api', 'uploads', 'exports'] as $name) {
            RateLimiter::for($name, fn (Request $r) => Limit::perMinute((int) config("extractiq.security.rate_limits.$name"))->by($name.'|'.$by($r)));
        }
        // Login: per email + IP, so one attacker can't lock out everyone behind the same NAT.
        RateLimiter::for('login', fn (Request $r) => Limit::perMinute((int) config('extractiq.security.rate_limits.login'))
            ->by('login|'.mb_strtolower((string) $r->input('email')).'|'.$r->ip()));
    }
}
