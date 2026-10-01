<?php

namespace App\Services\Audit\Relevance;

use Anthropic\Client;
use Anthropic\Core\Exceptions\AnthropicException;
use App\Support\UnsafeUrlException;
use App\Support\UrlGuard;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cohérence image / article jugée par un modèle de vision (Claude).
 *
 * Contrairement à l'heuristique, le modèle regarde réellement l'image et la
 * confronte à trois critères :
 *
 *  1. cohérence avec le titre : l'image représente le sujet principal annoncé ;
 *  2. cohérence avec le contexte : elle correspond aux événements, personnes,
 *     lieux ou objets réellement développés dans l'article — une image fidèle
 *     au titre peut être incohérente avec le contenu ;
 *  3. précision : ni trop générique, ni ambiguë, ni trompeuse ; lorsqu'un
 *     élément précis est traité, l'image doit le représenter.
 *
 * Le score retenu est celui du critère le plus faible : un seul critère en
 * échec suffit à rendre l'image « potentiellement incohérente ». Il ne s'agit
 * jamais d'une preuve, ni d'une détection d'image générée par IA.
 *
 * Les verdicts sont mémorisés par image et par titre d'article : un audit
 * relancé ne réinterroge pas le modèle pour une image déjà jugée.
 */
class ClaudeVisionImageRelevanceAnalyzer implements ImageRelevanceAnalyzerInterface
{
    /** Formats d'image acceptés par l'API en base64. */
    protected const MEDIA_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /** Taille maximale d'une image envoyée en base64. */
    protected const MAX_INLINE_BYTES = 5 * 1024 * 1024;

    /** Critère réussi, incertain, en échec. */
    protected const SCORES = ['pass' => 1.0, 'uncertain' => 0.5, 'fail' => 0.0];

    protected const SYSTEM_PROMPT = <<<'PROMPT'
Tu es correcteur éditorial pour des sites WordPress. Tu reçois une image publiée dans un article, ainsi que le titre et le texte de cet article. Tu évalues si l'image est cohérente avec l'article selon trois critères, indépendamment les uns des autres.

1. Cohérence avec le titre : l'image correspond directement au sujet principal annoncé dans le titre. Elle ne doit pas représenter un sujet différent ou trop éloigné de celui du titre.
2. Cohérence avec le contexte de l'article : l'image correspond au contexte, aux événements, aux personnes, aux lieux ou aux objets décrits dans le contenu. Une image peut être incohérente même si elle correspond au titre, lorsqu'elle ne correspond pas au contexte réellement développé dans l'article.
3. Précision et pertinence : l'image représente le sujet de manière suffisamment précise. Une image trop générique, ambiguë, hors contexte ou susceptible d'induire le lecteur en erreur ne convient pas. Lorsque l'article concerne un événement, une personne, un lieu ou un objet précis, l'image doit, dans la mesure du possible, représenter précisément cet élément.

Pour chaque critère, réponds « pass » si l'image le respecte, « fail » si elle ne le respecte manifestement pas, « uncertain » si tu ne peux pas trancher (image illisible, sujet impossible à vérifier visuellement…). Ne réponds « fail » que lorsque le défaut est visible : dans le doute, choisis « uncertain ». Une image décorative mais en rapport direct avec le sujet n'est pas un échec.

Ne cherche pas à déterminer si l'image a été générée par une IA : seule la cohérence avec l'article compte.

Rédige chaque explication en français, en une phrase courte et concrète qui décrit ce que montre l'image et pourquoi le critère est respecté ou non. Le résumé tient en une phrase de moins de 160 caractères, destinée à l'agent qui corrigera l'article.
PROMPT;

    protected ?Client $client = null;

    public function __construct(
        protected UrlGuard $guard,
    ) {}

    public function isAvailable(): bool
    {
        return filled($this->apiKey());
    }

