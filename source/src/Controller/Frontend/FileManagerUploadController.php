<?php

namespace App\Controller\Frontend;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use App\Controller\Controller;
use App\Controller\Frontend\SiteAccessTrait;
use App\Entity\Manager\SiteManager as SiteEntityManager;
use App\Service\Logger;
use App\System\CommandExecutor;
use App\System\Command\SudoTeeCommand;

class FileManagerUploadController extends Controller
{
    use SiteAccessTrait;

    private const MAX_UPLOAD_BYTES = 16777216; // 16 MiB
    private SiteEntityManager $siteEntityManager;
    private CsrfTokenManagerInterface $csrfTokenManager;

    public function __construct(SiteEntityManager $siteEntityManager, CsrfTokenManagerInterface $csrf, TranslatorInterface $t, Logger $l) {
        $this->siteEntityManager = $siteEntityManager;
        $this->csrfTokenManager = $csrf;
        parent::__construct($t, $l);
    }

    public function uploadAction(Request $request) : JsonResponse {
      // CSRF check
      $token = $request->headers->get('X-CSRF-Token') ?: (string)$request->request->get('_token');
      if (!$this->csrfTokenManager->isTokenValid(new CsrfToken('file-manager', $token))) {
        return new JsonResponse(['error'=>'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
      }
      $site = $this->siteEntityManager->findOneByDomainName((string)$request->get('domainName'));
      $denied = $this->denySiteAccessAsJson($site);
      if ($denied !== null) {
        return $denied;
      }
      $siteUser = $site->getUser();
      if (empty($siteUser)) return new JsonResponse(['error'=>'Site user missing'], Response::HTTP_CONFLICT);
      $root = realpath(sprintf('/home/%s/htdocs', $siteUser));
      if ($root === false) return new JsonResponse(['error'=>'Site htdocs missing'], Response::HTTP_NOT_FOUND);
      $rel = ltrim(str_replace('\\','/', (string)$request->request->get('path','')), '/');
      if (strpos($rel,'..') !== false || strpos($rel,"\0") !== false) return new JsonResponse(['error'=>'Invalid path'], Response::HTTP_BAD_REQUEST);
      $file = $request->files->get('file');
      if (!$file) return new JsonResponse(['error'=>'No file uploaded'], Response::HTTP_BAD_REQUEST);
      if ($file->getSize() > self::MAX_UPLOAD_BYTES) return new JsonResponse(['error'=>'File too large'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
      $basename = basename($file->getClientOriginalName());
      if ($basename === '' || $basename[0] === '.') return new JsonResponse(['error'=>'Invalid filename'], Response::HTTP_BAD_REQUEST);
      $targetDir = $rel === '' ? $root : $root . '/' . $rel;
      $targetReal = realpath($targetDir);
      if ($targetReal === false || ($targetReal !== $root && strncmp($targetReal, $root . '/', strlen($root) + 1) !== 0)) {
        return new JsonResponse(['error'=>'Path outside root'], Response::HTTP_BAD_REQUEST);
      }
      $abs = $targetReal . '/' . $basename;
      // If $abs already exists, re-resolve through any symlink and confirm it stays inside root.
      if (file_exists($abs) && is_link($abs)) {
        $absReal = realpath($abs);
        if ($absReal === false || ($absReal !== $root && strncmp($absReal, $root . '/', strlen($root) + 1) !== 0)) {
          return new JsonResponse(['error'=>'Path outside root'], Response::HTTP_BAD_REQUEST);
        }
      }
      $content = (string)@file_get_contents($file->getPathname());
      try {
        $cmd = new SudoTeeCommand();
        $cmd->setTargetUser($siteUser);
        $cmd->setPath($abs);
        $cmd->setContent($content);
        (new CommandExecutor())->execute($cmd, 60);
      } catch (\Exception $e) {
        $this->logger->exception($e);
        return new JsonResponse(['error'=>'Upload failed: '.$e->getMessage()], Response::HTTP_FORBIDDEN);
      }
      return new JsonResponse(['ok'=>true, 'name'=>$basename, 'bytes'=>strlen($content)]);
    }
}
