<?php

namespace App\Services\Audit;

use App\Models\ArticleAudit;
use App\Models\ArticleAuditIssue;
use App\Models\WordpressArticle;
use App\Services\Audit\Rules\AuditRule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Moteur d'audit.
 *
 * Il exécute les règles activées, agrège leurs remarques, puis réconcilie le
 * résultat avec les problèmes déjà connus de l'article :
 *
 *  - un problème toujours présent conserve sa date de détection initiale ;
 *  - un problème disparu est clôturé (`resolved_at`) plutôt que supprimé, ce
 *    qui permet d'afficher « Corrigé » en connaissance de cause ;
 *  - une règle active mais non exécutée (réseau indisponible) ne clôture jamais
 *    ses problèmes : ils seraient faussement considérés comme résolus ;
 *  - une règle désactivée par l'utilisateur voit en revanche ses problèmes
 *    supprimés : ils n'ont plus lieu d'être affichés, et les supprimer plutôt
 *    que les clôturer évite d'annoncer une correction qui n'a pas eu lieu.
 */
class AuditService
{
    /** @var array<int, AuditRule> */
    protected array $rules;

    public function __construct(AuditRule ...$rules)
    {
        $this->rules = $rules;
    }

    /**
     * @return array<int, AuditRule>
     */
    public function rules(): array
    {
        return $this->rules;
    }

    /**
     * Catalogue des règles pour l'écran de paramètres.
     *
     * @return array<int, array{key: string, label: string, requires_network: bool}>
     */
    public function catalog(): array
    {
        $catalog = array_map(fn (AuditRule $rule) => [
            'key' => $rule->key(),
            'label' => $rule->label(),
            'requires_network' => $rule->requiresNetwork(),
        ], $this->rules);

        // Règle optionnelle portée par H1Rule : elle n'a pas de classe dédiée
        // mais doit rester pilotable depuis les paramètres.
        $catalog[] = [
            'key' => 'missing_h1',
            'label' => 'Signaler l’absence de H1',
            'requires_network' => false,
        ];

        return $catalog;
    }

    /**
     * Exécute un audit complet et le persiste.
     *
     * @param  bool  $allowNetwork  false pour un audit instantané, sans
     *                              téléchargement d'images
     */
    public function run(
        WordpressArticle $article,
        ?AuditSettings $settings = null,
        bool $allowNetwork = true,
        string $trigger = 'manual',
    ): ArticleAudit {
        $settings ??= AuditSettings::forUser($article->site?->user);
        $context = new AuditContext($article, $settings, $allowNetwork);

        $startedAt = microtime(true);
        $issues = [];
        $executedTypes = [];
        $disabledTypes = [];

        foreach ($this->rules as $rule) {
            if (! $settings->ruleEnabled($rule->key())) {
                // Règle désactivée volontairement : ses remarques héritées d'une
                // configuration précédente sont supprimées, pas clôturées. Les
                // clôturer les ferait passer pour corrigées ; les laisser
                // ouvertes les afficherait indéfiniment.
                $disabledTypes = array_merge($disabledTypes, $rule->issueTypes());

                continue;
            }

            if ($rule->requiresNetwork() && ! $allowNetwork) {
                // Cas différent : la règle est active mais n'a pas pu tourner.
                // Clôturer ses problèmes les déclarerait résolus à tort.
                continue;
            }

            try {
                $produced = $rule->evaluate($context);
            } catch (Throwable $e) {
                // Une règle en échec ne doit pas faire échouer l'audit entier.
                Log::error('Règle d’audit en échec', [
                    'rule' => $rule->key(),
                    'article_id' => $article->id,
                    'detail' => $e->getMessage(),
                ]);

                continue;
            }

            $executedTypes = array_merge($executedTypes, $rule->issueTypes());
            $issues = array_merge($issues, $produced);
        }

        return $this->persist(
            $article,
            $issues,
            array_values(array_unique($executedTypes)),
            $trigger,
            (int) round((microtime(true) - $startedAt) * 1000),
            array_values(array_unique($disabledTypes)),
        );
    }

