<?php

namespace App\Services\Stats;

use App\Models\User;
use App\Models\WordpressArticle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Répartition dans le temps des articles passés à « OK » ou « Corrigé ».
 *
 * La source est `article_status_history` : une ligne par article et par
 * passage au vert. Elle survit à la suppression d'un site, ce que ne
 * permettraient ni les articles ni les remarques, effacés avec lui.
 *
 * Un article compte une fois par période où il a changé de statut : la série
 * mesure l'activité de correction, pas le stock d'articles conformes — celui-ci
 * est donné par ArticleStatisticsService::overview().
 */
class CorrectionStatsService
{
    public const GRANULARITIES = ['day', 'week', 'month'];

    /** Nombre de périodes affichées par défaut, par granularité. */
    protected const DEFAULT_PERIODS = ['day' => 14, 'week' => 12, 'month' => 12];

    /**
     * Série continue de périodes, de la plus ancienne à la plus récente.
     *
     * Les périodes sans correction sont présentes avec des compteurs à zéro :
     * un graphique doit montrer les creux, pas les escamoter.
     *
     * @return array<int, array{key: string, label: string, full_label: string, articles: int, ok: int, fixed: int, manual: int}>
     */
    public function series(User $user, string $granularity, ?int $periods = null, ?int $siteId = null): array
    {
        $granularity = $this->normalize($granularity);
        $periods = $this->clampPeriods($periods ?? self::DEFAULT_PERIODS[$granularity]);

        $start = $this->startOf($this->now(), $granularity)
            ->sub($this->interval($granularity), $periods - 1);

        $rows = $this->aggregate($user, $granularity, $start, $siteId);

        $buckets = [];
        $cursor = $start;

        for ($index = 0; $index < $periods; $index++) {
            $key = $cursor->format('Y-m-d');
            $row = $rows[$key] ?? null;

            $buckets[] = [
                'key' => $key,
                'label' => $this->shortLabel($cursor, $granularity),
                'full_label' => $this->fullLabel($cursor, $granularity),
                'articles' => (int) ($row->articles ?? 0),
                'ok' => (int) ($row->ok ?? 0),
                'fixed' => (int) ($row->fixed ?? 0),
                'manual' => (int) ($row->manual ?? 0),
            ];

            $cursor = $cursor->add($this->interval($granularity), 1);
        }

        return $buckets;
    }

    /**
     * Période courante et évolution par rapport à la précédente, pour les
     * trois granularités.
     *
     * @return array<string, array{articles: int, ok: int, fixed: int, manual: int, previous_articles: int, trend: int|null}>
     */
    public function summary(User $user, ?int $siteId = null): array
    {
        $summary = [];

        foreach (self::GRANULARITIES as $granularity) {
            // Deux périodes suffisent : la courante et celle qui précède.
            $series = $this->series($user, $granularity, 2, $siteId);
            [$previous, $current] = $series;

            $summary[$granularity] = [
                'articles' => $current['articles'],
                'ok' => $current['ok'],
                'fixed' => $current['fixed'],
                'manual' => $current['manual'],
                'previous_articles' => $previous['articles'],
                'trend' => $this->trend($current['articles'], $previous['articles']),
            ];
        }

        return $summary;
    }

    /**
     * Variation en pourcentage entre deux périodes, ou `null` lorsqu'elle n'a
     * pas de sens — partir de zéro n'est pas une progression chiffrable.
     */
    protected function trend(int $current, int $previous): ?int
    {
        if ($previous === 0) {
            return null;
        }

        return (int) round((($current - $previous) / $previous) * 100);
    }

    /**
     * Agrégation en une requête, indexée par début de période.
     *
     * @return array<string, object>
     */
    protected function aggregate(User $user, string $granularity, CarbonImmutable $start, ?int $siteId = null): array
    {
        $bucket = $this->bucketExpression($granularity);

        return DB::table('article_status_history as h')
            ->where('h.user_id', $user->id)
            ->when($siteId, fn ($query) => $query->where('h.wordpress_site_id', $siteId))
            ->where('h.recorded_at', '>=', $start)
            ->groupBy(DB::raw($bucket))
            ->selectRaw(
                $bucket.' as bucket,'
                .' count(*) as articles,'
                .' sum(case when h.status = ? then 1 else 0 end) as ok,'
                .' sum(case when h.status = ? then 1 else 0 end) as fixed,'
                .' sum(case when h.resolved_manually = 1 then 1 else 0 end) as manual',
                [WordpressArticle::AUDIT_OK, WordpressArticle::AUDIT_FIXED],
            )
            ->get()
            ->keyBy(fn ($row) => substr((string) $row->bucket, 0, 10))
            ->all();
    }

    /**
     * Expression SQL ramenant `recorded_at` au premier jour de sa période.
     *
     * Grouper en base évite de rapatrier tout l'historique, mais
     * les fonctions de date ne sont pas portables : seuls les deux moteurs
     * utilisés par le projet sont couverts, MySQL/MariaDB servant de défaut.
     */
    protected function bucketExpression(string $granularity, ?string $driver = null): string
    {
        $driver ??= DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            return match ($granularity) {
                // « weekday 0 » avance au dimanche suivant ; reculer de six
                // jours donne le lundi de la semaine.
                'week' => "strftime('%Y-%m-%d', h.recorded_at, 'weekday 0', '-6 days')",
                'month' => "strftime('%Y-%m-01', h.recorded_at)",
                default => "strftime('%Y-%m-%d', h.recorded_at)",
            };
        }

        return match ($granularity) {
            // WEEKDAY() vaut 0 le lundi.
            'week' => 'DATE(DATE_SUB(h.recorded_at, INTERVAL WEEKDAY(h.recorded_at) DAY))',
            'month' => "DATE_FORMAT(h.recorded_at, '%Y-%m-01')",
            default => 'DATE(h.recorded_at)',
        };
    }

    protected function startOf(CarbonImmutable $date, string $granularity): CarbonImmutable
    {
        return match ($granularity) {
            'week' => $date->startOfWeek(),
            'month' => $date->startOfMonth(),
            default => $date->startOfDay(),
        };
    }

    protected function interval(string $granularity): string
    {
        return match ($granularity) {
            'week' => 'week',
            'month' => 'month',
            default => 'day',
        };
    }

    protected function shortLabel(CarbonImmutable $date, string $granularity): string
    {
        return match ($granularity) {
            'week' => 'S'.$date->isoWeek(),
            'month' => $date->translatedFormat('M'),
            default => $date->translatedFormat('d/m'),
        };
    }

    protected function fullLabel(CarbonImmutable $date, string $granularity): string
    {
        return match ($granularity) {
            'week' => 'Semaine '.$date->isoWeek().' — du '.$date->translatedFormat('d M')
                .' au '.$date->endOfWeek()->translatedFormat('d M Y'),
            'month' => ucfirst($date->translatedFormat('F Y')),
            default => ucfirst($date->translatedFormat('D d M Y')),
        };
    }

    public function normalize(?string $granularity): string
    {
        return in_array($granularity, self::GRANULARITIES, true) ? $granularity : 'day';
    }

    /** Borne le nombre de périodes : une URL ne doit pas pouvoir tout demander. */
    protected function clampPeriods(int $periods): int
    {
        return max(2, min($periods, 60));
    }

    protected function now(): CarbonImmutable
    {
        return CarbonImmutable::now();
    }
}
