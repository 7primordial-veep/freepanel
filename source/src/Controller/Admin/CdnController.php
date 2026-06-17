<?php

namespace App\Controller\Admin;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use App\Cdn\BunnyClient;
use App\Controller\Controller;
use App\Entity\Manager\ConfigManager;
use App\Event\EventQueue;
use App\Service\Crypto;
use App\Service\Logger;

class CdnController extends Controller
{
    private BunnyClient $bunny;
    private ConfigManager $config;

    public function __construct(BunnyClient $bunny, ConfigManager $config, TranslatorInterface $t, Logger $l)
    {
        $this->bunny = $bunny;
        $this->config = $config;
        parent::__construct($t, $l);
    }

    public function settings(Request $request): Response
    {
        return $this->render('Admin/Cdn/settings.html.twig', [
            'apiKeyConfigured' => $this->bunny->isConfigured(),
            'active' => 'cdn',
        ]);
    }

    public function saveSettings(Request $request): Response
    {
        $session = $request->getSession();

        if (false === $this->isCsrfTokenValid('cdn-save-settings', (string) $request->request->get('_token'))) {
            $session->getFlashBag()->set('danger', $this->translator->trans('Invalid CSRF token.'));
            return $this->redirect($this->generateUrl('clp_admin_cdn'));
        }

        try {
            $apiKey = trim((string) $request->request->get('apiKey'));
            if ('' !== $apiKey) {
                $this->config->set('bunny_api_key', Crypto::encrypt($apiKey));
            }

            $user = $this->getUser();
            if (null !== $user) {
                EventQueue::addEvent(EventQueue::EVENT_CDN_SETTINGS_UPDATE, $user, [], $request);
            }

            $session->getFlashBag()->set('success', $this->translator->trans('CDN settings have been saved.'));
        } catch (\Throwable $e) {
            $this->logger->exception($e);
            $session->getFlashBag()->set('danger', $this->translator->trans('An error has occurred, error message: %errorMessage%', ['%errorMessage%' => $e->getMessage()]));
        }

        return $this->redirect($this->generateUrl('clp_admin_cdn'));
    }
}
