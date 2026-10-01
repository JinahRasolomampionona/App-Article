<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSiteRequest;
use App\Http\Requests\UpdateSiteRequest;
use App\Jobs\SynchronizeSiteJob;
use App\Models\ArticleStatusHistory;
use App\Models\SiteAgentAssignment;
use App\Models\User;
use App\Models\WordpressArticle;
use App\Models\WordpressSite;
use App\Services\Assignment\ArticleLockService;
use App\Services\QueueHealth;
use App\Services\QueueWorkerLauncher;
use App\Services\SiteContext;
use App\Services\WordPress\SiteConnectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Sites WordPress.
 *
 * L'Admin connecte les sites (identifiants dans `site_credentials`), les
 * teste, les modifie, les supprime et les assigne aux agents. Un site
 * assigné apparaît aussitôt dans l'espace de chaque agent concerné, qui peut
 * le synchroniser et en corriger les articles — sans rien connecter lui-même.
 */
class SiteController extends Controller
{
    public function __construct(
        protected SiteContext $context,
        protected SiteConnectionService $connection,
        protected QueueHealth $queue,
        protected QueueWorkerLauncher $worker,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', WordpressSite::class);

        $user = $request->user();

        $sites = WordpressSite::query()
            ->accessibleBy($user)
            ->with(['siteCredential', 'agentAssignments.user:id,name,is_active'])
            ->withCount(['articles', 'categories'])
            ->orderBy('name')
            ->get();

        return view('sites.index', [
            'sites' => $sites,
            'agents' => $user->isAdmin()
                ? User::query()->agents()->orderBy('name')->get(['id', 'name', 'is_active'])
                : collect(),
            // Travail de chaque agent par site, pour le suivi de l'Admin.
            'agentActivity' => $user->isAdmin() ? $this->agentActivity($sites->pluck('id')->all()) : [],
            // Sans worker, un « en cours » resterait affiché indéfiniment :
            // la vue doit pouvoir dire que rien n'avance.
            'queueStalled' => $this->queue->needsManualWorker(),
        ]);
    }

    /**
     * Extension WordPress « ArticleGuard Cache Bridge », en .zip prêt à
     * téléverser (Extensions › Ajouter › Téléverser une extension).
     */
    public function cacheBridge(): BinaryFileResponse
    {
        $source = resource_path('wordpress/articleguard-cache-bridge/articleguard-cache-bridge.php');
        $zipPath = storage_path('app/articleguard-cache-bridge.zip');

        if (! is_file($zipPath) || filemtime($zipPath) < filemtime($source)) {
            $zip = new \ZipArchive;
            $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
            $zip->addFile($source, 'articleguard-cache-bridge/articleguard-cache-bridge.php');
            $zip->close();
        }

        return response()->download($zipPath, 'articleguard-cache-bridge.zip', ['Content-Type' => 'application/zip']);
    }

    public function create(): View
    {
        $this->authorize('create', WordpressSite::class);

        return view('sites.create');
    }

    public function store(StoreSiteRequest $request): RedirectResponse
    {
        $site = new WordpressSite([
            'name' => $request->validated('name'),
            'url' => $request->normalizedUrl(),
            'wp_username' => $request->validated('wp_username'),
        ]);

        if (filled($request->validated('application_password'))) {
            $site->application_password = WordpressSite::normalizeApplicationPassword(
                $request->validated('application_password')
            );
        }

        $site->user_id = $request->user()->id;
        $site->save();

        $this->context->refresh();
        $this->context->remember($site);

        $result = $this->connection->test($site);

        if ($result['ok']) {
            // Même état que la synchronisation manuelle : la liste des sites
            // doit refléter qu'un travail est en attente, worker ou non.
            $site->forceFill(['sync_status' => 'queued', 'sync_message' => null])->save();

            SynchronizeSiteJob::dispatch($site);
            $this->worker->ensureRunning();

            return redirect()
                ->route('sites.index')
                ->with('status', 'Site connecté. La synchronisation initiale est en cours ; vous pouvez l’assigner à vos agents.');
        }

        return redirect()
            ->route('sites.edit', $site)
            ->with('error', $result['message']);
    }

    public function edit(WordpressSite $site): View
    {
        $this->authorize('update', $site);

        return view('sites.edit', ['site' => $site]);
    }

    public function update(UpdateSiteRequest $request, WordpressSite $site): RedirectResponse
    {
        $site->fill([
            'name' => $request->validated('name'),
            'url' => $request->normalizedUrl(),
            'wp_username' => $request->validated('wp_username'),
        ]);

        // Champ laissé vide : on conserve le secret déjà enregistré.
        if (filled($request->validated('application_password'))) {
            $site->application_password = WordpressSite::normalizeApplicationPassword(
                $request->validated('application_password')
            );
        }

        $site->save();
        $this->context->refresh();

        $this->connection->test($site);

        return redirect()
            ->route('sites.index')
            ->with('status', 'Site mis à jour.');
    }

