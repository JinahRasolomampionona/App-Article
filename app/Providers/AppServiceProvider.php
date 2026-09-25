<?php

namespace App\Providers;

use App\Models\User;
use App\Models\WordpressArticle;
use App\Models\WordpressSite;
use App\Policies\UserPolicy;
use App\Policies\WordpressArticlePolicy;
use App\Support\AgentCatalog;
use App\Policies\WordpressSitePolicy;
use App\Services\Audit\AuditService;
use App\Services\Audit\Relevance\HeuristicImageRelevanceAnalyzer;
use App\Services\Audit\Relevance\ImageRelevanceAnalyzerInterface;
use App\Services\Audit\Relevance\NullImageRelevanceAnalyzer;
use App\Services\Audit\Rules\BodyImageRule;
use App\Services\Audit\Rules\BrokenImageRule;
use App\Services\Audit\Rules\FeaturedImageRule;
use App\Services\Audit\Rules\H1Rule;
use App\Services\Audit\Rules\H2Rule;
use App\Services\Audit\Rules\ImageBlurRule;
use App\Services\Audit\Rules\ImageRelevanceRule;
use App\Services\Audit\Rules\LongTitleRule;
use App\Services\Audit\Rules\ShortcodeRule;
use App\Services\QueueHealth;
use App\Services\QueueWorkerLauncher;
use App\Services\SiteContext;
use Illuminate\Pagination\Paginator;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Ordre d'exécution des règles d'audit. Les règles locales passent avant
     * les règles réseau, afin que les remarques les plus rapides à obtenir
     * soient produites en premier.
     *
     * @var array<int, class-string<\App\Services\Audit\Rules\AuditRule>>
     */
    protected array $auditRules = [
        FeaturedImageRule::class,
        BodyImageRule::class,
        ShortcodeRule::class,
        LongTitleRule::class,
        H1Rule::class,
        H2Rule::class,
        BrokenImageRule::class,
        ImageBlurRule::class,
        ImageRelevanceRule::class,
    ];

    public function register(): void
    {
        // Portée requête : la liste des sites n'est chargée qu'une fois par
        // requête, quel que soit le nombre de composants qui la consultent.
        $this->app->scoped(SiteContext::class);

        $this->app->bind(ImageRelevanceAnalyzerInterface::class, function () {
            return match (config('articleguard.relevance.driver')) {
                'heuristic' => new HeuristicImageRelevanceAnalyzer,
                // Un fournisseur de vision distant viendra s'enregistrer ici ;
                // tant qu'aucune clé n'est configurée, l'analyse reste inactive.
                default => new NullImageRelevanceAnalyzer,
            };
        });

        $this->app->singleton(AuditService::class, function ($app) {
            return new AuditService(
                ...array_map(fn (string $rule) => $app->make($rule), $this->auditRules)
            );
        });
    }

    public function boot(): void
    {
        Gate::policy(WordpressSite::class, WordpressSitePolicy::class);
        Gate::policy(WordpressArticle::class, WordpressArticlePolicy::class);
        Gate::policy(User::class, UserPolicy::class);

        // Pages réservées à l'Admin : gestion des comptes, sites, paramètres.
        Gate::define('admin', fn (User $user) => $user->isAdmin());

        AgentCatalog::flush();

        // Le sélecteur de site et la sidebar sont présents sur toutes les pages
        // applicatives : leurs données sont résolues une seule fois par requête.
        View::composer('layouts.app', function ($view) {
            $context = app(SiteContext::class);
            $current = $context->current();

            $view->with([
                'navSites' => $context->sites(),
                'navCurrentSite' => $current,
                // Compté une fois par requête : la sidebar est rendue deux fois
                // (version fixe et version offcanvas).
                'navNeedsFix' => $current
                    ? $current->articles()->where('audit_status', WordpressArticle::AUDIT_NEEDS_FIX)->count()
                    : 0,
                // Une file sans worker ne produit ni articles ni audits : il
                // vaut mieux l'annoncer que laisser l'utilisateur attendre.
                'queueWarning' => app(QueueHealth::class)->warning(),
            ]);
        });

        Paginator::useBootstrapFive();

        // Signal de vie du worker (voir QueueWorkerLauncher::ALIVE_KEY).
        Event::listen([Looping::class, JobProcessing::class, JobProcessed::class], fn () => QueueWorkerLauncher::heartbeat());
        Event::listen(WorkerStopping::class, fn () => QueueWorkerLauncher::stopped());
    }
}
