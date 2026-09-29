<?php

namespace App\Http\Controllers;

use App\Services\SiteContext;
use App\Services\Stats\ArticleStatisticsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Tableau de bord de l'Admin : quatre cartes (Total, À corriger, À vérifier,
 * Corrigés) pour le site sélectionné, chacune menant au tableau des articles
 * filtré sur le même statut.
 *
 * Un agent n'a pas de tableau de bord : il est renvoyé vers ses articles.
 */
class DashboardController extends Controller
{
    public function __construct(
        protected SiteContext $context,
        protected ArticleStatisticsService $statistics,
    ) {}

    public function __invoke(Request $request): View|RedirectResponse
    {
        if (! $request->user()->isAdmin()) {
            return redirect()->route('articles.index');
        }

        $site = $this->context->current();

        return view('dashboard.index', [
            'site' => $site,
            'cards' => $site ? $this->statistics->statusCards($site->id) : null,
        ]);
    }
}
