<?php

namespace App\Controller\Admin;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use App\Controller\Controller;
use App\Service\Logger;
use App\Service\Crypto;
use App\Entity\Manager\ConfigManager;
use App\Cloudflare\Client as CloudflareClient;

class CloudflareController extends Controller
{
    private ConfigManager $configManager;
    private CloudflareClient $cloudflare;

    public function __construct(ConfigManager $configManager, CloudflareClient $cloudflare, TranslatorInterface $translator, Logger $logger)
    {
        $this->configManager = $configManager;
        $this->cloudflare = $cloudflare;
        parent::__construct($translator, $logger);
    }

    public function settings(Request $request): Response
    {
        $session = $request->getSession();
        if (true === $request->isMethod("POST")) {
            $this->checkCsrfToken($request, "cloudflare-settings");
            try {
                $token = trim((string) $request->request->get("api_token", ''));
                $securityLevel = (string) $request->request->get("security_level", "medium");
                $autoDns = $request->request->has("auto_dns") ? "1" : "0";
                $autoProxied = $request->request->has("auto_proxied") ? "1" : "0";
                if ('' !== $token) {
                    $this->configManager->set("cloudflare_api_token", Crypto::encrypt($token));
                }
                $this->configManager->set("cloudflare_security_level", $securityLevel);
                $this->configManager->set("cloudflare_auto_dns", $autoDns);
                $this->configManager->set("cloudflare_auto_proxied", $autoProxied);
                $session->getFlashBag()->set("success", $this->translator->trans("Cloudflare settings have been saved."));
            } catch (\Throwable $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
            return $this->redirect($this->generateUrl("clp_admin_cloudflare_settings"));
        }
        $hasToken = $this->cloudflare->isConfigured();
        $securityLevel = $this->configManager->get("cloudflare_security_level") ?? "medium";
        $autoDns = "1" === ($this->configManager->get("cloudflare_auto_dns") ?? "0");
        $autoProxied = "1" === ($this->configManager->get("cloudflare_auto_proxied") ?? "1");
        return $this->render("Admin/Cloudflare/index.html.twig", [
            "active" => "cloudflare",
            "hasToken" => $hasToken,
            "securityLevel" => $securityLevel,
            "autoDns" => $autoDns,
            "autoProxied" => $autoProxied,
        ]);
    }

    public function purgeSiteCache(Request $request): Response
    {
        $this->checkCsrfToken($request, "cloudflare-purge");
        $domainName = (string) $request->get("domainName");
        $session = $request->getSession();
        try {
            if (false === $this->cloudflare->isConfigured()) {
                throw new \RuntimeException("Cloudflare is not configured.");
            }
            $zone = $this->cloudflare->findZoneForDomain($domainName);
            if (null === $zone) {
                throw new \RuntimeException(sprintf("No Cloudflare zone matches domain '%s'.", $domainName));
            }
            $this->cloudflare->purgeCache($zone["id"], [$domainName, "www." . ltrim($domainName, "www.")]);
            $session->getFlashBag()->set("success", $this->translator->trans("Cloudflare cache has been purged for %domain%.", ["%domain%" => $domainName]));
        } catch (\Throwable $e) {
            $this->logger->exception($e);
            $session->getFlashBag()->set("danger", $this->translator->trans("Cloudflare purge failed: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
        }
        return $this->redirect($this->generateUrl("clp_site", ["domainName" => $domainName]));
    }

    public function applySecurityLevel(Request $request): Response
    {
        $this->checkCsrfToken($request, "cloudflare-security-apply");
        $session = $request->getSession();
        $level = $this->configManager->get("cloudflare_security_level") ?? "medium";
        try {
            if (false === $this->cloudflare->isConfigured()) {
                throw new \RuntimeException("Cloudflare is not configured.");
            }
            $zones = $this->cloudflare->listZones();
            $count = 0;
            foreach ($zones as $zone) {
                try {
                    $this->cloudflare->setSecurityLevel($zone["id"], $level);
                    $count++;
                } catch (\Throwable $inner) {
                    $this->logger->exception($inner);
                }
            }
            $session->getFlashBag()->set("success", $this->translator->trans("Cloudflare security level '%level%' applied to %count% zone(s).", ["%level%" => $level, "%count%" => $count]));
        } catch (\Throwable $e) {
            $this->logger->exception($e);
            $session->getFlashBag()->set("danger", $this->translator->trans("Cloudflare apply failed: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
        }
        return $this->redirect($this->generateUrl("clp_admin_cloudflare_settings"));
    }
}
