<?php

namespace App\Controller\Admin;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use App\Controller\Controller;
use App\Service\Logger;
use App\Entity\MarketplaceApp;
use App\Entity\Manager\MarketplaceAppManager;
use App\Entity\Manager\SiteManager;

class MarketplaceController extends Controller
{
    private MarketplaceAppManager $marketplaceAppManager;
    private SiteManager $siteManager;

    public function __construct(
        MarketplaceAppManager $marketplaceAppManager,
        SiteManager $siteManager,
        TranslatorInterface $translator,
        Logger $logger
    ) {
        $this->marketplaceAppManager = $marketplaceAppManager;
        $this->siteManager = $siteManager;
        parent::__construct($translator, $logger);
    }

    public function index(Request $request): Response
    {
        // Lazy seed so a fresh DB shows the catalog even if the install-hook didn't run.
        $apps = $this->marketplaceAppManager->findAllOrdered();
        if (0 === count($apps)) {
            try {
                $this->marketplaceAppManager->seedDefaults();
                $apps = $this->marketplaceAppManager->findAllOrdered();
            } catch (\Exception $e) {
                $this->logger->exception($e);
            }
        }

        $user = $this->getUser();
        return $this->render('Admin/Marketplace/index.html.twig', [
            'user' => $user,
            'apps' => $apps,
        ]);
    }

    public function install(Request $request, string $slug): Response
    {
        $this->checkCsrfToken($request, 'marketplace-install');
        $session = $request->getSession();
        $app = $this->marketplaceAppManager->findOneBySlug($slug);

        if (null === $app) {
            $session->getFlashBag()->set('danger', $this->translator->trans('App not found.'));
            return $this->redirect($this->generateUrl('clp_admin_marketplace'));
        }

        if (false === $app->isAvailable()) {
            $session->getFlashBag()->set('warning', $this->translator->trans('%name% is coming soon.', ['%name%' => $app->getName()]));
            return $this->redirect($this->generateUrl('clp_admin_marketplace'));
        }

        // ponytail: stub — real per-app installers are wired via the existing site
        // creation flow. For WordPress the admin should use the canonical wizard at
        // /site/new/wordpress which already calls WordPressInstaller end to end.
        if (MarketplaceApp::TYPE_WORDPRESS === $app->getType()) {
            return $this->redirect($this->generateUrl('clp_site_wordpress_new'));
        }

        if (MarketplaceApp::TYPE_GHOST === $app->getType()) {
            return $this->redirect($this->generateUrl('clp_admin_marketplace_ghost'));
        }

        if (MarketplaceApp::TYPE_NEXTCLOUD === $app->getType()) {
            return $this->redirect($this->generateUrl('clp_admin_marketplace_nextcloud'));
        }

        $session->getFlashBag()->set('warning', $this->translator->trans('Installer for %name% is not implemented yet.', ['%name%' => $app->getName()]));
        return $this->redirect($this->generateUrl('clp_admin_marketplace'));
    }

    public function ghost(Request $request): Response
    {
        $user = $this->getUser();
        $apps = $this->marketplaceAppManager->findAllOrdered();
        $sites = $this->siteManager->getUserSites($user, ['domainName' => 'ASC']);

        return $this->render('Admin/Marketplace/ghost.html.twig', [
            'user' => $user,
            'apps' => $apps,
            'sites' => $sites,
        ]);
    }

    public function nextcloud(Request $request): Response
    {
        $user = $this->getUser();
        $apps = $this->marketplaceAppManager->findAllOrdered();
        $sites = $this->siteManager->getUserSites($user, ['domainName' => 'ASC']);

        return $this->render('Admin/Marketplace/nextcloud.html.twig', [
            'user' => $user,
            'apps' => $apps,
            'sites' => $sites,
        ]);
    }
}
