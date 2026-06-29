<?php

namespace App\Controller\Frontend;

use App\Entity\Site as SiteEntity;
use App\Entity\User as UserEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared owner-or-admin gate for per-site controllers.
 *
 * Two flavors:
 *   - denyUnlessSiteOwnerOrAdmin() throws AccessDeniedException — for HTML
 *     controllers that let Symfony's exception listener render a 403 page.
 *   - denySiteAccessAsJson() returns a JsonResponse (401/403/404) or null — for
 *     JSON API endpoints whose consumers depend on a structured error body.
 *
 * Authorization rules (identical across both methods):
 *   - unauthenticated caller            → 401
 *   - site argument is null              → 404 / AccessDenied (safety net;
 *                                          callers normally handle this upstream
 *                                          to surface a domain-specific message)
 *   - ROLE_USER caller without hasSite()  → 403
 *   - everyone else (admin, ROLE_USER with hasSite) → pass
 */
trait SiteAccessTrait
{
    /**
     * Throwing variant. Use from HTML controllers.
     *
     * @return void  Returns normally if the caller is authorized; otherwise
     *               throws AccessDeniedException (handled by Symfony into 403).
     */
    private function denyUnlessSiteOwnerOrAdmin(?SiteEntity $site): void
    {
        $user = $this->getUser();
        if ($user === null) {
            throw $this->createAccessDeniedException();
        }
        if ($site === null) {
            throw $this->createAccessDeniedException();
        }
        if (UserEntity::ROLE_USER === $user->getRole() && false === $user->hasSite($site)) {
            throw $this->createAccessDeniedException();
        }
    }

    /**
     * Non-throwing variant. Use from JSON API controllers that already return
     * a structured error body — preserves the existing JSON 401/403/404 contract.
     *
     * @return JsonResponse|null  null when the caller is authorized; otherwise a
     *                            JsonResponse the caller should return as-is.
     */
    private function denySiteAccessAsJson(?SiteEntity $site): ?JsonResponse
    {
        $user = $this->getUser();
        if ($user === null) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }
        if ($site === null) {
            return new JsonResponse(['error' => 'Site not found'], Response::HTTP_NOT_FOUND);
        }
        if (UserEntity::ROLE_USER === $user->getRole() && false === $user->hasSite($site)) {
            return new JsonResponse(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }
        return null;
    }
}
