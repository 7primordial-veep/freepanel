<?php

namespace App\Backup\Dropbox;

use GuzzleHttp\Client as HttpClient;
use App\Util\Retry;
class AccessCodeValidator
{
    const HTTP_CLIENT_TIMEOUT = 10;
    const ENDPOINT = "https://dropbox-auth.cloudpanel.io/";
    private ?HttpClient $httpClient = null;
    private ?string $token = null;
    private ?string $refreshToken = null;
    public function isValid(string $accessCode) : bool
    {
        $isValid = false;
        $httpClient = $this->getHttpClient();
        $formData = ["code" => $accessCode];
        $response = $this->retry(function () use($httpClient, $formData) {
            $response = $httpClient->request("POST", self::ENDPOINT, ["form_params" => $formData]);
            return $response;
        });
        if (200 == $response->getStatusCode()) {
            $responseData = (string) $response->getBody();
            $responseDataDecoded = json_decode($responseData, true);
            if (true === isset($responseDataDecoded["access_token"]) && true === isset($responseDataDecoded["refresh_token"])) {
                $token = $responseDataDecoded["access_token"];
                $refreshToken = $responseDataDecoded["refresh_token"];
                $this->setToken($token);
                $this->setRefreshToken($refreshToken);
                $isValid = true;
            } elseif (true === isset($responseDataDecoded["errorMessage"])) {
                throw new \Exception($responseDataDecoded["errorMessage"]);
            }
        }
        return $isValid;
    }
    public function setToken(string $token) : void
    {
        $this->token = $token;
    }
    public function getToken() : ?string
    {
        return $this->token;
    }
    public function setRefreshToken(string $refreshToken) : void
    {
        $this->refreshToken = $refreshToken;
    }
    public function getRefreshToken() : ?string
    {
        return $this->refreshToken;
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