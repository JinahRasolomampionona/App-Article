<?php

namespace App\Services\Audit;

use App\Models\ImageAnalysis;
use App\Support\UnsafeUrlException;
use App\Support\UrlGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Mesure la netteté d'une image par variance du Laplacien.
 *
 * Principe : on convertit l'image en niveaux de gris, on applique le noyau
 * laplacien [[0,1,0],[1,-4,1],[0,1,0]] puis on calcule la variance du résultat.
 * Une image nette contient beaucoup de transitions rapides, donc une variance
 * élevée ; une image floue les a lissées, donc une variance basse.
 *
 * Le résultat reste une heuristique : le seuil est configurable et le libellé
 * affiché parle d'image « potentiellement » floue.
 *
 * Chaque URL n'est téléchargée qu'une fois : le résultat est mémorisé dans la
 * table `image_analyses` et réutilisé tant que l'URL ne change pas.
 */
class ImageQualityAnalyzer
{
    public function __construct(
        protected UrlGuard $guard,
    ) {}

    public function analyze(string $url, bool $forceRefresh = false): ImageAnalysis
    {
        $hash = ImageAnalysis::hashFor($url);
        $ttl = (int) config('articleguard.images.analysis_ttl_days', 30);

        $existing = ImageAnalysis::where('url_hash', $hash)->first();

        if ($existing && ! $forceRefresh && $existing->isFresh($ttl)) {
            return $existing;
        }

        $result = $this->inspect($url);

        $record = $existing ?? new ImageAnalysis(['url_hash' => $hash, 'url' => $url]);
        $record->fill($result + ['url' => $url, 'url_hash' => $hash, 'analyzed_at' => now()]);
        $record->save();

        return $record;
    }

    /**
     * Récupère une analyse déjà en cache sans déclencher d'appel réseau.
     */
    public function cached(string $url): ?ImageAnalysis
    {
        return ImageAnalysis::where('url_hash', ImageAnalysis::hashFor($url))->first();
    }

    /**
     * @return array<string, mixed>
     */
    protected function inspect(string $url): array
    {
        try {
            $this->guard->assertSafe($url);
        } catch (UnsafeUrlException $e) {
            return [
                'status' => ImageAnalysis::STATUS_BLOCKED,
                'error_code' => $e->reason,
                'sharpness' => null,
                'is_blurry' => null,
            ];
        }

        $maxBytes = (int) config('articleguard.images.max_bytes');

        try {
            $response = Http::withHeaders(['User-Agent' => config('articleguard.http.user_agent')])
                ->timeout((int) config('articleguard.images.download_timeout', 12))
                ->connectTimeout((int) config('articleguard.http.connect_timeout', 8))
                ->get($url);
        } catch (ConnectionException $e) {
            Log::info('Image inaccessible', ['url' => $url, 'detail' => $e->getMessage()]);

            return [
                'status' => ImageAnalysis::STATUS_UNREACHABLE,
                'error_code' => 'connection',
                'sharpness' => null,
                'is_blurry' => null,
            ];
        }

        if (! $response->successful()) {
            return [
                'status' => ImageAnalysis::STATUS_UNREACHABLE,
                'error_code' => 'http_'.$response->status(),
                'sharpness' => null,
                'is_blurry' => null,
            ];
        }

        $body = $response->body();
        $bytes = strlen($body);

        if ($bytes === 0) {
            return [
                'status' => ImageAnalysis::STATUS_UNREACHABLE,
                'error_code' => 'empty',
                'sharpness' => null,
                'is_blurry' => null,
            ];
        }

        if ($bytes > $maxBytes) {
            return [
                'status' => ImageAnalysis::STATUS_TOO_LARGE,
                'error_code' => 'too_large',
                'bytes' => $bytes,
                'sharpness' => null,
                'is_blurry' => null,
            ];
        }

        $info = @getimagesizefromstring($body);

        if ($info === false) {
            return [
                'status' => ImageAnalysis::STATUS_UNSUPPORTED,
                'error_code' => 'not_an_image',
                'bytes' => $bytes,
                'sharpness' => null,
                'is_blurry' => null,
            ];
        }

        $base = [
            'bytes' => $bytes,
            'width' => (int) $info[0],
            'height' => (int) $info[1],
            'mime' => (string) ($info['mime'] ?? $response->header('Content-Type')),
        ];

        if (! function_exists('imagecreatefromstring')) {
            // Sans GD, on conserve les dimensions : la règle de résolution
            // minimale reste opérationnelle, pas celle de netteté.
            return $base + [
                'status' => ImageAnalysis::STATUS_OK,
                'error_code' => 'gd_missing',
                'sharpness' => null,
                'is_blurry' => null,
            ];
        }

        $image = @imagecreatefromstring($body);

        if ($image === false) {
            return $base + [
                'status' => ImageAnalysis::STATUS_UNSUPPORTED,
                'error_code' => 'decode_failed',
                'sharpness' => null,
                'is_blurry' => null,
            ];
        }

        try {
            $sharpness = $this->laplacianVariance($image);
        } finally {
            imagedestroy($image);
        }

        $threshold = (float) config('articleguard.thresholds.blur', 100);

        return $base + [
            'status' => ImageAnalysis::STATUS_OK,
            'error_code' => null,
            'sharpness' => $sharpness,
            'is_blurry' => $sharpness !== null ? $sharpness < $threshold : null,
        ];
    }

    /**
     * Variance du Laplacien sur l'image réduite et convertie en niveaux de gris.
     */
    protected function laplacianVariance(\GdImage $image): ?float
    {
        $maxSide = max(64, (int) config('articleguard.images.analysis_resize', 512));
        $width = imagesx($image);
        $height = imagesy($image);

        if ($width < 3 || $height < 3) {
            return null;
        }

        // Réduction : borne le coût de l'analyse quelle que soit la taille
        // d'origine, et lisse le bruit de compression.
        $scale = min(1.0, $maxSide / max($width, $height));
        $targetW = max(3, (int) round($width * $scale));
        $targetH = max(3, (int) round($height * $scale));

        $resized = imagecreatetruecolor($targetW, $targetH);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $targetW, $targetH, $width, $height);
        imagefilter($resized, IMG_FILTER_GRAYSCALE);

        // Tableau de luminance : une seule lecture des pixels.
        $gray = [];
        for ($y = 0; $y < $targetH; $y++) {
            $row = [];
            for ($x = 0; $x < $targetW; $x++) {
                $row[$x] = imagecolorat($resized, $x, $y) & 0xFF;
            }
            $gray[$y] = $row;
        }

        imagedestroy($resized);

        $sum = 0.0;
        $sumSquares = 0.0;
        $count = 0;

        for ($y = 1; $y < $targetH - 1; $y++) {
            for ($x = 1; $x < $targetW - 1; $x++) {
                $value = $gray[$y - 1][$x] + $gray[$y + 1][$x] + $gray[$y][$x - 1] + $gray[$y][$x + 1]
                    - 4 * $gray[$y][$x];

                $sum += $value;
                $sumSquares += $value * $value;
                $count++;
            }
        }

        if ($count === 0) {
            return null;
        }

        $mean = $sum / $count;

        return round(max(0.0, ($sumSquares / $count) - ($mean * $mean)), 3);
    }
}
