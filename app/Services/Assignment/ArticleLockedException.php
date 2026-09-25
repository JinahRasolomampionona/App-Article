<?php

namespace App\Services\Assignment;

use RuntimeException;

/**
 * L'article est pris par quelqu'un d'autre, ou le verrou de l'utilisateur a
 * été perdu (expiré, libéré par un Admin, réassigné).
 */
class ArticleLockedException extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $holder = null)
    {
        parent::__construct($message);
    }

    public static function heldBy(?string $holder): self
    {
        return new self(
            'Cet article est actuellement traité par '.($holder ?: 'un autre agent').'.',
            $holder,
        );
    }

    public static function notHeld(): self
    {
        return new self(
            'Vous ne détenez plus cet article : il a été libéré ou son verrou a expiré. '
            .'Reprenez-le pour continuer.'
        );
    }
}
