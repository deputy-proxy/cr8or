<?php

namespace App\Providers;

use App\AI\Contracts\ModelProvider;
use App\AI\Providers\LaravelAiProvider;
use App\Contracts\CanvaClient as CanvaClientContract;
use App\Contracts\CredentialResolver;
use App\Contracts\MediaGenerator;
use App\Contracts\MediaRenderer;
use App\Contracts\MediaStorage;
use App\Contracts\PublishingProvider;
use App\Services\FilesystemMediaStorage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ModelProvider::class, LaravelAiProvider::class);
        $this->app->bind(MediaStorage::class, FilesystemMediaStorage::class);
        $this->app->singleton(PublishingProvider::class, function ($app): PublishingProvider {
            return $app->environment('testing')
                ? new FakePublishingProvider
                : new PostizPublishingProvider;
        });
        $this->app->singleton(CredentialResolver::class, CanvaCredentialResolver::class);
        $this->app->singleton(CanvaClientContract::class, function ($app): CanvaClientContract {
            return $app->environment('testing')
                ? new FakeCanvaClient
                : new CanvaClient($app->make(CredentialResolver::class));
        });
        $this->app->bind(MediaGenerator::class, function (): MediaGenerator {
            return new class implements MediaGenerator
            {
                public function generate(\App\Models\GenerationRequest $request): array
                {
                    throw new \LogicException('No media generator is configured. External generation must be supplied by an execution worker.');
                }
            };
        });
        $this->app->bind(MediaRenderer::class, function (): MediaRenderer {
            return new class implements MediaRenderer
            {
                public function render(\App\Models\RenderRequest $request): array
                {
                    throw new \LogicException('No media renderer is configured. External rendering must be supplied by an execution worker.');
                }
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Passport::authorizationView('mcp.authorize');

        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}