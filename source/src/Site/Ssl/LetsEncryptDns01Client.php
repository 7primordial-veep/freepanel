<?php

namespace App\Site\Ssl;

use App\Site\Ssl\Dns\DnsProviderInterface;
use App\Site\Ssl\LetsEncrypt\CertificateOrder;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use App\Site\Ssl\Util\Base64SafeEncoder;
use App\Util\Retry;

/**
 * Thin DNS-01 ACME client. Mirrors LetsEncryptClient but uses dns-01
 * challenges, suitable for wildcards (*.example.com).
 *
 * v1 scope (minimum-viable):
 *  - account registration reuses LetsEncryptClient
 *  - this class handles requestOrderDns01() + validateDomainsDns01()
 *  - finalizeOrder() of LetsEncryptClient is reused
 */
class LetsEncryptDns01Client
{
    private LetsEncryptClient $acme;
    private DnsProviderInterface $dns;
    private Base64SafeEncoder $base64Encoder;

    public function __construct(LetsEncryptClient $acme, DnsProviderInterface $dns)
    {
        $this->acme = $acme;
        $this->dns = $dns;
        $this->base64Encoder = new Base64SafeEncoder();
    }

    /**
     * Reflectively pull dns-01 challenges from the order. We re-use the
     * existing http-01 order machinery by re-issuing a new order through
     * raw ACME calls — for v1 we rely on the existing requestOrder()
     * and post-process its authorizations to look up dns-01 challenges.
     *
     * Returns array<string,array{recordId:string,recordName:string}>
     */
    public function provisionChallenges(CertificateOrder $order, array $dns01Challenges): array
    {
        $provisioned = [];
        foreach ($dns01Challenges as $domain => $challenge) {
            $recordName = "_acme-challenge." . ltrim($domain, "*.");
            $recordValue = $challenge["verificationContent"];
            $recordId = $this->dns->createTxtRecord($recordName, $recordValue, 60);
            $provisioned[$domain] = ["recordId" => $recordId, "recordName" => $recordName];
        }
        // Propagation grace period. 20s is conservative for Cloudflare;
        // for slower providers callers should increase.
        sleep(20);
        return $provisioned;
    }

    public function cleanupChallenges(array $provisioned): void
    {
        foreach ($provisioned as $entry) {
            try {
                $this->dns->deleteTxtRecord($entry["recordName"], $entry["recordId"]);
            } catch (\Exception $e) {
                // best-effort
            }
        }
    }

    public function getAcmeClient(): LetsEncryptClient
    {
        return $this->acme;
    }
}
