<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSiteRequest;
use App\Http\Requests\UpdateSiteRequest;
use App\Jobs\SynchronizeSiteJob;
use App\Models\SiteConnection;
use App\Models\WordpressSite;
use App\Services\QueueHealth;
use App\Services\QueueWorkerLauncher;
use App\Services\SiteContext;
use App\Services\WordPress\SiteConnectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Sites WordPress.
 *
 * Chaque compte — Admin ou Agent — connecte ses propres sites avec ses
 * propres identifiants et gère sa connexion (tester, synchroniser, modifier,
 * supprimer). Un même site connecté par plusieurs comptes partage ses
 * articles : c'est ce qui permet à l'Admin de suivre le travail des agents.
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

        // Les sites de l'utilisateur : ceux qu'il a lui-même connectés.
        $sites = WordpressSite::query()
            ->whereHas('connections', fn ($query) => $query->where('user_id', $user->id))
            ->with(['connections.user:id,name'])
            ->withCount(['articles', 'categories'])
            ->orderBy('name')
            ->get();

        // L'Admin suit aussi les sites connectés uniquement par les agents.
        $otherSites = $user->isAdmin()
            ? WordpressSite::query()
                ->whereDoesntHave('connections', fn ($query) => $query->where('user_id', $user->id))
                ->with(['connections.user:id,name'])
                ->withCount(['articles', 'categories'])
                ->orderBy('name')
                ->get()
            : collect();

        return view('sites.index', [
            'sites' => $sites,
            'otherSites' => $otherSites,
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

    /**
     * Connecte un site pour l'utilisateur.
     *
     * Si un autre compte l'a déjà connecté, le site existant est rejoint :
     * l'utilisateur y ajoute sa propre connexion et travaille sur les mêmes
     * articles, avec ses propres identifiants.
     */
    public function store(StoreSiteRequest $request): RedirectResponse
    {
        $this->authorize('create', WordpressSite::class);

        $user = $request->user();
        $existing = $request->existingSite();

        $site = DB::transaction(function () use ($request, $user, $existing) {
            if ($existing !== null) {
                $site = $existing->useConnection(new SiteConnection([
                    'wordpress_site_id' => $existing->id,
                    'user_id' => $user->id,
                ]));
            } else {
                $site = new WordpressSite([
                    'name' => $request->validated('name'),
                    'url' => $request->normalizedUrl(),
                ]);
                $site->user_id = $user->id;
            }

            $site->wp_username = $request->validated('wp_username');

            if (filled($request->validated('application_password'))) {
                $site->application_password = WordpressSite::normalizeApplicationPassword(
                    $request->validated('application_password')
                );
            }

            $site->save();

            return $site;
        });

        $this->context->refresh();
        $this->context->remember($site);

        $result = $this->connection->test($site);

        if ($result['ok']) {
            // Même état que la synchronisation manuelle : la liste des sites
            // doit refléter qu'un travail est en attente, worker ou non.
            $site->forceFill(['sync_status' => 'queued', 'sync_message' => null])->save();

            SynchronizeSiteJob::dispatch($site, true, $user->id);
            $this->worker->ensureRunning();

            return redirect()
                ->route('sites.index')
                ->with('status', $existing
                    ? 'Site connecté avec vos identifiants. Vous partagez ses articles avec les autres comptes qui l’ont connecté.'
                    : 'Site connecté. La synchronisation initiale est en cours.');
        }

        return redirect()
            ->route('sites.edit', $site)
            ->with('error', $result['message']);
    }

    public function edit(Request $request, WordpressSite $site): View
    {
        $this->authorize('update', $site);

        return view('sites.edit', [
            'site' => $site,
            'sharedWith' => $site->connections()
                ->where('user_id', '!=', $request->user()->id)
                ->with('user:id,name')
                ->get(),
        ]);
    }

    public function update(UpdateSiteRequest $request, WordpressSite $site): RedirectResponse
    {
        // Le site est partagé : son nom et son adresse ne changent pas sous
        // les pieds des autres comptes qui l'ont connecté. Les identifiants,
        // eux, n'appartiennent qu'à l'utilisateur.
        if ($request->canRenameSite()) {
            $site->name = $request->validated('name');
        }

        if ($request->canChangeUrl()) {
            $site->url = $request->normalizedUrl();
        }

        $site->wp_username = $request->validated('wp_username');

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

    /**
     * Supprime la connexion de l'utilisateur. Le site et ses articles ne
     * disparaissent que si plus personne ne l'a connecté : le travail des
     * autres comptes n'est jamais effacé.
     */
    public function destroy(Request $request, WordpressSite $site): RedirectResponse
    {
        $this->authorize('delete', $site);

        $siteDeleted = DB::transaction(function () use ($request, $site) {
            $site->connections()->where('user_id', $request->user()->id)->delete();

            if ($site->connections()->exists()) {
                return false;
            }

            $site->delete();

            return true;
        });

        $this->context->forget();
        $this->context->refresh();

        return redirect()
            ->route('sites.index')
            ->with('status', $siteDeleted
                ? 'Site supprimé ainsi que ses articles synchronisés.'
                : 'Site retiré de vos sites. Il reste disponible pour les autres comptes qui l’ont connecté.');
    }

    /**
     * Test de connexion en AJAX depuis la liste des sites : ce sont les
     * identifiants de l'utilisateur qui sont testés.
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
     * Lance la synchronisation en arrière-plan, avec les identifiants de
     * l'utilisateur s'il a connecté le site.
     */
    public function sync(Request $request, WordpressSite $site): JsonResponse
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

        SynchronizeSiteJob::dispatch($site, true, $request->user()->id);
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
}
