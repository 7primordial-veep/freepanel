<?php

namespace App\Cloudflare;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\GuzzleException;
use App\Entity\Manager\ConfigManager;
use App\Service\Crypto;

/**
 * Thin Cloudflare API v4 client.
 *
 * Reads the API token from the config table (key: cloudflare_api_token,
 * stored Crypto::encrypt'd to match the rest of CloudPanel's secret storage).
 * If absent, falls back to the acme-dns01 env var CF_API_TOKEN so an existing
 * dns-01 ACME setup keeps working without re-entry.
 *
 * Minimum-viable surface:
 *   - listZones() / findZoneForDomain()
 *   - addDnsRecord(zoneId, type, name, content, proxied=true, ttl=1, priority=null)
 *   - listDnsRecords(zoneId, perPage=100)
 *   - deleteDnsRecord(zoneId, recordId)
 *   - purgeCache(zoneId, hosts=null)  // null = purge_everything
 *   - setSecurityLevel(zoneId, level) // off|essentially_off|low|medium|high|under_attack
 */
class Client
{
    private const API_BASE = "https://api.cloudflare.com/client/v4/";
    private const TIMEOUT = 10;

    private ConfigManager $configManager;
    private ?HttpClient $http = null;
    private ?string $cachedToken = null;

    public function __construct(ConfigManager $configManager)
    {
        $this->configManager = $configManager;
    }

    public function isConfigured(): bool
    {
        return null !== $this->getToken();
    }

    public function getToken(): ?string
    {
        if (null !== $this->cachedToken) {
            return $this->cachedToken;
        }
        $encrypted = $this->configManager->get("cloudflare_api_token");
        if (false === empty($encrypted)) {
            try {
                $this->cachedToken = Crypto::decrypt($encrypted);
                return $this->cachedToken;
            } catch (\Throwable $e) {
                // fall through to env
            }
        }
        $envToken = $_ENV["CF_API_TOKEN"] ?? getenv("CF_API_TOKEN") ?: null;
        if (false === empty($envToken)) {
            $this->cachedToken = $envToken;
            return $this->cachedToken;
        }
        return null;
    }

    public function listZones(): array
    {
        $response = $this->request("GET", "zones?per_page=50");
        return $response["result"] ?? [];
    }

    public function findZoneForDomain(string $domainName): ?array
    {
        $domainName = strtolower(trim($domainName));
        if ('' === $domainName) {
            return null;
        }

        // Walk candidate apex domains from shortest (2 labels) to longest.
        // e.g. foo.bar.example.co.uk -> [example.co.uk, bar.example.co.uk, foo.bar.example.co.uk]
        // The first zones?name=<candidate> hit wins. Avoids the 50-zone pagination cap.
        $labels = explode('.', $domainName);
        $count = count($labels);
        for ($take = 2; $take <= $count; $take++) {
            $candidate = implode('.', array_slice($labels, $count - $take));
            try {
                $response = $this->request("GET", "zones?name=" . rawurlencode($candidate));
            } catch (\RuntimeException $e) {
                // Network/API hiccup on one candidate shouldn't abort the search.
                continue;
            }
            $result = $response["result"] ?? [];
            if (false === empty($result) && is_array($result[0] ?? null)) {
                return $result[0];
            }
        }

        // Fallback: list+match (rare edge cases like vanity TLDs not matched by exact-name lookup).
        $zones = $this->listZones();
        $best = null;
        $bestLen = 0;
        foreach ($zones as $zone) {
            $zoneName = strtolower($zone["name"] ?? '');
            if ('' === $zoneName) {
                continue;
            }
            if ($domainName === $zoneName || str_ends_with($domainName, "." . $zoneName)) {
                $len = strlen($zoneName);
                if ($len > $bestLen) {
                    $best = $zone;
                    $bestLen = $len;
                }
            }
        }
        return $best;
    }

    public function addDnsRecord(string $zoneId, string $type, string $name, string $content, bool $proxied = true, int $ttl = 1, ?int $priority = null): array
    {
        $payload = [
            "type" => $type,
            "name" => $name,
            "content" => $content,
            "ttl" => $ttl,
            "proxied" => $proxied,
        ];
        if (null !== $priority) {
            $payload["priority"] = $priority;
        }
        $response = $this->request("POST", sprintf("zones/%s/dns_records", rawurlencode($zoneId)), $payload);
        return $response["result"] ?? $response;
    }

    public function listDnsRecords(string $zoneId, int $perPage = 100): array
    {
        $response = $this->request("GET", sprintf("zones/%s/dns_records?per_page=%d", rawurlencode($zoneId), $perPage));
        return $response["result"] ?? [];
    }

    public function deleteDnsRecord(string $zoneId, string $recordId): void
    {
        $this->request("DELETE", sprintf("zones/%s/dns_records/%s", rawurlencode($zoneId), rawurlencode($recordId)));
    }

    public function purgeCache(string $zoneId, ?array $hosts = null): array
    {
        if (null === $hosts || 0 === count($hosts)) {
            $payload = ["purge_everything" => true];
        } else {
            $payload = ["hosts" => array_values($hosts)];
        }
        return $this->request("POST", sprintf("zones/%s/purge_cache", rawurlencode($zoneId)), $payload);
    }

    public function setSecurityLevel(string $zoneId, string $level): array
    {
        $allowed = ["off", "essentially_off", "low", "medium", "high", "under_attack"];
        if (false === in_array($level, $allowed, true)) {
            throw new \InvalidArgumentException(sprintf("Invalid security level '%s'.", $level));
        }
        return $this->request("PATCH", sprintf("zones/%s/settings/security_level", rawurlencode($zoneId)), ["value" => $level]);
    }

    private function request(string $method, string $path, array $json = null): array
    {
        $token = $this->getToken();
        if (null === $token) {
            throw new \RuntimeException("Cloudflare API token is not configured.");
        }
        $http = $this->getHttpClient();
        $options = [
            "headers" => [
                "Authorization" => "Bearer " . $token,
                "Accept" => "application/json",
                "Content-Type" => "application/json",
            ],
        ];
        if (null !== $json) {
            $options["json"] = $json;
        }
        try {
            $response = $http->request($method, self::API_BASE . $path, $options);
        } catch (GuzzleException $e) {
            throw new \RuntimeException("Cloudflare API request failed: " . $e->getMessage(), 0, $e);
        }
        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);
        if (false === is_array($decoded)) {
            throw new \RuntimeException("Cloudflare API returned non-JSON response.");
        }
        if (false === ($decoded["success"] ?? false)) {
            $errors = $decoded["errors"] ?? [];
            $first = $errors[0]["message"] ?? "unknown error";
            throw new \RuntimeException(sprintf("Cloudflare API error: %s", $first));
        }
        return $decoded;
    }

    private function getHttpClient(): HttpClient
    {
        if (null === $this->http) {
            $this->http = new HttpClient(["timeout" => self::TIMEOUT, "http_errors" => false]);
        }
        return $this->http;
    }
}
