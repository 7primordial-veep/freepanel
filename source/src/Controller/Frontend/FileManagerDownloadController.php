<?php

namespace App\Controller\Frontend;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Contracts\Translation\TranslatorInterface;
use App\Controller\Controller;
use App\Entity\Manager\SiteManager as SiteEntityManager;
use App\Service\Logger;

class FileManagerDownloadController extends Controller
{
    private const MAX_DOWNLOAD_BYTES = 268435456; // 256 MiB

    private SiteEntityManager $siteEntityManager;

    public function __construct(SiteEntityManager $sm, TranslatorInterface $t, Logger $l)
    {
        $this->siteEntityManager = $sm;
        parent::__construct($t, $l);
    }

    public function downloadAction(Request $request): Response
    {
        $site = $this->siteEntityManager->findOneByDomainName((string)$request->get('domainName'));
        if ($site === null) {
            return new Response('Site not found', 404);
        }

        $user = $this->getUser();
        if ($user === null) {
            return new Response('Unauthorized', 401);
        }

        $owner = $site->getUser();
        if ($owner !== null && method_exists($user, 'getRoles')) {
            $isAdmin = in_array('ROLE_ADMIN', $user->getRoles(), true);
            if (!$isAdmin && $user->getUserIdentifier() !== $owner) {
                return new Response('Forbidden', 403);
            }
        }

        $siteUser = $site->getUser();
        if (empty($siteUser)) {
            return new Response('Site user missing', 409);
        }

        $root = realpath(sprintf('/home/%s/htdocs', $siteUser));
        if ($root === false) {
            return new Response('Htdocs missing', 404);
        }

        $rel = ltrim(str_replace('\\', '/', (string)$request->query->get('path', '')), '/');
        if ($rel === '' || strpos($rel, '..') !== false || strpos($rel, "\0") !== false) {
            return new Response('Invalid path', 400);
        }

        $abs = realpath($root . '/' . $rel);
        if ($abs === false || strncmp($abs, $root . '/', strlen($root) + 1) !== 0) {
            return new Response('Outside root', 400);
        }

        if (!is_file($abs)) {
            return new Response('Not found', 404);
        }

        if ((int)@filesize($abs) > self::MAX_DOWNLOAD_BYTES) {
            return new Response('Too large', 413);
        }

        $mime = 'application/octet-stream';
        if (function_exists('finfo_open')) {
            $fi = @finfo_open(FILEINFO_MIME_TYPE);
            if ($fi !== false) {
                $sniffed = @finfo_file($fi, $abs);
                if ($sniffed) {
                    $mime = $sniffed;
                }
                @finfo_close($fi);
            }
        }

        $response = new BinaryFileResponse($abs);
        $response->headers->set('Content-Type', $mime);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, basename($abs));

        return $response;
    }
}
