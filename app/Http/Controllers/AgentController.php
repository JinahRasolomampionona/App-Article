<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveAgentRequest;
use App\Models\ArticleStatusHistory;
use App\Models\User;
use App\Models\WordpressSite;
use App\Services\Assignment\ArticleLockService;
use App\Services\Stats\StatisticsRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Gestion des comptes (Admin) : chaque agent a son propre compte.
 *
 * Un compte n'est jamais supprimé silencieusement de l'historique : ses
 * corrections restent lisibles sous son nom. Désactiver un compte est
 * préférable à le supprimer — il ne peut plus se connecter, et ses articles
 * en cours sont libérés.
 */
class AgentController extends Controller
{
    public function __construct(
        protected ArticleLockService $locks,
        protected StatisticsRecorder $recorder,
    ) {}

    public function index(): View
    {
        $this->authorize('viewAny', User::class);

        $users = User::query()
            ->withCount([
                'lockedArticles as in_progress_count',
                'assignments as completed_count' => fn ($query) => $query->whereNotNull('completed_at'),
            ])
            ->orderByRaw('case when role = ? then 0 else 1 end', [User::ROLE_ADMIN])
            ->orderBy('name')
            ->get();

        return view('agents.index', ['users' => $users]);
    }

    public function create(): View
    {
        $this->authorize('create', User::class);

        return view('agents.create');
    }

    public function store(SaveAgentRequest $request): RedirectResponse
    {
        $user = DB::transaction(function () use ($request) {
            $user = new User($request->safe()->only('name', 'email', 'password'));
            $user->forceFill([
                'role' => $request->validated('role', User::ROLE_AGENT),
                'is_active' => true,
            ])->save();

            // Corrections enregistrées sous ce nom avant la création du compte.
            $this->recorder->linkAgentHistory($user);

            return $user;
        });

        Log::info('Compte créé.', ['user_id' => $user->id, 'role' => $user->role, 'by' => $request->user()->id]);

        return redirect()
            ->route('agents.index')
            ->with('status', 'Compte de '.$user->name.' créé. Communiquez-lui son adresse e-mail et son mot de passe.');
    }

    public function edit(User $user): View
    {
        $this->authorize('update', $user);

        return view('agents.edit', ['agent' => $user]);
    }

    public function update(SaveAgentRequest $request, User $user): RedirectResponse
    {
        $admin = $request->user();

        $user->fill($request->safe()->only('name', 'email'));

        if (filled($request->validated('password'))) {
            $user->password = $request->validated('password');
        }

        // Rôle et activation : jamais sur son propre compte (un Admin ne peut
        // ni se rétrograder ni se désactiver et laisser l'espace sans Admin).
        if ($admin->can('changeRole', $user)) {
            if ($request->has('role')) {
                $user->forceFill(['role' => $request->validated('role')]);
            }

            $user->forceFill(['is_active' => $request->boolean('is_active')]);
        }

        $user->save();

        if (! $user->is_active) {
            $this->releaseAll($user, $admin);
        }

        $this->recorder->linkAgentHistory($user);

        Log::info('Compte modifié.', ['user_id' => $user->id, 'by' => $admin->id]);

        return redirect()
            ->route('agents.index')
            ->with('status', 'Compte de '.$user->name.' mis à jour.');
    }

    public function destroy(User $user): RedirectResponse
    {
        $this->authorize('delete', $user);

        $admin = request()->user();

        $this->releaseAll($user, $admin);
        $name = $user->name;

        DB::transaction(function () use ($user, $admin) {
            // Les sites (et l'historique qui leur est rattaché) appartiennent à
            // l'espace, pas à la personne qui les a connectés : ils sont
            // transférés avant suppression, sinon la cascade les effacerait.
            WordpressSite::query()->where('user_id', $user->id)->update(['user_id' => $admin->id]);
            ArticleStatusHistory::query()->where('user_id', $user->id)->update(['user_id' => $admin->id]);

            $user->delete();
        });

        Log::info('Compte supprimé.', ['user_id' => $user->id, 'by' => $admin->id]);

        return redirect()
            ->route('agents.index')
            ->with('status', 'Compte de '.$name.' supprimé. Son historique de corrections est conservé.');
    }

    /** Un compte désactivé ou supprimé ne doit bloquer aucun article. */
    protected function releaseAll(User $user, User $admin): void
    {
        $user->lockedArticles()->get()->each(
            fn ($article) => $this->locks->release($article, $admin)
        );
    }
}
