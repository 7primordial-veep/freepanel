<?php

namespace App\Site\VarnishCache;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use App\Util\Retry;
class Client
{
    const HTTP_CLIENT_TIMEOUT = 10;
    private ?HttpClient $httpClient = null;
    private ?string $server = null;
    public function setServer(?string $server) : void
    {
        $this->server = $server;
    }
    public function getServer() : ?string
    {
        return $this->server;
    }
    public function purgeUrl(string $url) : void
    {
        $server = $this->getServer();
        $parsedUrl = parse_url($url);
        if (!(true === isset($parsedUrl["host"]))) {
            throw new \Exception(sprintf("Not a valid url: %s", $url));
        }
        $host = $parsedUrl["host"];
        $requestUrl = $server;
        if (true === isset($parsedUrl["path"])) {
            $path = $parsedUrl["path"];
            $requestUrl = sprintf("%s/%s", $requestUrl, "/" == $path ? '' : ltrim($path, "/"));
        }
        $queryString = parse_url($url, PHP_URL_QUERY);
        if (false === empty($queryString)) {
            parse_str($queryString, $queryParams);
            if (false === empty($queryParams)) {
                $queryString = http_build_query($queryParams);
                $requestUrl = sprintf("%s?%s", $requestUrl, $queryString);
            }
        }
        $headers = ["Host" => $host];
        $this->sendPurgeRequest($requestUrl, $headers);
    }
    public function purgeHost(string $host) : void
    {
        $server = $this->getServer();
        $headers = ["Host" => $host];
        $this->sendPurgeRequest($server, $headers);
    }
    public function purgeTag(string $tag) : void
    {
        $this->purgeTags([$tag]);
    }
    public function purgeTags(array $tags) : void
    {
        $server = $this->getServer();
        $headers = ["X-Cache-Tags" => implode(",", $tags)];
        $this->sendPurgeRequest($server, $headers);
    }
    private function sendPurgeRequest(string $requestUrl, array $headers) : void
    {
        $request = new Request("PURGE", $requestUrl, $headers);
        $httpClient = $this->getHttpClient();
        $httpClient->send($request);
    }
    private function getHttpClient() : HttpClient
    {
        if (true === is_null($this->httpClient)) {
            $config = ["timeout" => self::HTTP_CLIENT_TIMEOUT, "verify" => false];
            $this->httpClient = new HttpClient($config);
        }
        return $this->httpClient;
    }

}