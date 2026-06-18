<?php

namespace App\Controller\Frontend;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use App\Controller\Controller;
use App\Entity\Manager\SiteManager as SiteEntityManager;
use App\Entity\User as UserEntity;
use App\Service\Logger;
use App\System\CommandExecutor;
use App\System\Command\SudoMkdirCommand;
use App\System\Command\SudoMvCommand;

/**
 * Mutating file-manager endpoints: mkdir and rename.
 *
 * Both operations run as the site's system user via sudo, are CSRF-protected,
 * and are chrooted to /home/<siteUser>/htdocs.
 */
class FileManagerMutateController extends Controller
{
    private SiteEntityManager $siteEntityManager;
    private CsrfTokenManagerInterface $csrfTokenManager;

    public function __construct(
        SiteEntityManager $siteEntityManager,
        CsrfTokenManagerInterface $csrfTokenManager,
        TranslatorInterface $translator,
        Logger $logger
    ) {
        $this->siteEntityManager = $siteEntityManager;
        $this->csrfTokenManager  = $csrfTokenManager;
        parent::__construct($translator, $logger);
    }

    public function mkdirAction(Request $request) : JsonResponse
    {
        $auth = $this->authorize($request);
        if ($auth instanceof JsonResponse) {
            return $auth;
        }
        $root     = $auth['root'];
        $siteUser = $auth['siteUser'];

        $payload = json_decode((string) $request->getContent(), true);
        if (!is_array($payload) || !isset($payload['path'])) {
            return $this->jsonError('Missing path', Response::HTTP_BAD_REQUEST);
        }
        $rel = (string) $payload['path'];
        if (!$this->isSafeRelative($rel)) {
            return $this->jsonError('Invalid path', Response::HTTP_BAD_REQUEST);
        }
        $target = $root . '/' . ltrim(str_replace('\\', '/', $rel), '/');
        if (strncmp($target, $root . '/', strlen($root) + 1) !== 0) {
            return $this->jsonError('Path escapes site root', Response::HTTP_BAD_REQUEST);
        }

        try {
            $cmd = new SudoMkdirCommand();
            $cmd->setTargetUser($siteUser);
            $cmd->setPath($target);
            (new CommandExecutor())->execute($cmd, 30);
        } catch (\Exception $e) {
            $this->logger->exception($e);
            return $this->jsonError('mkdir failed', Response::HTTP_FORBIDDEN);
        }
        return new JsonResponse(['ok' => true]);
    }

    public function renameAction(Request $request) : JsonResponse
    {
        $auth = $this->authorize($request);
        if ($auth instanceof JsonResponse) {
            return $auth;
        }
        $root     = $auth['root'];
        $siteUser = $auth['siteUser'];

        $payload = json_decode((string) $request->getContent(), true);
        if (!is_array($payload) || !isset($payload['from']) || !isset($payload['to'])) {
            return $this->jsonError('Missing from or to', Response::HTTP_BAD_REQUEST);
        }
        $from = (string) $payload['from'];
        $to   = (string) $payload['to'];
        if (!$this->isSafeRelative($from) || !$this->isSafeRelative($to)) {
            return $this->jsonError('Invalid path', Response::HTTP_BAD_REQUEST);
        }
        $fromAbs = $root . '/' . ltrim(str_replace('\\', '/', $from), '/');
        $toAbs   = $root . '/' . ltrim(str_replace('\\', '/', $to), '/');
        $prefix  = $root . '/';
        if (strncmp($fromAbs, $prefix, strlen($prefix)) !== 0 ||
            strncmp($toAbs,   $prefix, strlen($prefix)) !== 0) {
            return $this->jsonError('Path escapes site root', Response::HTTP_BAD_REQUEST);
        }

        try {
            $cmd = new SudoMvCommand();
            $cmd->setTargetUser($siteUser);
            $cmd->setFromPath($fromAbs);
            $cmd->setToPath($toAbs);
            (new CommandExecutor())->execute($cmd, 30);
        } catch (\Exception $e) {
            $this->logger->exception($e);
            return $this->jsonError('rename failed', Response::HTTP_FORBIDDEN);
        }
        return new JsonResponse(['ok' => true]);
    }

    /**
     * @return array{site: \App\Entity\Site, siteUser: string, root: string}|JsonResponse
     */
    private function authorize(Request $request)
    {
        $token = $request->headers->get('X-CSRF-Token') ?: (string) $request->request->get('_token');
        if ($token === '' || $token === null ||
            !$this->csrfTokenManager->isTokenValid(new CsrfToken('file-manager', (string) $token))) {
            return $this->jsonError('Invalid CSRF token', Response::HTTP_FORBIDDEN);
        }

        $domainName = (string) $request->get('domainName');
        $siteEntity = $this->siteEntityManager->findOneByDomainName($domainName);
        if ($siteEntity === null) {
            return $this->jsonError('Site not found', Response::HTTP_NOT_FOUND);
        }

        $user = $this->getUser();
        if ($user === null) {
            return $this->jsonError('Unauthorized', Response::HTTP_UNAUTHORIZED);
        }
        if (UserEntity::ROLE_USER == $user->getRole() && false === $user->hasSite($siteEntity)) {
            return $this->jsonError('Forbidden', Response::HTTP_FORBIDDEN);
        }

        $siteUser = $siteEntity->getUser();
        if ($siteUser === null || $siteUser === '') {
            return $this->jsonError('Site has no system user', Response::HTTP_CONFLICT);
        }
        $root = realpath(sprintf('/home/%s/htdocs', $siteUser));
        if ($root === false) {
            return $this->jsonError('Site htdocs missing on disk', Response::HTTP_NOT_FOUND);
        }
        return [
            'site'     => $siteEntity,
            'siteUser' => $siteUser,
            'root'     => $root,
        ];
    }

    private function isSafeRelative(string $rel) : bool
    {
        if ($rel === '') {
            return false;
        }
        if (strpos($rel, "\0") !== false) {
            return false;
        }
        $norm = str_replace('\\', '/', $rel);
        if ($norm[0] === '/') {
            return false;
        }
        foreach (explode('/', $norm) as $segment) {
            if ($segment === '..') {
                return false;
            }
        }
        return true;
    }

    private function jsonError(string $message, int $status) : JsonResponse
    {
        return new JsonResponse(['error' => $message], $status);
    }
}
