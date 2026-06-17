<?php

namespace App\Controller\Frontend;

use App\Controller\Controller;
use App\Entity\Manager\SiteManager as SiteEntityManager;
use App\Event\EventQueue;
use App\Service\Logger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

class WpAutoUpdateController extends Controller
{
    private SiteEntityManager $siteEntityManager;

    public function __construct(SiteEntityManager $siteEntityManager, TranslatorInterface $translator, Logger $logger)
    {
        $this->siteEntityManager = $siteEntityManager;
        parent::__construct($translator, $logger);
    }

    public function update(Request $request): Response
    {
        $domainName = (string) $request->get('domainName');
        $session = $request->getSession();
        if (false === $this->isCsrfTokenValid('default', (string) $request->request->get('_token'))) {
            return $this->redirect($this->generateUrl('clp_site_settings', ['domainName' => $domainName]));
        }
        $site = $this->siteEntityManager->findOneBy(['domainName' => $domainName]);
        if (null === $site || 'php' !== $site->getType()) {
            return $this->redirect($this->generateUrl('clp_sites'));
        }
        $mode = (string) $request->request->get('wpAutoUpdate', 'none');
        if (false === in_array($mode, ['none', 'security', 'all'], true)) {
            $mode = 'none';
        }
        try {
            $site->setWpAutoUpdate($mode);
            $this->siteEntityManager->updateEntity($site);
            $user = $this->getUser();
            EventQueue::addEvent('site.wp_auto_update.set', $user, ['site' => $domainName, 'mode' => $mode], $request);
            $session->getFlashBag()->set('success', $this->translator->trans('WordPress auto-update mode saved.'));
        } catch (\Exception $e) {
            $this->logger->exception($e);
            $session->getFlashBag()->set('danger', $this->translator->trans('An error has occurred, error message: %errorMessage%', ['%errorMessage%' => $e->getMessage()]));
        }
        return $this->redirect($this->generateUrl('clp_site_settings', ['domainName' => $domainName]));
    }
}
