<?php

namespace App\Providers;

use App\AI\Contracts\ModelProvider;
use App\AI\Providers\LaravelAiProvider;
use App\Contracts\CanvaClient as CanvaClientContract;
use App\Contracts\CredentialResolver;
use App\Contracts\IntegrationWebhookVerifier;
use App\Contracts\KnowledgeEmbeddingProvider;
use App\Contracts\KnowledgeRetrievalProvider;
use App\Contracts\MediaGenerator;
use App\Contracts\MediaRenderer;
use App\Contracts\MediaStorage;
use App\Contracts\PublishingProvider;
use App\Events\AgentExecutionEvent;
use App\Listeners\RecordAgentExecutionEvent;
use App\Models\Competitor;
use App\Models\Mission;
use App\Models\Vision;
use App\Models\Workflow;
use App\Policies\StrategicRecordPolicy;
use App\Policies\WorkflowPolicy;
use App\Services\DeterministicKnowledgeEmbeddingProvider;
use App\Services\FailureTranslator;
use App\Services\HmacIntegrationWebhookVerifier;
use App\Services\KnowledgeHybridRetrievalProvider;
use App\Services\KnowledgeLexicalRetrievalProvider;
use App\Services\KnowledgeSemanticRetrievalProvider;
use App\Services\R2MediaStorage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
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
        $this->app->singleton(FailureTranslator::class);
        $this->app->singleton(KnowledgeEmbeddingProvider::class, DeterministicKnowledgeEmbeddingProvider::class);
        $this->app->singleton(KnowledgeRetrievalProvider::class, function ($app): KnowledgeRetrievalProvider {
            return new KnowledgeHybridRetrievalProvider(
                new KnowledgeLexicalRetrievalProvider,
                new KnowledgeSemanticRetrievalProvider($app->make(KnowledgeEmbeddingProvider::class)),
            );
        });
        $this->app->bind(MediaStorage::class, R2MediaStorage::class);
        $this->app->singleton(IntegrationWebhookVerifier::class, HmacIntegrationWebhookVerifier::class);
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
        Event::listen(AgentExecutionEvent::class, [RecordAgentExecutionEvent::class, 'handle']);
        Gate::policy(Competitor::class, StrategicRecordPolicy::class);
        Gate::policy(Mission::class, StrategicRecordPolicy::class);
        Gate::policy(Vision::class, StrategicRecordPolicy::class);
        Gate::policy(Workflow::class, WorkflowPolicy::class);\n        Gate::policy(WorkflowExecution::class, WorkflowExecutionPolicy::class);
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
