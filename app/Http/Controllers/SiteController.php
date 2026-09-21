<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSiteRequest;
use App\Http\Requests\UpdateSiteRequest;
use App\Jobs\SynchronizeSiteJob;
use App\Models\WordpressSite;
use App\Services\QueueHealth;
use App\Services\QueueWorkerLauncher;
use App\Services\SiteContext;
use App\Services\WordPress\SiteConnectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SiteController extends Controller
{
    public function __construct(
        protected SiteContext $context,
        protected SiteConnectionService $connection,
        protected QueueHealth $queue,
        protected QueueWorkerLauncher $worker,
    ) {}

    public function index(): View
    {
        $this->authorize('viewAny', WordpressSite::class);

        $sites = auth()->user()->sites()
            ->withCount(['articles', 'categories'])
            ->orderBy('name')
            ->get();

        return view('sites.index', [
            'sites' => $sites,
            // Sans worker, un « en cours » resterait affiché indéfiniment :
            // la vue doit pouvoir dire que rien n'avance.
            'queueStalled' => $this->queue->needsManualWorker(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', WordpressSite::class);

        return view('sites.create');
    }

    public function store(StoreSiteRequest $request): RedirectResponse
    {
        $this->authorize('create', WordpressSite::class);

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
                ->with('status', 'Site connecté. La synchronisation initiale est en cours.');
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
            ->with('status', 'Site supprimé ainsi que ses articles synchronisés.');
    }

    /**
     * Test de connexion en AJAX depuis la liste des sites.
     */
    public function test(WordpressSite $site): JsonResponse
    {
        $this->authorize('update', $site);

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
            'queue_stalled' => $this->queue->needsManualWorker(),
            'queue_warning' => $this->queue->warning(),
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
}
