<?php

namespace App\Controller\Frontend;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use App\Cdn\BunnyClient;
use App\Controller\Controller;
use App\Entity\Manager\ConfigManager;
use App\Entity\Manager\SiteManager;
use App\Entity\User as UserEntity;
use App\Event\EventQueue;
use App\Service\Logger;

class SiteCdnController extends Controller
{
    private SiteManager $siteManager;
    private BunnyClient $bunny;
    private ConfigManager $config;

    public function __construct(SiteManager $siteManager, BunnyClient $bunny, ConfigManager $config, TranslatorInterface $t, Logger $l)
    {
        $this->siteManager = $siteManager;
        $this->bunny = $bunny;
        $this->config = $config;
        parent::__construct($t, $l);
    }

    public function index(Request $request): Response
    {
        $site = $this->getAuthorizedSite($request);
        if (null === $site) {
            return $this->redirect($this->generateUrl('clp_sites'));
        }

        $cdnNotConfigured = false === $this->bunny->isConfigured();
        $pullZone = null;

        if (false === $cdnNotConfigured) {
            try {
                $zones = $this->bunny->listPullZones();
                $needle = strtolower($site->getDomainName());
                foreach ($zones as $zone) {
                    if (!is_array($zone)) {
                        continue;
                    }
                    $name = isset($zone['Name']) ? strtolower((string) $zone['Name']) : '';
                    if ($name === $needle) {
                        $pullZone = $zone;
                        break;
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->exception($e);
                $request->getSession()->getFlashBag()->set('danger', $this->translator->trans('An error has occurred, error message: %errorMessage%', ['%errorMessage%' => $e->getMessage()]));
            }
        }

        return $this->render('Frontend/Site/cdn.html.twig', [
            'site' => $site,
            'cdnNotConfigured' => $cdnNotConfigured,
            'pullZone' => $pullZone,
            'formErrors' => $this->formErrors,
        ]);
    }

    public function enable(Request $request): Response
    {
        $site = $this->getAuthorizedSite($request);
        if (null === $site) {
            return $this->redirect($this->generateUrl('clp_sites'));
        }
        $domainName = $site->getDomainName();
        $session = $request->getSession();

        if (false === $this->isCsrfTokenValid('site-cdn-enable', (string) $request->request->get('_token'))) {
            $session->getFlashBag()->set('danger', $this->translator->trans('Invalid CSRF token.'));
            return $this->redirect($this->generateUrl('clp_site_cdn', ['domainName' => $domainName]));
        }

        try {
            if (false === $this->bunny->isConfigured()) {
                $session->getFlashBag()->set('danger', $this->translator->trans('BunnyCDN is not configured.'));
                return $this->redirect($this->generateUrl('clp_site_cdn', ['domainName' => $domainName]));
            }

            $originUrl = 'https://' . $domainName;
            $pz = $this->bunny->createPullZone($domainName, $originUrl);

            $hostname = '';
            if (isset($pz['Hostnames']) && is_array($pz['Hostnames'])) {
                foreach ($pz['Hostnames'] as $h) {
                    if (is_array($h) && isset($h['Value']) && '' !== (string) $h['Value']) {
                        $hostname = (string) $h['Value'];
                        break;
                    }
                }
            }

            $user = $this->getUser();
            if (null !== $user) {
                EventQueue::addEvent(EventQueue::EVENT_SITE_CDN_ENABLE, $user, ['domainName' => $domainName, 'pullZoneId' => $pz['Id'] ?? null], $request);
            }

            if ('' !== $hostname) {
                $session->getFlashBag()->set('success', $this->translator->trans('Pull zone created. CDN hostname: %hostname% — add a CNAME from cdn.%domain% -> that hostname', ['%hostname%' => $hostname, '%domain%' => $domainName]));
            } else {
                $session->getFlashBag()->set('success', $this->translator->trans('Pull zone created for %domain%.', ['%domain%' => $domainName]));
            }
        } catch (\Throwable $e) {
            $this->logger->exception($e);
            $session->getFlashBag()->set('danger', $this->translator->trans('An error has occurred, error message: %errorMessage%', ['%errorMessage%' => $e->getMessage()]));
        }

        return $this->redirect($this->generateUrl('clp_site_cdn', ['domainName' => $domainName]));
    }

    public function disable(Request $request): Response
    {
        $site = $this->getAuthorizedSite($request);
        if (null === $site) {
            return $this->redirect($this->generateUrl('clp_sites'));
        }
        $domainName = $site->getDomainName();
        $session = $request->getSession();

        if (false === $this->isCsrfTokenValid('site-cdn-disable', (string) $request->request->get('_token'))) {
            $session->getFlashBag()->set('danger', $this->translator->trans('Invalid CSRF token.'));
            return $this->redirect($this->generateUrl('clp_site_cdn', ['domainName' => $domainName]));
        }

        try {
            $pullZoneId = (int) $request->request->get('pullZoneId');
            if ($pullZoneId <= 0) {
                $session->getFlashBag()->set('danger', $this->translator->trans('Missing or invalid pull zone id.'));
                return $this->redirect($this->generateUrl('clp_site_cdn', ['domainName' => $domainName]));
            }

            $this->bunny->deletePullZone($pullZoneId);

            $user = $this->getUser();
            if (null !== $user) {
                EventQueue::addEvent(EventQueue::EVENT_SITE_CDN_DISABLE, $user, ['domainName' => $domainName, 'pullZoneId' => $pullZoneId], $request);
            }

            $session->getFlashBag()->set('success', $this->translator->trans('Pull zone has been deleted.'));
        } catch (\Throwable $e) {
            $this->logger->exception($e);
            $session->getFlashBag()->set('danger', $this->translator->trans('An error has occurred, error message: %errorMessage%', ['%errorMessage%' => $e->getMessage()]));
        }

        return $this->redirect($this->generateUrl('clp_site_cdn', ['domainName' => $domainName]));
    }

    private function getAuthorizedSite(Request $request)
    {
        $domainName = (string) $request->get('domainName');
        if ('' === $domainName) {
            return null;
        }
        $siteEntity = $this->siteManager->findOneByDomainName($domainName);
        if (null === $siteEntity) {
            return null;
        }
        $user = $this->getUser();
        if (null === $user) {
            return null;
        }
        if (UserEntity::ROLE_USER === $user->getRole() && false === $user->hasSite($siteEntity)) {
            return null;
        }
        return $siteEntity;
    }
}
