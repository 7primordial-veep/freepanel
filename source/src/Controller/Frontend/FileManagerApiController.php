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
use App\System\Command\SudoTeeCommand;
use App\System\Command\SudoRmCommand;

/**
 * ponytail: scaffold-grade native file manager backend.
 *
 * REST endpoints chrooted to /home/<site-user>/htdocs/.
 * v1 ships: list, read, write, delete.
 *
 * TODO (polish):
 *   - upload / rename / mkdir / move
 *   - real permission checks against the site's owner (currently relies on
 *     web server running as the panel user; we should drop privileges via
 *     `sudo -u <siteUser>` when reading/writing).
 *   - streaming for large files (>1 MB read is rejected).
 *   - mime sniffing + binary download path.
 *   - rate limiting / audit logging via App\Event.
 */
class FileManagerApiController extends Controller
{
    private const MAX_READ_BYTES   = 1048576;   // 1 MiB
    private const MAX_WRITE_BYTES  = 4194304;   // 4 MiB
    // Executable extensions deliberately excluded (php/phtml/twig/blade/sh/htaccess) —
    // writing them via the panel UI is an RCE primitive. Edit those over SFTP/SSH instead.
    private const EDITABLE_EXTS    = [
        'txt','md','log','conf','ini','env','json','yml','yaml',
        'xml','html','htm','css','scss','less','js','mjs','ts','jsx','tsx',
        'vue','py','rb','go','rs','java',
        'sql','toml','svg',
    ];

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