    public function analyze(array $image, array $article): RelevanceResult
    {
        $url = (string) ($image['url'] ?? '');

        if ($url === '' || ! $this->isAvailable()) {
            return RelevanceResult::unknown();
        }

        $cacheKey = 'articleguard:relevance:vision:'.sha1(implode('|', [
            $this->model(),
            $url,
            mb_strtolower(trim((string) ($article['title'] ?? ''))),
            // Taille et dimensions du fichier : un fichier remplacé sous la
            // même URL change d'empreinte et sera réexaminé.
            (string) ($image['fingerprint'] ?? ''),
        ]));

        try {
            $cached = Cache::get($cacheKey);
        } catch (Throwable) {
            $cached = null;
        }

        if (is_array($cached)) {
            return $this->toResult($cached);
        }

        $source = $this->imageSource($url);

        if ($source === null) {
            return RelevanceResult::unknown('Image inaccessible pour l’analyse visuelle.');
        }

        $verdict = $this->ask($source, $image, $article);

        if ($verdict === null) {
            return RelevanceResult::unknown('Analyse visuelle indisponible.');
        }

        try {
            Cache::put($cacheKey, $verdict, now()->addDays((int) config('articleguard.images.analysis_ttl_days', 30)));
        } catch (Throwable) {
            // Sans cache, l'image sera simplement réanalysée au prochain audit.
        }

        return $this->toResult($verdict);
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $image
     * @param  array<string, mixed>  $article
     * @return array<string, mixed>|null
     */
    protected function ask(array $source, array $image, array $article): ?array
    {
        $context = trim(implode("\n\n", array_filter([
            'Titre de l’article : '.trim((string) ($article['title'] ?? '')),
            filled($article['excerpt'] ?? null) ? 'Extrait : '.trim(strip_tags((string) $article['excerpt'])) : null,
            'Contenu de l’article :'."\n".mb_substr(trim((string) ($article['text'] ?? '')), 0, 12000),
            filled($image['surrounding_text'] ?? null) ? 'Texte autour de l’image : '.trim((string) $image['surrounding_text']) : null,
            filled($image['alt'] ?? null) ? 'Texte alternatif de l’image : '.trim((string) $image['alt']) : null,
            filled($image['caption'] ?? null) ? 'Légende de l’image : '.trim((string) $image['caption']) : null,
        ])));

        try {
            $message = $this->client()->beta->messages->create(
                maxTokens: 2048,
                model: $this->model(),
                system: self::SYSTEM_PROMPT,
                messages: [[
                    'role' => 'user',
                    'content' => [
                        ['type' => 'image', 'source' => $source],
                        ['type' => 'text', 'text' => $context],
                    ],
                ]],
                outputConfig: [
                    'effort' => $this->effort(),
                    'format' => ['type' => 'json_schema', 'schema' => $this->schema()],
                ],
                // Un refus de sécurité est relancé côté serveur sur le modèle
                // de repli prévu par l'API, dans le même appel.
                fallbacks: 'default',
                betas: ['server-side-fallback-2026-07-01'],
                requestOptions: ['timeout' => (float) config('articleguard.relevance.vision.timeout', 60), 'maxRetries' => 1],
            );
        } catch (AnthropicException $e) {
            Log::warning('Analyse visuelle de cohérence impossible', [
                'url' => $image['url'] ?? null,
                'error' => class_basename($e),
                'detail' => mb_substr($e->getMessage(), 0, 300),
            ]);

            return null;
        }

        if ($message->stopReason === 'refusal') {
            return null;
        }

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $data = json_decode($block->text, true);

                return is_array($data) && $this->isValid($data) ? $data : null;
            }
        }

