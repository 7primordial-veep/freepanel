<?php

namespace App\Backup\Dropbox;

use GuzzleHttp\Client as HttpClient;
use App\Util\Retry;
class Client
{
    const HTTP_CLIENT_TIMEOUT = 10;
    const ENDPOINT = "https://dropbox-auth.cloudpanel.io/";
    private ?HttpClient $httpClient = null;
    public function getAccessToken(string $refreshToken) : ?string
    {
        $httpClient = $this->getHttpClient();
        $formData = ["refreshToken" => $refreshToken];
        $response = $this->retry(function () use($httpClient, $formData) {
            $response = $httpClient->request("POST", self::ENDPOINT, ["form_params" => $formData]);
            return $response;
        });
        $accessToken = null;
        if (200 == $response->getStatusCode()) {
            $responseData = (string) $response->getBody();
            $responseDataDecoded = json_decode($responseData, true);
            if (true === isset($responseDataDecoded["access_token"])) {
                $accessToken = $responseDataDecoded["access_token"];
            } elseif (true === isset($responseDataDecoded["errorMessage"])) {
                throw new \Exception($responseDataDecoded["errorMessage"]);
            }
        }
        return $accessToken;
    }
    private function getHttpClient() : HttpClient
    {
        if (true === is_null($this->httpClient)) {
            $config = ["timeout" => self::HTTP_CLIENT_TIMEOUT, "verify" => true];
            $this->httpClient = new HttpClient($config);
        }
        return $this->httpClient;
    }
    protected function retry(callable $fn, $retries = 1, $delay = 3)
    {
        return Retry::retry($fn, $retries, $delay);
    }
}