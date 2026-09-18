<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ImageAnalysis extends Model
{
    use HasFactory;

    public const STATUS_OK = 'ok';
    public const STATUS_UNREACHABLE = 'unreachable';
    public const STATUS_UNSUPPORTED = 'unsupported';
    public const STATUS_TOO_LARGE = 'too_large';
    public const STATUS_BLOCKED = 'blocked';

    protected $fillable = [
        'url_hash',
        'url',
        'status',
        'error_code',
        'width',
        'height',
        'bytes',
        'mime',
        'sharpness',
        'is_blurry',
        'analyzed_at',
    ];

    protected function casts(): array
    {
        return [
            'width' => 'integer',
            'height' => 'integer',
            'bytes' => 'integer',
            'sharpness' => 'float',
            'is_blurry' => 'boolean',
            'analyzed_at' => 'datetime',
        ];
    }

    public static function hashFor(string $url): string
    {
        return sha1($url);
    }

    public function isFresh(int $ttlDays): bool
    {
        return $this->analyzed_at !== null
            && $this->analyzed_at->greaterThan(now()->subDays($ttlDays));
    }
}
