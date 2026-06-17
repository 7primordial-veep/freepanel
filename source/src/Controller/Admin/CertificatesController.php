<?php

namespace App\Controller\Admin;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use App\Controller\Controller;
use App\Entity\Certificate as CertificateEntity;
use App\Entity\Manager\CertificateManager as CertificateEntityManager;
use App\Service\Logger;

class CertificatesController extends Controller
{
    private CertificateEntityManager $certificateEntityManager;

    public function __construct(CertificateEntityManager $certificateEntityManager, TranslatorInterface $translator, Logger $logger)
    {
        $this->certificateEntityManager = $certificateEntityManager;
        parent::__construct($translator, $logger);
    }

    public function index(Request $request) : Response
    {
        $certificates = $this->certificateEntityManager->findAll();
        $now = new \DateTime('now');
        $rows = [];
        foreach ($certificates as $certificateEntity) {
            /** @var CertificateEntity $certificateEntity */
            $expiresAt = $certificateEntity->getExpiresAt();
            $daysToExpire = null;
            if ($expiresAt instanceof \DateTime) {
                $daysToExpire = (int) round(($expiresAt->getTimestamp() - $now->getTimestamp()) / 86400);
            }
            $status = 'ok';
            if ($daysToExpire === null) {
                $status = 'unknown';
            } elseif ($daysToExpire < 0) {
                $status = 'expired';
            } elseif ($daysToExpire <= 7) {
                $status = 'critical';
            } elseif ($daysToExpire <= 30) {
                $status = 'warning';
            }
            $typeLabel = 'Self-Signed';
            if ((int) $certificateEntity->getType() === CertificateEntity::TYPE_LETS_ENCRYPT) {
                $typeLabel = "Let's Encrypt";
            } elseif ((int) $certificateEntity->getType() === CertificateEntity::TYPE_IMPORTED) {
                $typeLabel = 'Imported';
            }
            $site = $certificateEntity->getSite();
            $rows[] = [
                'uid' => $certificateEntity->getUid(),
                'domainName' => $site ? $site->getDomainName() : '-',
                'type' => $typeLabel,
                'typeId' => (int) $certificateEntity->getType(),
                'isDefault' => (bool) $certificateEntity->getDefaultCertificate(),
                'expiresAt' => $expiresAt,
                'daysToExpire' => $daysToExpire,
                'status' => $status,
                'lastRenewAttemptAt' => method_exists($certificateEntity, 'getLastRenewAttemptAt') ? $certificateEntity->getLastRenewAttemptAt() : null,
                'lastRenewError' => method_exists($certificateEntity, 'getLastRenewError') ? $certificateEntity->getLastRenewError() : null,
            ];
        }
        usort($rows, function ($a, $b) {
            $da = $a['daysToExpire'] === null ? PHP_INT_MAX : $a['daysToExpire'];
            $db = $b['daysToExpire'] === null ? PHP_INT_MAX : $b['daysToExpire'];
            return $da <=> $db;
        });
        return $this->render('Admin/Certificates/index.html.twig', ['rows' => $rows]);
    }

    public function renewAll(Request $request) : Response
    {
        $this->checkCsrfToken($request, 'admin-certificates-renew-all');
        $session = $request->getSession();
        try {
            // ponytail: shell out to clpctl rather than re-implementing renew DI inside the controller.
            $output = [];
            $exitCode = 0;
            @exec('/usr/bin/clpctl lets-encrypt:renew:certificates 2>&1', $output, $exitCode);
            $now = new \DateTime('now');
            $errorText = $exitCode === 0 ? null : trim(implode("\n", $output));
            foreach ($this->certificateEntityManager->findAll() as $certificateEntity) {
                if ((int) $certificateEntity->getType() !== CertificateEntity::TYPE_LETS_ENCRYPT) {
                    continue;
                }
                if (method_exists($certificateEntity, 'setLastRenewAttemptAt')) {
                    $certificateEntity->setLastRenewAttemptAt($now);
                }
                if (method_exists($certificateEntity, 'setLastRenewError')) {
                    $certificateEntity->setLastRenewError($errorText);
                }
                $this->certificateEntityManager->updateEntity($certificateEntity);
            }
            if ($exitCode === 0) {
                $session->getFlashBag()->set('success', $this->translator->trans('Certificate renew was triggered.'));
            } else {
                $session->getFlashBag()->set('danger', $this->translator->trans('Certificate renew failed: %errorMessage%', ['%errorMessage%' => substr((string) $errorText, 0, 500)]));
            }
        } catch (\Exception $e) {
            $this->logger->exception($e);
            $session->getFlashBag()->set('danger', $this->translator->trans('An error has occurred, error message: %errorMessage%', ['%errorMessage%' => $e->getMessage()]));
        }
        return $this->redirect($this->generateUrl('clp_admin_certificates'));
    }
}