    public function listAction(Request $request): JsonResponse
    {
        $root = $this->resolveRoot($request);
        if ($root instanceof JsonResponse) {
            return $root;
        }
        $abs = $this->resolvePath($root, (string) $request->query->get('path', ''));
        if ($abs === null || !is_dir($abs)) {
            return $this->jsonError('Directory not found', Response::HTTP_NOT_FOUND);
        }

        $entries = [];
        $dh = @opendir($abs);
        if ($dh === false) {
            return $this->jsonError('Cannot open directory', Response::HTTP_FORBIDDEN);
        }
        while (($name = readdir($dh)) !== false) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $full = $abs . DIRECTORY_SEPARATOR . $name;
            $isDir = is_dir($full);
            $entries[] = [
                'name'      => $name,
                'path'      => ltrim(substr($full, strlen($root)), DIRECTORY_SEPARATOR . '/'),
                'type'      => $isDir ? 'dir' : 'file',
                'size'      => $isDir ? 0 : (int) @filesize($full),
                'mtime'     => (int) @filemtime($full),
                'editable'  => !$isDir && $this->isEditable($name),
            ];
        }
        closedir($dh);
        usort($entries, static function ($a, $b) {
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'dir' ? -1 : 1;
            }
            return strcasecmp($a['name'], $b['name']);
        });

        return new JsonResponse([
            'path'    => trim(substr($abs, strlen($root)), DIRECTORY_SEPARATOR . '/'),
            'entries' => $entries,
        ]);
    }

    public function readAction(Request $request): JsonResponse
    {
        $root = $this->resolveRoot($request);
        if ($root instanceof JsonResponse) {
            return $root;
        }
        $abs = $this->resolvePath($root, (string) $request->query->get('path', ''));
        if ($abs === null || !is_file($abs)) {
            return $this->jsonError('File not found', Response::HTTP_NOT_FOUND);
        }
        if (!$this->isEditable(basename($abs))) {
            return $this->jsonError('File type not editable in scaffold v1', Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }
        $size = (int) @filesize($abs);
        if ($size > self::MAX_READ_BYTES) {
            return $this->jsonError('File too large to edit in scaffold v1', Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }
        $content = @file_get_contents($abs);
        if ($content === false) {
            return $this->jsonError('Cannot read file', Response::HTTP_FORBIDDEN);
        }
        return new JsonResponse([
            'path'    => ltrim(substr($abs, strlen($root)), DIRECTORY_SEPARATOR . '/'),
            'content' => $content,
            'size'    => $size,
            'mtime'   => (int) @filemtime($abs),
        ]);
    }

    public function writeAction(Request $request): JsonResponse
    {
        if (!$this->verifyCsrf($request)) {
            return $this->jsonError('Invalid CSRF token', Response::HTTP_FORBIDDEN);
        }
        $root = $this->resolveRoot($request);
        if ($root instanceof JsonResponse) {
            return $root;
        }
        $payload = json_decode((string) $request->getContent(), true);
        if (!is_array($payload) || !isset($payload['path']) || !array_key_exists('content', $payload)) {
            return $this->jsonError('Missing path or content', Response::HTTP_BAD_REQUEST);
        }
        $abs = $this->resolvePath($root, (string) $payload['path']);
        if ($abs === null) {
            return $this->jsonError('Invalid path', Response::HTTP_BAD_REQUEST);
        }
        if (!$this->isEditable(basename($abs))) {
            return $this->jsonError('File type not editable in scaffold v1', Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }
        $content = (string) $payload['content'];
        if (strlen($content) > self::MAX_WRITE_BYTES) {
            return $this->jsonError('File too large to write', Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }
        $parent = dirname($abs);
        if (!is_dir($parent)) {
            return $this->jsonError('Parent directory missing', Response::HTTP_NOT_FOUND);
        }
        $siteUser = $this->resolveSiteUser($request);
        if ($siteUser === null) {
            return $this->jsonError('Site user missing', Response::HTTP_CONFLICT);
        }
        try {
            $cmd = new SudoTeeCommand();
            $cmd->setTargetUser($siteUser);
            $cmd->setPath($abs);
            $cmd->setContent($content);
            (new CommandExecutor())->execute($cmd, 30);
        } catch (\Exception $e) {
            $this->logger->exception($e);
            return $this->jsonError('Write failed', Response::HTTP_FORBIDDEN);
        }
        return new JsonResponse(['ok' => true, 'bytes' => strlen($content)]);
    }

    public function deleteAction(Request $request): JsonResponse
    {
        if (!$this->verifyCsrf($request)) {
            return $this->jsonError('Invalid CSRF token', Response::HTTP_FORBIDDEN);
        }
        $root = $this->resolveRoot($request);
        if ($root instanceof JsonResponse) {
            return $root;
        }
        $abs = $this->resolvePath($root, (string) $request->query->get('path', ''));
        if ($abs === null || !file_exists($abs)) {
            return $this->jsonError('Not found', Response::HTTP_NOT_FOUND);
        }
        if ($abs === $root) {
            return $this->jsonError('Refusing to delete site root', Response::HTTP_BAD_REQUEST);
        }
        $siteUser = $this->resolveSiteUser($request);
        if ($siteUser === null) {
            return $this->jsonError('Site user missing', Response::HTTP_CONFLICT);
        }
        $isDir = is_dir($abs);
        if ($isDir) {
            // Shallow-only delete: reject non-empty directories.
            $children = @scandir($abs);
            if ($children !== false && count(array_diff($children, ['.', '..'])) > 0) {
                return $this->jsonError('Directory not empty', Response::HTTP_CONFLICT);
            }
        }
        try {
            $cmd = new SudoRmCommand();
            $cmd->setTargetUser($siteUser);
            $cmd->setPath($abs);
            $cmd->setIsDirectory($isDir);
            (new CommandExecutor())->execute($cmd, 30);
        } catch (\Exception $e) {
            $this->logger->exception($e);
            return $this->jsonError('Delete failed', Response::HTTP_FORBIDDEN);
        }
        return new JsonResponse(['ok' => true]);
    }

    private function resolveSiteUser(Request $request) : ?string
    {
        $domainName = (string) $request->get('domainName');
        $siteEntity = $this->siteEntityManager->findOneByDomainName($domainName);
        if ($siteEntity === null) {
            return null;
        }
        $siteUser = $siteEntity->getUser();
        return empty($siteUser) ? null : $siteUser;
    }

    /**
     * @return string|JsonResponse  absolute, real-path root, or an error response
     */
    private function resolveRoot(Request $request)
    {
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
        $root = sprintf('/home/%s/htdocs', $siteUser);
        $real = realpath($root);
        if ($real === false) {
            return $this->jsonError('Site htdocs missing on disk', Response::HTTP_NOT_FOUND);
        }
        return $real;
    }

    /**
     * Resolve user-supplied relative path against root, refusing traversal.
     */
    private function resolvePath(string $root, string $relative): ?string
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        if ($relative === '' || $relative === '.') {
            return $root;
        }
        if (strpos($relative, "\0") !== false) {
            return null;
        }
        $candidate = $root . DIRECTORY_SEPARATOR . $relative;
        // Normalize without requiring existence (so writes to new files work).
        $parent = dirname($candidate);
        $parentReal = realpath($parent);
        if ($parentReal === false) {
            return null;
        }
        $abs = $parentReal . DIRECTORY_SEPARATOR . basename($candidate);
        // Confine to root.
        if (strncmp($abs, $root . DIRECTORY_SEPARATOR, strlen($root) + 1) !== 0 && $abs !== $root) {
            return null;
        }
        return $abs;
    }

    private function isEditable(string $name): bool
    {
        if ($name === '' || $name[0] === '.') {
            // Allow common dotfiles by name.
            // .htaccess intentionally omitted — it can re-route PHP execution,
            // enable arbitrary handlers, or unset security headers (RCE primitive).
            // Edit .htaccess over SFTP/SSH.
            $allowedDot = ['.env', '.gitignore', '.editorconfig'];
            return in_array($name, $allowedDot, true);
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        return $ext !== '' && in_array($ext, self::EDITABLE_EXTS, true);
    }

    private function verifyCsrf(Request $request): bool
    {
        $token = $request->headers->get('X-CSRF-Token') ?: (string) $request->get('_token');
        if ($token === '' || $token === null) {
            return false;
        }
        return $this->csrfTokenManager->isTokenValid(new CsrfToken('file-manager', $token));
    }

    private function jsonError(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $message], $status);
    }
}
