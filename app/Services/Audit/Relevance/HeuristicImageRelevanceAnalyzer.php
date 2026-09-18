<?php

namespace App\Services\Audit\Relevance;

/**
 * Analyse locale, sans dépendance ni appel réseau.
 *
 * Elle compare le vocabulaire décrivant l'image (nom de fichier, `alt`,
 * `title`, légende) à celui de l'article (titre, extrait, texte). Elle ne
 * « regarde » pas l'image : elle mesure simplement si l'image est décrite en
 * cohérence avec le sujet.
 *
 * Quand l'image ne porte aucune description exploitable, le verdict est
 * `unknown` — on ne signale rien plutôt que d'accuser à tort.
 */
class HeuristicImageRelevanceAnalyzer implements ImageRelevanceAnalyzerInterface
{
    /** Mots vides français et anglais, plus le bruit typique des noms de fichiers. */
    protected const STOP_WORDS = [
        'alors', 'aucun', 'aussi', 'autre', 'avant', 'avec', 'avoir', 'bien', 'cela', 'cette',
        'ceux', 'chaque', 'comme', 'dans', 'depuis', 'deux', 'dont', 'elle', 'elles', 'encore',
        'entre', 'etre', 'faire', 'fait', 'leur', 'leurs', 'mais', 'meme', 'moins', 'notre',
        'nous', 'plus', 'pour', 'pourquoi', 'quand', 'quel', 'quelle', 'sans', 'sont', 'sous',
        'sur', 'tous', 'tout', 'toute', 'toutes', 'tres', 'votre', 'vous', 'about', 'after',
        'also', 'because', 'been', 'before', 'being', 'between', 'both', 'each', 'from', 'have',
        'here', 'into', 'more', 'most', 'only', 'other', 'over', 'same', 'some', 'such', 'than',
        'that', 'them', 'then', 'there', 'these', 'they', 'this', 'those', 'through', 'were',
        'what', 'when', 'where', 'which', 'while', 'with', 'your',
        'image', 'images', 'photo', 'photos', 'picture', 'jpeg', 'jpg', 'png', 'webp', 'scaled',
        'copy', 'final', 'upload', 'uploads', 'default', 'placeholder', 'untitled',
        'capture', 'screenshot', 'unnamed', 'download', 'file', 'media', 'visuel', 'illustration',
        'ecran', 'decran', 'titre', 'nouveau', 'nouvelle', 'document',
    ];

    public function isAvailable(): bool
    {
        return true;
    }

    public function analyze(array $image, array $article): RelevanceResult
    {
        $imageTokens = $this->tokenize(implode(' ', [
            $this->filenameWords($image['url'] ?? ''),
            $image['alt'] ?? '',
            $image['title'] ?? '',
            $image['caption'] ?? '',
        ]));

        if (count($imageTokens) === 0) {
            return RelevanceResult::unknown(
                "L'image ne comporte aucun texte descriptif exploitable (alt, légende, nom de fichier)."
            );
        }

        $articleTokens = $this->tokenize(implode(' ', [
            $article['title'] ?? '',
            $article['excerpt'] ?? '',
            $image['surrounding_text'] ?? '',
            mb_substr((string) ($article['text'] ?? ''), 0, 1500),
        ]));

        if (count($articleTokens) === 0) {
            return RelevanceResult::unknown("L'article ne contient pas assez de texte pour être comparé.");
        }

        $matches = array_values(array_intersect($imageTokens, $articleTokens));
        $score = count($matches) / max(1, count($imageTokens));

        $threshold = (float) config('articleguard.thresholds.relevance', 0.35);

        $verdict = $score >= $threshold
            ? RelevanceResult::RELEVANT
            : RelevanceResult::POSSIBLY_INCOHERENT;

        return new RelevanceResult(
            $verdict,
            round($score, 3),
            $verdict === RelevanceResult::POSSIBLY_INCOHERENT
                ? "Le vocabulaire décrivant l'image recoupe peu celui de l'article."
                : null,
            [
                'matched_terms' => array_slice($matches, 0, 10),
                'image_terms' => array_slice($imageTokens, 0, 10),
                'threshold' => $threshold,
            ],
        );
    }

    /**
     * @return array<int, string>
     */
    protected function tokenize(string $text): array
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = $this->stripAccents(mb_strtolower($text, 'UTF-8'));
        $text = preg_replace('/[^a-z0-9]+/', ' ', $text) ?? '';

        $tokens = array_filter(
            preg_split('/\s+/', trim($text)) ?: [],
            fn (string $token) => mb_strlen($token) >= 4
                && ! ctype_digit($token)
                && ! in_array($token, self::STOP_WORDS, true)
        );

        return array_values(array_unique(array_map(
            fn (string $token) => $this->stem($token),
            $tokens
        )));
    }

    /**
     * Racinisation très simple : suffit à rapprocher « bague » de « bagues »
     * ou « collier » de « colliers ».
     */
    protected function stem(string $token): string
    {
        foreach (['aux', 'eux', 'es', 's', 'x'] as $suffix) {
            if (mb_strlen($token) > 4 && str_ends_with($token, $suffix)) {
                return mb_substr($token, 0, mb_strlen($token) - mb_strlen($suffix));
            }
        }

        return $token;
    }

    protected function filenameWords(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path)) {
            return '';
        }

        $name = pathinfo($path, PATHINFO_FILENAME);

        // Les tailles ajoutées par WordPress (« -1024x768 ») ne décrivent rien.
        $name = preg_replace('/-\d{2,4}x\d{2,4}$/', '', $name) ?? $name;

        $name = str_replace(['-', '_', '.', '+'], ' ', $name);

        // Les chiffres accolés aux mots sont détachés : « Image1 », « IMG_20240115 »
        // ou « DSC05678 » deviennent un mot vide suivi d'un nombre, tous deux
        // écartés à la tokenisation. Sans cela « image1 » passait pour un terme
        // descriptif et l'image était jugée incohérente faute de correspondance.
        return preg_replace('/(\d+)/', ' $1 ', $name) ?? $name;
    }

    protected function stripAccents(string $value): string
    {
        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return is_string($transliterated) ? $transliterated : $value;
    }
}