    public function destroy(WordpressSite $site): RedirectResponse
    {
        $this->authorize('delete', $site);

        $site->delete();
        $this->context->forget();
        $this->context->refresh();

        return redirect()
            ->route('sites.index')
            ->with('status', 'Site supprimé ainsi que ses articles synchronisés et ses assignations.');
    }

    /**
     * Assigne le site aux agents cochés. Un nouvel agent démarre « En cours » ;
     * un agent décoché perd l'accès au site et ses articles en cours sont
     * libérés.
     */
    public function assign(Request $request, WordpressSite $site, ArticleLockService $locks): RedirectResponse
    {
        $this->authorize('assign', $site);

        $validated = $request->validate([
            'agents' => ['nullable', 'array'],
            'agents.*' => ['integer', Rule::exists('users', 'id')->where('role', User::ROLE_AGENT)],
        ], [
            'agents.*.exists' => 'Agent inconnu.',
        ]);

        $wanted = collect($validated['agents'] ?? [])->map(fn ($id) => (int) $id)->unique();
        $admin = $request->user();

        [$added, $removed] = DB::transaction(function () use ($site, $wanted, $admin, $locks) {
            // Seuls les agents « En cours » sont cochés : un agent « Terminé »
            // coché de nouveau est réassigné (nouvelle modif à faire).
            $current = $site->agentAssignments()
                ->where('status', SiteAgentAssignment::STATUS_IN_PROGRESS)
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id);

            $added = $wanted->diff($current);
            $removed = $current->diff($wanted);

            foreach ($added as $userId) {
                $site->agentAssignments()->updateOrCreate(['user_id' => $userId], [
                    'assigned_by' => $admin->id,
                    'status' => SiteAgentAssignment::STATUS_IN_PROGRESS,
                    'assigned_at' => now(),
                    'completed_at' => null,
                ]);
            }

            if ($removed->isNotEmpty()) {
                // Plus d'accès au site : ses articles en cours sont rendus.
                WordpressArticle::query()
                    ->where('wordpress_site_id', $site->id)
                    ->whereIn('assigned_to', $removed->all())
                    ->get()
                    ->each(fn (WordpressArticle $article) => $locks->release($article, $admin));

                $site->agentAssignments()->whereIn('user_id', $removed->all())->get()->each->delete();
            }

            return [$added, $removed];
        });

        Log::info('Assignation de site modifiée.', [
            'site_id' => $site->id,
            'added' => $added->values()->all(),
            'removed' => $removed->values()->all(),
            'by' => $admin->id,
        ]);

