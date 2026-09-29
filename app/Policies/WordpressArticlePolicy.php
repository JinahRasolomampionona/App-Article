<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WordpressArticle;
use Illuminate\Auth\Access\Response;

/**
 * Un article est visible par les comptes qui ont accès à son site (les agents
 * à qui l'Admin l'a assigné, et l'Admin). Seul celui qui détient le verrou d'un article
 * peut le modifier — Admin compris, pour que deux personnes ne modifient
 * jamais le même article en même temps.
 *
 * Les refus portent un message lisible : il est renvoyé tel quel à
 * l'interface (toast), y compris pour une requête AJAX forgée.
 */
class WordpressArticlePolicy
{
    public function view(User $user, WordpressArticle $article): bool
    {
        return $this->canAccessSite($user, $article);
    }

    public function update(User $user, WordpressArticle $article): Response
    {
        if (! $this->canAccessSite($user, $article)) {
            return Response::deny('Ce site ne vous est pas assigné.');
        }

        if ($article->isLockedBy($user)) {
            return Response::allow();
        }

        if ($article->isLocked()) {
            return Response::denyWithStatus(409, 'Cet article est actuellement traité par '.$article->activeAgentName().'.');
        }

        return Response::denyWithStatus(409, 'Prenez d’abord cet article en charge pour pouvoir le modifier.');
    }

    /**
     * « Corrigé » posé à la main depuis le tableau : par l'agent qui détient
     * l'article, ou par l'Admin au nom de ce dernier. Un article que personne
     * n'a pris ne peut pas être déclaré corrigé.
     */
    public function setStatus(User $user, WordpressArticle $article): Response
    {
        if (! $this->canAccessSite($user, $article)) {
            return Response::deny('Ce site ne vous est pas assigné.');
        }

        if ($article->isCompleted() && ! $user->isAdmin()) {
            return Response::denyWithStatus(409, 'Cet article a déjà été déclaré corrigé.');
        }

        if ($article->isLockedByOther($user) && ! $user->isAdmin()) {
            return Response::denyWithStatus(409, 'Cet article est actuellement traité par '.$article->activeAgentName().'.');
        }

        if (! $article->isLocked()) {
            return Response::denyWithStatus(422, 'Cet article n’est assigné à aucun agent : assignez-le avant de le déclarer corrigé.');
        }

        return Response::allow();
    }

    /** Relancer l'audit ne modifie pas le contenu. */
    public function audit(User $user, WordpressArticle $article): bool
    {
        return $this->canAccessSite($user, $article);
    }

    /** Prendre un article : seulement s'il est libre (ou déjà à soi). */
    public function take(User $user, WordpressArticle $article): Response
    {
        if (! $this->canAccessSite($user, $article)) {
            return Response::deny('Ce site ne vous est pas assigné.');
        }

        // Un article corrigé attend la vérification de l'Admin : lui seul
        // peut le rendre à un agent.
        if ($article->isCompleted() && ! $user->isAdmin()) {
            return Response::denyWithStatus(409, 'Cet article a déjà été déclaré corrigé : seul un administrateur peut le réassigner.');
        }

        return $article->isLockedByOther($user)
            ? Response::denyWithStatus(409, 'Cet article est actuellement traité par '.$article->activeAgentName().'.')
            : Response::allow();
    }

    /** Libérer : son propre article, ou n'importe lequel pour l'Admin. */
    public function release(User $user, WordpressArticle $article): Response
    {
        if ($user->isAdmin() || ! $article->isLocked() || $article->assigned_to === $user->id) {
            return Response::allow();
        }

        return Response::deny('Seul '.$article->activeAgentName().' ou un administrateur peut libérer cet article.');
    }

    /** Attribuer l'article à un autre compte : Admin uniquement. */
    public function assign(User $user, WordpressArticle $article): Response
    {
        return $user->isAdmin()
            ? Response::allow()
            : Response::deny('Seul un administrateur peut attribuer un article à un autre agent.');
    }

    /** Réassigner un article (corrigé ou non) à un agent : Admin uniquement. */
    public function reassign(User $user, WordpressArticle $article): Response
    {
        return $user->isAdmin()
            ? Response::allow()
            : Response::deny('Seul un administrateur peut réassigner un article.');
    }

    /** Commenter un article à l'attention d'un agent : Admin uniquement. */
    public function comment(User $user, WordpressArticle $article): Response
    {
        return $user->isAdmin()
            ? Response::allow()
            : Response::deny('Seul un administrateur peut commenter un article.');
    }

    protected function canAccessSite(User $user, WordpressArticle $article): bool
    {
        $site = $article->relationLoaded('site') ? $article->site : $article->site()->first();

        return $site !== null && $site->isAccessibleBy($user);
    }
}
