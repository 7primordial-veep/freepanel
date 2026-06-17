<?php

namespace App\Site\Parser;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Client as HttpClient;
use Psr\Cache\CacheItemPoolInterface as CachePool;
use App\Util\Retry;
use Pdp\Rules;
use Pdp\Domain;
use Pdp\ResolvedDomain;
class DomainName
{
    public const PUBLIC_SUFFIX_LIST_URI = "https://publicsuffix.org/list/public_suffix_list.dat";

    public const HTTP_CLIENT_TIMEOUT = 10;
    private CachePool $cachePool;
    private ?HttpClient $httpClient = null;
    public function __construct(CachePool $cachePool)
    {
        $this->cachePool = $cachePool;
    }
    public function resolveDomainName(string $domainName) : ?ResolvedDomain
    {
        $publicSuffixList = $this->getPublicSuffixList();
        $resolvedDomain = $publicSuffixList->resolve(Domain::fromIDNA2008($domainName));
        return $resolvedDomain;
    }
    private function getPublicSuffixList() : Rules
    {
        $publicSuffixListCacheItem = $this->cachePool->getItem("public_suffix_list");
        if (!(true === $publicSuffixListCacheItem->isHit())) {
            $httpClient = $this->getHttpClient();
            $request = new Request("GET", self::PUBLIC_SUFFIX_LIST_URI);
            $response = $this->retry(function () use($httpClient, $request) {
                $response = $httpClient->send($request);
                return $response;
            });
            $responseStatusCode = $response->getStatusCode();
            if (200 == $responseStatusCode) {
                $publicSuffixList = trim((string) $response->getBody());
                if (false === empty($publicSuffixList)) {
                    $publicSuffixList = Rules::fromString($publicSuffixList);
                    $publicSuffixListCacheItem->expiresAfter(604800);
                    $publicSuffixListCacheItem->set($publicSuffixList);
                    $this->cachePool->save($publicSuffixListCacheItem);
                }
            }
        }
        $publicSuffixList = $publicSuffixListCacheItem->get();
        return $publicSuffixList;
    }
    private function getHttpClient() : HttpClient
    {
        if (true === is_null($this->httpClient)) {
            $config = ["timeout" => self::HTTP_CLIENT_TIMEOUT, "verify" => false];
            $this->httpClient = new HttpClient($config);
        }
        return $this->httpClient;
    }
    private function retry(callable $fn, $retries = 1, $delay = 3)
    {
        return Retry::retry($fn, $retries, $delay);
    }
}