        return redirect()
            ->route('sites.index')
            ->with('status', match (true) {
                $added->isEmpty() && $removed->isEmpty() => 'Aucun changement d’assignation.',
                default => 'Assignations de « '.$site->name.' » mises à jour.',
            });
    }

    /**
     * « Terminer » le travail d'un agent sur le site : le site disparaît de
     * son espace et ses articles en cours sont libérés. L'Admin peut le lui
     * réassigner (« Réassigner », ou en le cochant de nouveau) s'il reste une
     * modification à faire.
     */
    public function assignmentStatus(Request $request, WordpressSite $site, User $user, ArticleLockService $locks): JsonResponse|RedirectResponse
    {
        $this->authorize('assign', $site);

        $validated = $request->validate([
            'status' => ['required', Rule::in([SiteAgentAssignment::STATUS_IN_PROGRESS, SiteAgentAssignment::STATUS_DONE])],
        ]);

        $assignment = $site->agentAssignments()->where('user_id', $user->id)->firstOrFail();

        $done = $validated['status'] === SiteAgentAssignment::STATUS_DONE;
        $admin = $request->user();

        DB::transaction(function () use ($assignment, $done, $site, $user, $admin, $locks) {
            $assignment->forceFill($done
                ? ['status' => SiteAgentAssignment::STATUS_DONE, 'completed_at' => now()]
                : ['status' => SiteAgentAssignment::STATUS_IN_PROGRESS, 'completed_at' => null, 'assigned_at' => now(), 'assigned_by' => $admin->id]
            )->save();

            if ($done) {
                // Plus d'accès au site : ses articles en cours sont rendus.
                WordpressArticle::query()
                    ->where('wordpress_site_id', $site->id)
                    ->where('assigned_to', $user->id)
                    ->get()
                    ->each(fn (WordpressArticle $article) => $locks->release($article, $admin));
            }
        });

        Log::info('État d’assignation de site modifié.', [
            'site_id' => $site->id,
            'agent_id' => $user->id,
            'status' => $assignment->status,
            'by' => $admin->id,
        ]);

        $message = $done
            ? 'Travail de '.$user->name.' sur « '.$site->name.' » terminé : le site n’apparaît plus dans son espace.'
            : '« '.$site->name.' » réassigné à '.$user->name.'.';

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'status' => $assignment->status,
                'label' => $assignment->statusLabel(),
                'message' => $message,
            ]);
        }

        return redirect()->route('sites.index')->with('status', $message);
    }

    /**
     * Test de connexion en AJAX depuis la liste des sites.
     */
    public function test(WordpressSite $site): JsonResponse
    {
        $this->authorize('test', $site);

        $result = $this->connection->test($site);

        return response()->json([
            'ok' => $result['ok'],
            'message' => $result['message'],
            'status' => $site->connection_status,
            'status_label' => $site->statusLabel(),
            'status_variant' => $site->statusVariant(),
            'checked_at' => $site->last_checked_at?->diffForHumans(),
        ], $result['ok'] ? 200 : 422);
    }

    /**
     * Lance la synchronisation en arrière-plan.
     */
    public function sync(WordpressSite $site): JsonResponse
    {
        $this->authorize('sync', $site);

        // Déjà en cours (worker ou `wp:sync`) : relancer ne ferait qu'empiler
        // un second job concurrent sur le même site.
        if ($site->isSyncRunning()) {
            return response()->json([
                'ok' => true,
                'status' => $site->sync_status,
                'already_running' => true,
                'message' => 'La synchronisation est déjà lancée. Patientez quelques minutes.',
                'queue_warning' => null,
            ]);
        }

        $site->forceFill(['sync_status' => 'queued', 'sync_message' => null])->save();

        SynchronizeSiteJob::dispatch($site);
        $this->worker->ensureRunning();

        return response()->json([
            'ok' => true,
            'status' => $site->fresh()->sync_status,
            'message' => 'Synchronisation lancée. Les articles apparaîtront au fur et à mesure.',
            'queue_warning' => $this->queue->warning(),
        ]);
    }

    /**
     * Sondage de progression, appelé par l'interface pendant une synchronisation.
     */
    public function syncStatus(WordpressSite $site): JsonResponse
    {
        $this->authorize('view', $site);

        $site->loadCount(['articles', 'categories']);

        // File bloquée (worker arrêté entre-temps) : on en relance un plutôt
        // que de laisser l'utilisateur taper une commande.
        if ($this->queue->isStalled()) {
            $this->worker->ensureRunning();
        }

        return response()->json([
            'sync_status' => $site->sync_status,
            'sync_message' => $site->sync_message,
            'articles_count' => $site->articles_count,
            'categories_count' => $site->categories_count,
            'last_sync_at' => $site->last_sync_at?->diffForHumans(),
            'connection_status' => $site->connection_status,
            'status_label' => $site->statusLabel(),
            'status_variant' => $site->statusVariant(),
            // Sans worker, `sync_status` resterait « queued » indéfiniment :
            // l'interface doit pouvoir le dire au lieu de tourner dans le vide.
            // Un site « running » est déjà pris en charge : seul un « queued »
            // peut attendre un worker absent.
            'queue_stalled' => $site->sync_status === 'queued' && $this->queue->needsManualWorker(),
            'queue_warning' => $site->sync_status === 'queued' ? $this->queue->warning() : null,
        ]);
    }

    /**
     * Changement de site courant depuis le sélecteur du header.
     */
    public function select(Request $request, WordpressSite $site): JsonResponse|RedirectResponse
    {
        $this->authorize('view', $site);

        $this->context->remember($site);

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'site_id' => $site->id]);
        }

        return back();
    }

    /**
     * Corrections et articles en cours, par site et par agent.
     *
     * @param  array<int, int>  $siteIds
     * @return array<int, array<int, array{fixed: int, in_progress: int}>>
     */
    protected function agentActivity(array $siteIds): array
    {
        if ($siteIds === []) {
            return [];
        }

        $activity = [];

        ArticleStatusHistory::query()
            ->whereIn('wordpress_site_id', $siteIds)
            ->whereNotNull('agent_user_id')
            ->where('status', WordpressArticle::AUDIT_FIXED)
            ->selectRaw('wordpress_site_id, agent_user_id, count(*) as total')
            ->groupBy('wordpress_site_id', 'agent_user_id')
            ->get()
            ->each(function ($row) use (&$activity) {
                $activity[(int) $row->wordpress_site_id][(int) $row->agent_user_id]['fixed'] = (int) $row->total;
            });

        WordpressArticle::query()
            ->whereIn('wordpress_site_id', $siteIds)
            ->locked()
            ->selectRaw('wordpress_site_id, assigned_to, count(*) as total')
            ->groupBy('wordpress_site_id', 'assigned_to')
            ->get()
            ->each(function ($row) use (&$activity) {
                $activity[(int) $row->wordpress_site_id][(int) $row->assigned_to]['in_progress'] = (int) $row->total;
            });

        return $activity;
    }
}
