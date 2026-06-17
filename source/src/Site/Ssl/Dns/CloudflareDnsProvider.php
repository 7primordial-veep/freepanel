<?php

namespace App\Site\Ssl\Dns;

use GuzzleHttp\Client as HttpClient;

class CloudflareDnsProvider implements DnsProviderInterface
{
    private string $apiToken;
    private HttpClient $httpClient;

    public function __construct(string $apiToken)
    {
        $this->apiToken = $apiToken;
        $this->httpClient = new HttpClient([
            "base_uri" => "https://api.cloudflare.com/client/v4/",
            "timeout" => 20,
            "headers" => [
                "Authorization" => "Bearer " . $apiToken,
                "Content-Type" => "application/json",
                "Accept" => "application/json",
            ],
        ]);
    }

    public function getName(): string
    {
        return "cloudflare";
    }

    public function createTxtRecord(string $name, string $value, int $ttl = 60): string
    {
        $zoneId = $this->resolveZoneId($name);
        $response = $this->httpClient->post(sprintf("zones/%s/dns_records", $zoneId), [
            "json" => [
                "type" => "TXT",
                "name" => $name,
                "content" => $value,
                "ttl" => $ttl,
            ],
        ]);
        $data = json_decode((string) $response->getBody(), true);
        if (true !== ($data["success"] ?? false) || empty($data["result"]["id"])) {
            throw new \Exception("Cloudflare: failed to create TXT record for " . $name);
        }
        return sprintf("%s:%s", $zoneId, $data["result"]["id"]);
    }

    public function deleteTxtRecord(string $name, string $recordId): void
    {
        if (false === strpos($recordId, ":")) {
            return;
        }
        [$zoneId, $id] = explode(":", $recordId, 2);
        try {
            $this->httpClient->delete(sprintf("zones/%s/dns_records/%s", $zoneId, $id));
        } catch (\Exception $e) {
            // best-effort cleanup
        }
    }

    private function resolveZoneId(string $fqdn): string
    {
        $fqdn = rtrim($fqdn, ".");
        if (0 === strpos($fqdn, "_acme-challenge.")) {
            $fqdn = substr($fqdn, strlen("_acme-challenge."));
        }
        $labels = explode(".", $fqdn);
        // try longest-suffix-match: a.b.c.example.com -> example.com -> com
        while (count($labels) >= 2) {
            $candidate = implode(".", $labels);
            $response = $this->httpClient->get("zones", ["query" => ["name" => $candidate]]);
            $data = json_decode((string) $response->getBody(), true);
            if (true === ($data["success"] ?? false) && false === empty($data["result"])) {
                return $data["result"][0]["id"];
            }
            array_shift($labels);
        }
        throw new \Exception("Cloudflare: no matching zone for " . $fqdn);
    }
}