        return null;
    }

    /**
     * Image envoyée en base64 lorsqu'elle est téléchargeable (protection
     * anti-hotlink, site derrière un pare-feu…), sinon par URL.
     *
     * @return array<string, mixed>|null
     */
    protected function imageSource(string $url): ?array
    {
        try {
            $this->guard->assertSafe($url);
        } catch (UnsafeUrlException) {
            return null;
        }

        try {
            $response = Http::withHeaders(['User-Agent' => config('articleguard.http.user_agent')])
                ->timeout((int) config('articleguard.images.download_timeout', 12))
                ->connectTimeout((int) config('articleguard.http.connect_timeout', 8))
                ->get($url);
        } catch (Throwable) {
            return ['type' => 'url', 'url' => $url];
        }

        if (! $response->successful()) {
            return $response->status() === 404 ? null : ['type' => 'url', 'url' => $url];
        }

        $body = $response->body();
        $mime = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));

        if (! in_array($mime, self::MEDIA_TYPES, true) && $body !== '') {
            $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($body);
        }

        if ($body === '' || ! in_array($mime, self::MEDIA_TYPES, true) || strlen($body) > self::MAX_INLINE_BYTES) {
            return ['type' => 'url', 'url' => $url];
        }

        return ['type' => 'base64', 'mediaType' => $mime, 'data' => base64_encode($body)];
    }

    /**
     * @param  array<string, mixed>  $verdict
     */
    protected function toResult(array $verdict): RelevanceResult
    {
        $criteria = [
            'title' => (string) ($verdict['title_match']['verdict'] ?? 'uncertain'),
            'context' => (string) ($verdict['context_match']['verdict'] ?? 'uncertain'),
            'precision' => (string) ($verdict['precision']['verdict'] ?? 'uncertain'),
        ];

        $score = min(array_map(fn (string $value) => self::SCORES[$value] ?? 0.5, $criteria));
        $failed = in_array('fail', $criteria, true);

        if (! $failed && ! in_array('pass', $criteria, true)) {
            return RelevanceResult::unknown((string) ($verdict['summary'] ?? ''));
        }

        return new RelevanceResult(
            $failed ? RelevanceResult::POSSIBLY_INCOHERENT : RelevanceResult::RELEVANT,
            $score,
            $failed ? (string) ($verdict['summary'] ?? '') : null,
            [
                'source' => 'vision',
                'criteria' => [
                    'title' => ['verdict' => $criteria['title'], 'explanation' => (string) ($verdict['title_match']['explanation'] ?? '')],
                    'context' => ['verdict' => $criteria['context'], 'explanation' => (string) ($verdict['context_match']['explanation'] ?? '')],
                    'precision' => ['verdict' => $criteria['precision'], 'explanation' => (string) ($verdict['precision']['explanation'] ?? '')],
                ],
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function isValid(array $data): bool
    {
        foreach (['title_match', 'context_match', 'precision'] as $key) {
            if (! isset($data[$key]['verdict']) || ! array_key_exists($data[$key]['verdict'], self::SCORES)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    protected function schema(): array
    {
        $criterion = [
            'type' => 'object',
            'properties' => [
                'verdict' => ['type' => 'string', 'enum' => array_keys(self::SCORES)],
                'explanation' => ['type' => 'string'],
            ],
            'required' => ['verdict', 'explanation'],
            'additionalProperties' => false,
        ];

        return [
            'type' => 'object',
            'properties' => [
                'title_match' => $criterion,
                'context_match' => $criterion,
                'precision' => $criterion,
                'summary' => ['type' => 'string'],
            ],
            'required' => ['title_match', 'context_match', 'precision', 'summary'],
            'additionalProperties' => false,
        ];
    }

    protected function client(): Client
    {
        return $this->client ??= new Client(
            apiKey: $this->apiKey(),
            baseUrl: filled(config('articleguard.relevance.vision.endpoint')) ? (string) config('articleguard.relevance.vision.endpoint') : null,
        );
    }

    protected function apiKey(): ?string
    {
        $key = config('articleguard.relevance.vision.api_key');

        return filled($key) ? (string) $key : null;
    }

    protected function model(): string
    {
        return (string) (config('articleguard.relevance.vision.model') ?: 'claude-opus-5-5');
    }

    protected function effort(): string
    {
        $effort = (string) config('articleguard.relevance.vision.effort', 'low');

        return in_array($effort, ['low', 'medium', 'high', 'xhigh', 'max'], true) ? $effort : 'low';
    }
}
