<?php

namespace App\Controller\Admin;

use App\Controller\Controller;
use App\Entity\Manager\ConfigManager;
use App\Site\Ssl\Dns\DnsProviderFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class DnsProviderController extends Controller
{
    private ConfigManager $configManager;
    private DnsProviderFactory $dnsProviderFactory;

    public function __construct(ConfigManager $configManager, DnsProviderFactory $dnsProviderFactory)
    {
        $this->configManager = $configManager;
        $this->dnsProviderFactory = $dnsProviderFactory;
    }

    public function index(Request $request): Response
    {
        if (true === $request->isMethod("POST")) {
            $this->checkCsrfToken($request, "dns_provider_settings");
            $provider = trim((string) $request->request->get("dns_provider"));
            $apiToken = (string) $request->request->get("api_token");
            if ("" === $provider) {
                $this->configManager->set(DnsProviderFactory::CONFIG_PROVIDER, "");
                $this->configManager->set(DnsProviderFactory::CONFIG_CREDENTIALS, "");
                $request->getSession()->getFlashBag()->set("success", "DNS provider disabled.");
            } else {
                if ("" !== $apiToken) {
                    $this->dnsProviderFactory->saveCredentials($provider, ["api_token" => $apiToken]);
                } else {
                    $this->configManager->set(DnsProviderFactory::CONFIG_PROVIDER, $provider);
                }
                $request->getSession()->getFlashBag()->set("success", "DNS provider saved.");
            }
            return $this->redirectToRoute("clp_admin_dns_provider");
        }
        $provider = (string) $this->configManager->get(DnsProviderFactory::CONFIG_PROVIDER);
        return $this->render("Admin/Settings/dns-provider.html.twig", ["provider" => $provider]);
    }
}
