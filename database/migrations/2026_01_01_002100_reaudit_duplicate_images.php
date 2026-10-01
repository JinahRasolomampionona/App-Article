<?php

use App\Models\WordpressArticle;
use App\Services\Audit\AuditService;
use App\Services\Audit\AuditSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * Nouvelle règle « Image en double » : les articles qui contiennent au moins
 * deux images sont ré-audités sur les règles locales (aucun appel réseau) pour
 * que la remarque apparaisse sans attendre la prochaine synchronisation.
 */
return new class extends Migration
{
    public function up(): void
    {
        $audit = app(AuditService::class);

        WordpressArticle::query()
            ->where('content', 'like', '%<img%<img%')
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
                        Log::warning('Ré-audit des doublons d’images impossible', ['article_id' => $article->id, 'detail' => $e->getMessage()]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Un nouvel audit recalcule les remarques selon les règles en vigueur.
    }
};
