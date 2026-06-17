<?php

namespace App\Site\Ssl\Dns;

use App\Entity\Manager\ConfigManager;

class DnsProviderFactory
{
    public const CONFIG_PROVIDER = "dns_provider";
    public const CONFIG_CREDENTIALS = "dns_provider_credentials";

    private ConfigManager $configManager;

    public function __construct(ConfigManager $configManager)
    {
        $this->configManager = $configManager;
    }

    /**
     * Returns the configured provider, or null when no provider is set up.
     */
    public function getConfigured(): ?DnsProviderInterface
    {
        $provider = $this->configManager->get(self::CONFIG_PROVIDER);
        if (true === empty($provider)) {
            return null;
        }
        $credentialsRaw = $this->configManager->get(self::CONFIG_CREDENTIALS);
        $credentials = $this->decrypt((string) $credentialsRaw);
        $credentialsData = json_decode((string) $credentials, true) ?: [];

        switch ($provider) {
            case "cloudflare":
                $token = $credentialsData["api_token"] ?? "";
                if (true === empty($token)) {
                    throw new \Exception("Cloudflare DNS provider configured but api_token is missing.");
                }
                return new CloudflareDnsProvider($token);
            case "route53":
            case "digitalocean":
                // ponytail: stub — only cloudflare is wired for v1.
                throw new \Exception(sprintf("DNS provider \"%s\" is not yet implemented.", $provider));
        }
        throw new \Exception(sprintf("Unknown DNS provider \"%s\".", $provider));
    }

    public function saveCredentials(string $provider, array $credentials): void
    {
        $this->configManager->set(self::CONFIG_PROVIDER, $provider);
        $this->configManager->set(self::CONFIG_CREDENTIALS, $this->encrypt(json_encode($credentials)));
    }

    private function encrypt(string $plain): string
    {
        $key = $this->getKey();
        $iv = random_bytes(16);
        $cipher = openssl_encrypt($plain, "aes-256-cbc", $key, OPENSSL_RAW_DATA, $iv);
        if (false === $cipher) {
            throw new \Exception("Unable to encrypt DNS provider credentials.");
        }
        return base64_encode($iv . $cipher);
    }

    private function decrypt(string $blob): string
    {
        if ("" === $blob) {
            return "";
        }
        $raw = base64_decode($blob, true);
        if (false === $raw || strlen($raw) < 17) {
            return "";
        }
        $iv = substr($raw, 0, 16);
        $cipher = substr($raw, 16);
        $plain = openssl_decrypt($cipher, "aes-256-cbc", $this->getKey(), OPENSSL_RAW_DATA, $iv);
        return false === $plain ? "" : $plain;
    }

    private function getKey(): string
    {
        // Derive a stable per-host key from the LE account key (already secret, on-box only).
        $material = (string) $this->configManager->get("le_private_key");
        if ("" === $material) {
            $material = "cloudpanel-dns-fallback-key";
        }
        return hash("sha256", "clp-dns-v1|" . $material, true);
    }
}