    /**
     * Évalue les règles sans rien écrire en base (utilisé par les tests et les
     * aperçus).
     *
     * @return array<int, Issue>
     */
    public function evaluate(WordpressArticle $article, ?AuditSettings $settings = null, bool $allowNetwork = true): array
    {
        $settings ??= AuditSettings::forUser($article->site?->user);
        $context = new AuditContext($article, $settings, $allowNetwork);
        $issues = [];

        foreach ($this->rules as $rule) {
            if (! $settings->ruleEnabled($rule->key())) {
                continue;
            }

            if ($rule->requiresNetwork() && ! $allowNetwork) {
                continue;
            }

            $issues = array_merge($issues, $rule->evaluate($context));
        }

        return $issues;
    }

    /**
     * @param  array<int, Issue>  $issues
     * @param  array<int, string>  $executedTypes
     * @param  array<int, string>  $disabledTypes  Types dont la règle est
     *                                             désactivée : leurs remarques
     *                                             sont effacées, pas résolues.
     */
    protected function persist(
        WordpressArticle $article,
        array $issues,
        array $executedTypes,
        string $trigger,
        int $durationMs,
        array $disabledTypes = [],
    ): ArticleAudit {
        return DB::transaction(function () use ($article, $issues, $executedTypes, $trigger, $durationMs, $disabledTypes) {
            $hash = $article->contentHash();

            if ($disabledTypes !== []) {
                // Suppression et non clôture : une remarque « résolue »
                // basculerait l'article en « Corrigé » alors que rien n'a été
                // corrigé — seule la règle a été désactivée.
                $article->issues()->whereIn('rule_type', $disabledTypes)->delete();
            }

            $audit = ArticleAudit::create([
                'wordpress_article_id' => $article->id,
                'status' => WordpressArticle::AUDIT_PENDING,
                'issues_count' => count($issues),
                'content_hash' => $hash,
                'trigger_source' => $trigger,
                'duration_ms' => $durationMs,
            ]);

            /** @var Collection<string, ArticleAuditIssue> $open */
            $open = $article->openIssues()->get()->keyBy(
                fn (ArticleAuditIssue $issue) => $this->fingerprintOf($issue)
            );

            $keptFingerprints = [];

            foreach ($issues as $issue) {
                $fingerprint = $issue->fingerprint();
                $keptFingerprints[] = $fingerprint;

                if ($existing = $open->get($fingerprint)) {
                    $existing->forceFill([
                        'article_audit_id' => $audit->id,
                        'severity' => $issue->severity,
                        'message' => $issue->message,
                        'metadata' => $issue->metadata,
                    ])->save();

                    continue;
                }

                ArticleAuditIssue::create([
                    'wordpress_article_id' => $article->id,
                    'article_audit_id' => $audit->id,
                    'rule_type' => $issue->type,
                    'severity' => $issue->severity,
                    'message' => $issue->message,
                    'metadata' => $issue->metadata,
                    'detected_at' => now(),
                ]);
            }

            // Clôture uniquement ce que les règles exécutées auraient dû revoir.
            $resolvedCount = 0;

            foreach ($open as $fingerprint => $issue) {
                if (in_array($fingerprint, $keptFingerprints, true)) {
                    continue;
                }

                if (! in_array($issue->rule_type, $executedTypes, true)) {
                    continue;
                }

                $issue->forceFill(['resolved_at' => now()])->save();
                $resolvedCount++;
            }

            $openCount = $article->openIssues()->count();
            $hadIssuesBefore = $article->issues()->whereNotNull('resolved_at')->exists();

            $status = match (true) {
                $openCount > 0 => WordpressArticle::AUDIT_NEEDS_FIX,
                $hadIssuesBefore => WordpressArticle::AUDIT_FIXED,
                default => WordpressArticle::AUDIT_OK,
            };

            $audit->forceFill([
                'status' => $status,
                'issues_count' => $openCount,
                'resolved_count' => $resolvedCount,
                'completed_at' => now(),
            ])->save();

            $article->forceFill([
                'audit_status' => $status,
                'issues_count' => $openCount,
                'last_audited_at' => now(),
                'audited_content_hash' => $hash,
                'issues_resolved_at' => $status === WordpressArticle::AUDIT_FIXED && $resolvedCount > 0
                    ? now()
                    : $article->issues_resolved_at,
            ])->save();

            return $audit;
        });
    }

    /**
     * Doit produire exactement la même clé que `Issue::fingerprint()`.
     */
    protected function fingerprintOf(ArticleAuditIssue $issue): string
    {
        $metadata = $issue->metadata ?? [];
        $target = $metadata['target'] ?? $metadata['src'] ?? null;

        return $issue->rule_type.'|'.(is_string($target) ? $target : '');
    }
}
