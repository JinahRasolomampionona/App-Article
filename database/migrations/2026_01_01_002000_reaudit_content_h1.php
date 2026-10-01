<?php

use App\Models\WordpressArticle;
use App\Services\Audit\AuditService;
use App\Services\Audit\AuditSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * La règle H1 signale désormais tout H1 présent dans le contenu : le titre de
 * l'article est déjà le H1 de la page. Jusqu'ici, un H1 unique dans le contenu
 * passait pour conforme.
 *
 * Les articles dont le contenu porte un H1 sont ré-audités sur les règles
 * locales (aucun appel réseau) pour que la remarque « Problème balise H1 »
 * apparaisse sans attendre la prochaine synchronisation.
 */
return new class extends Migration
{
    public function up(): void
    {
        $audit = app(AuditService::class);

        WordpressArticle::query()
            ->where('content', 'like', '%<h1%')
            ->with('site.user')
            ->chunkById(100, function ($articles) use ($audit) {
                foreach ($articles as $article) {
                    try {
                        $audit->run(
                            $article,
                            AuditSettings::forUser($article->site?->user),
                            allowNetwork: false,
                            trigger: 'rules_update',
                        );
                    } catch (Throwable $e) {
                        Log::warning('Ré-audit H1 impossible', ['article_id' => $article->id, 'detail' => $e->getMessage()]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Un nouvel audit recalcule les remarques selon la règle en vigueur.
    }
};
