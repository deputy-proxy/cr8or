<?php

namespace App\Providers;

use App\AI\Contracts\ModelProvider;
use App\AI\Providers\LaravelAiProvider;
use App\Contracts\CanvaClient as CanvaClientContract;
use App\Contracts\CommandWebhookAuthenticator;
use App\Contracts\CredentialResolver;
use App\Contracts\IntegrationWebhookVerifier;
use App\Contracts\KnowledgeEmbeddingProvider;
use App\Contracts\KnowledgeRetrievalProvider;
use App\Contracts\MediaGenerator;
use App\Contracts\MediaRenderer;
use App\Contracts\MediaStorage;
use App\Events\AgentExecutionEvent;
use App\Listeners\RecordAgentExecutionEvent;
use App\Listeners\RecordEnterpriseActivityEvent;
use App\Models\Campaign;
use App\Models\Competitor;
use App\Models\ContentItem;
use App\Models\Enterprise;
use App\Models\EnterpriseContext;
use App\Models\IntegrationConnection;
use App\Models\IntegrationJob;
use App\Models\IntegrationResult;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Publication;
use App\Models\PublicationResult;
use App\Models\PublicationSchedule;
use App\Models\Task;
use App\Models\Workflow;
use App\Models\WorkflowExecution;
use App\Observers\EnterpriseActivityObserver;
use App\Policies\CompetitorPolicy;
use App\Policies\SocialAuthorizationAttemptPolicy;
use App\Policies\SocialAuthorizationAuditPolicy;
use App\Policies\SocialCredentialPolicy;
use App\Policies\WorkflowExecutionPolicy;
use App\Policies\WorkflowPolicy;
use App\Services\DeterministicKnowledgeEmbeddingProvider;
use App\Services\FailureTranslator;
use App\Services\HmacCommandWebhookAuthenticator;
use App\Services\HmacIntegrationWebhookVerifier;
use App\Services\KnowledgeHybridRetrievalProvider;
use App\Services\KnowledgeLexicalRetrievalProvider;
use App\Services\KnowledgeSemanticRetrievalProvider;
use App\Services\PublishingAdapterRegistry;
use App\Services\R2MediaStorage;
use Carbon\CarbonImmutable;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
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
        $this->app->singleton(CommandWebhookAuthenticator::class, HmacCommandWebhookAuthenticator::class);
        $this->app->singleton(PublishingAdapterRegistry::class);
        $this->app->singleton(CredentialResolver::class, function ($app): CredentialResolver {
            return new ProviderCredentialResolver([
                'canva' => $app->make(CanvaCredentialResolver::class),
                'github' => $app->make(GitHubCredentialResolver::class),
                'social' => $app->make(SocialCredentialResolver::class),
            ], config('social_authorization.credential_resolvers', []));
        });
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
        HandleCors::skipWhen(static fn (Request $request): bool => $request->is('api/events/website'));

        $this->configureDefaults();
        Event::listen(AgentExecutionEvent::class, [RecordAgentExecutionEvent::class, 'handle']);
        foreach ([
            \App\Events\AgentExecutionCompleted::class,
            \App\Events\AgentExecutionFailed::class,
            \App\Events\AgentExecutionStarted::class,
            \App\Events\ApprovalGranted::class,
            \App\Events\ApprovalRequested::class,
            \App\Events\OperationExecuted::class,
        ] as $eventClass) {
            Event::listen($eventClass, [RecordEnterpriseActivityEvent::class, 'handleAgent']);
        }
        Event::listen(\App\Events\StrategicContextVersionPublished::class, [RecordEnterpriseActivityEvent::class, 'handleDomain']);
        foreach ([Enterprise::class, EnterpriseContext::class, Project::class, Task::class, Milestone::class, Campaign::class, ContentItem::class, Publication::class, PublicationResult::class, PublicationSchedule::class, IntegrationConnection::class, IntegrationJob::class, IntegrationResult::class, WorkflowExecution::class] as $model) {
            $model::observe(EnterpriseActivityObserver::class);
        }
        Gate::policy(Competitor::class, CompetitorPolicy::class);
        Gate::policy(\App\Models\SocialAuthorizationAttempt::class, SocialAuthorizationAttemptPolicy::class);
        Gate::policy(\App\Models\SocialAuthorizationAudit::class, SocialAuthorizationAuditPolicy::class);
        Gate::policy(\App\Models\SocialCredential::class, SocialCredentialPolicy::class);
        Gate::policy(Workflow::class, WorkflowPolicy::class);
        Gate::policy(WorkflowExecution::class, WorkflowExecutionPolicy::class);
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