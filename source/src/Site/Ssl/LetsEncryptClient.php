<?php

namespace App\Site\Ssl;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use App\Site\Ssl\Util\Base64SafeEncoder;
use App\Site\Ssl\LetsEncrypt\CertificateOrder;
use App\Site\Ssl\PrivateKey;
use App\Site\Ssl\KeyParser;
use App\Site\Ssl\DataSigner;
use App\Site\Ssl\Certificate;
use App\Util\Retry;
class LetsEncryptClient
{
    const HTTP_CLIENT_TIMEOUT = 15;
    const HTTP_CLIENT_USER_AGENT = "CloudPanel";
    const ACTION_NEW_ACCOUNT = "newAccount";
    const ACTION_NEW_NONCE = "newNonce";
    const ACTION_NEW_ORDER = "newOrder";
    private $endpoints = ["production" => ["newAccount" => "https://acme-v02.api.letsencrypt.org/acme/new-acct", "newNonce" => "https://acme-v02.api.letsencrypt.org/acme/new-nonce", "newAuthz" => "https://acme-v02.api.letsencrypt.org/acme/new-authz", "newOrder" => "https://acme-v02.api.letsencrypt.org/acme/new-order", "revokeCert" => "https://acme-v02.api.letsencrypt.org/acme/revoke-cert", "keyChange" => "https://acme-v02.api.letsencrypt.org/acme/key-change"], "staging" => ["newAccount" => "https://acme-staging-v02.api.letsencrypt.org/acme/new-acct", "newNonce" => "https://acme-staging-v02.api.letsencrypt.org/acme/new-nonce", "newAuthz" => "https://acme-staging-v02.api.letsencrypt.org/acme/new-authz", "newOrder" => "https://acme-staging-v02.api.letsencrypt.org/acme/new-order", "revokeCert" => "https://acme-staging-v02.api.letsencrypt.org/acme/revoke-cert", "keyChange" => "https://acme-staging-v02.api.letsencrypt.org/acme/key-change"]];
    private ?string $accountEndpoint = null;
    private ?HttpClient $httpClient = null;
    private PrivateKey $privateKey;
    private Base64SafeEncoder $base64Encoder;
    private KeyParser $keyParser;
    private DataSigner $dataSigner;
    private bool $dryRun = false;
    public function __construct(PrivateKey $privateKey)
    {
        $this->privateKey = $privateKey;
        $this->httpClient = $this->getHttpClient();
        $this->base64Encoder = new Base64SafeEncoder();
        $this->keyParser = new KeyParser();
        $this->dataSigner = new DataSigner();
    }
    public function setDryRun(bool $flag) : void
    {
        $this->dryRun = $flag;
    }
    private function getHttpClient() : ?HttpClient
    {
        if (true === is_null($this->httpClient)) {
            $config = ["timeout" => self::HTTP_CLIENT_TIMEOUT, "verify" => true, "headers" => ["User-Agent" => self::HTTP_CLIENT_USER_AGENT]];
            $this->httpClient = new HttpClient($config);
        }
        return $this->httpClient;
    }
    public function registerAccount()
    {
        try {
            $endpoint = $this->getEndpoint(self::ACTION_NEW_ACCOUNT);
            $signedData = $this->signData($endpoint, ["termsOfServiceAgreed" => true]);
            $request = $this->createRequest("POST", $endpoint, $signedData);
            $response = $this->sendRequest($request);
            $accountEndpoint = null;
            $status = null;
            if (true === $this->isResponseSuccessful($response)) {
                $responseData = $this->getJsonResponse($response);
                $status = $responseData["status"] ?? null;
                if (!("valid" == $status)) {
                    throw new \Exception(sprintf("Account status is not valid, status: %s", $status));
                }
                $accountEndpoint = $this->getAccountEndpoint();
                $response = $this->retry(function () use($accountEndpoint) {
                    $signedKidData = $this->signKidData($accountEndpoint, $accountEndpoint);
                    $request = $this->createRequest("POST", $accountEndpoint, $signedKidData);
                    $response = $this->httpClient->send($request);
                    return $response;
                });
                if (true === $this->isResponseSuccessful($response)) {
                    $responseData = $this->getJsonResponse($response);
                    $status = $responseData["status"] ?? null;
                    if ("valid" != $status) {
                        throw new \Exception(sprintf("Account status is not valid, status: %s", $status));
                    }
                }
                $responseData = ["account" => $accountEndpoint, "status" => $status];
                return $responseData;
            } else {
                $this->throwActionException("RegisterAccount response was not successful", $response);
            }
        } catch (\Exception $e) {
            $exceptionMessage = sprintf("An error occurred while registering an account, error message: %s", $e->getMessage());
            throw new \Exception($exceptionMessage);
        }
    }
    private function getEndpoint($action)
    {
        $endpoints = false === $this->dryRun ? $this->endpoints["production"] : $this->endpoints["staging"];
        $requestUrl = $endpoints[$action] ?? '';
        return $requestUrl;
    }
    private function createRequest($method, $endpoint, $data = null)
    {
        $request = new Request($method, $endpoint);
        $request = $request->withHeader("Accept", "application/json,application/jose+json");
        if ("POST" === $method && true === is_array($data)) {
            $request = $request->withHeader("Content-Type", "application/jose+json");
            $request = $request->withBody(\GuzzleHttp\Psr7\Utils::streamFor(json_encode($data)));
        }
        return $request;
    }
    private function isResponseSuccessful(Response $response)
    {
        $isResponseSuccessful = false;
        $responseStatusCode = $response->getStatusCode();
        $successfulStatusCodes = [200, 201];
        if (true === in_array($responseStatusCode, $successfulStatusCodes)) {
            $isResponseSuccessful = true;
        }
        return $isResponseSuccessful;
    }
    private function throwActionException($message, Response $response)
    {
        $responseStatusCode = $response->getStatusCode();
        $exceptionMessage = sprintf("status code: %s, error message: %s", $responseStatusCode, $message);
        throw new \Exception($exceptionMessage);
    }
    private function getNonce()
    {
        $endpoint = $this->getEndpoint(self::ACTION_NEW_NONCE);
        $request = $this->createRequest("HEAD", $endpoint);
        $response = $this->sendRequest($request);
        if (!(true === $this->isResponseSuccessful($response) && true === $response->hasHeader("Replay-Nonce") && false === empty($response->getHeaderLine("Replay-Nonce")))) {
            throw new \Exception("Unable to retrieve nonce");
        }
        $replayNonce = $response->getHeaderLine("Replay-Nonce");
        return $replayNonce;
    }
    private function signData($endpoint, $data)
    {
        $jwk = $this->getJwk();
        $nonce = $this->getNonce();
        $protected = ["alg" => "RS256", "jwk" => $jwk, "nonce" => $nonce, "url" => $endpoint];
        $protected = $this->base64Encoder->encode(json_encode($protected, JSON_UNESCAPED_SLASHES));
        $payload = $this->base64Encoder->encode(json_encode($data, JSON_UNESCAPED_SLASHES));
        $signature = $this->base64Encoder->encode($this->dataSigner->signData($protected . "." . $payload, $this->privateKey));
        $signedData = ["protected" => $protected, "payload" => $payload, "signature" => $signature];
        return $signedData;
    }
    private function getJwk()
    {
        $parsedKey = $this->keyParser->parse($this->privateKey);
        $jwk = ["e" => $this->base64Encoder->encode($parsedKey->getDetail("e")), "kty" => "RSA", "n" => $this->base64Encoder->encode($parsedKey->getDetail("n"))];
        return $jwk;
    }
    private function getJwkThumbprint()
    {
        $jwk = $this->getJwk();
        $jwkThumbprint = hash("sha256", json_encode($jwk), true);
        return $jwkThumbprint;
    }
    private function signKidData($endpoint, $account, array $data = [])
    {
        $nonce = $this->getNonce();
        $protected = ["alg" => "RS256", "kid" => $account, "nonce" => $nonce, "url" => $endpoint];
        if (true === empty($data)) {
            $payload = $this->base64Encoder->encode("{}");
        } else {
            $payload = $this->base64Encoder->encode(json_encode($data, JSON_UNESCAPED_SLASHES));
        }
        $protected = $this->base64Encoder->encode(json_encode($protected, JSON_UNESCAPED_SLASHES));
        $signature = $this->base64Encoder->encode($this->dataSigner->signData($protected . "." . $payload, $this->privateKey, OPENSSL_ALGO_SHA256));
        $signedData = ["protected" => $protected, "payload" => $payload, "signature" => $signature];
        return $signedData;
    }
    public function getAccountEndpoint()
    {
        if (true === is_null($this->accountEndpoint)) {
            $endpoint = $this->getEndpoint(self::ACTION_NEW_ACCOUNT);
            $response = $this->retry(function () use($endpoint) {
                $signedData = $this->signData($endpoint, ["onlyReturnExisting" => true]);
                $request = $this->createRequest("POST", $endpoint, $signedData);
                $response = $this->httpClient->send($request);
                return $response;
            });
            if (!(true === $this->isResponseSuccessful($response) && false === empty($response->getHeaderLine("Location")))) {
                throw new \Exception("Unable to retrieve account endpoint");
            }
            $this->accountEndpoint = $response->getHeaderLine("Location");
        }
        return $this->accountEndpoint;
    }
    public function requestOrder(array $domains)
    {
        try {
            $data = ["identifiers" => array_map(function ($domain) {
                return ["type" => "dns", "value" => $domain];
            }, array_values($domains))];
            $accountEndpoint = $this->getAccountEndpoint();
            $newOrderEndpoint = $this->getEndpoint(self::ACTION_NEW_ORDER);
            $response = $this->retry(function () use($accountEndpoint, $newOrderEndpoint, $data) {
                $signedKidData = $this->signKidData($newOrderEndpoint, $accountEndpoint, $data);
                $request = $this->createRequest("POST", $newOrderEndpoint, $signedKidData);
                $response = $this->httpClient->send($request);
                return $response;
            });
            $orderEndpoint = null;
            $authorizationEndpoints = [];
            if (!(true === $this->isResponseSuccessful($response))) {
                throw new \Exception("new account request was not successful");
            }
            $responseData = $this->getJsonResponse($response);
            if (true === isset($responseData["authorizations"]) && true === is_array($responseData["authorizations"])) {
                $authorizationEndpoints = $responseData["authorizations"];
                $orderEndpoint = $response->getHeaderLine("Location");
            }
            if (!(false === empty($authorizationEndpoints))) {
                throw new \Exception("no authorizations returned");
            }
            $authorizationsChallenges = [];
            foreach ($authorizationEndpoints as $authorizationEndpoint) {
                $request = $this->createRequest("GET", $authorizationEndpoint);
                $authorizationResponse = $this->sendRequest($request);
                if (!(true === $this->isResponseSuccessful($authorizationResponse))) {
                    throw new \Exception(sprintf("authorization request failed to endpoint: %s ", $authorizationEndpoint));
                }
                $authorizationsResponseData = $this->getJsonResponse($authorizationResponse);
                $domain = $authorizationsResponseData["identifier"]["value"] ?? null;
                if (!(false === empty($domain))) {
                    continue;
                }
                $challenges = $authorizationsResponseData["challenges"] ? (array) $authorizationsResponseData["challenges"] : [];
                $challenge = [];
                if (count($challenges)) {
                    foreach ($challenges as $domainChallenge) {
                        $challengeType = $domainChallenge["type"] ?? '';
                        if (!($challengeType == "http-01")) {
                            continue;
                        }
                        $status = $domainChallenge["status"] ?? '';
                        $url = $domainChallenge["url"] ?? '';
                        $token = $domainChallenge["token"] ?? '';
                        $verificationUrl = sprintf("http://%s/.well-known/acme-challenge/%s", $domain, $token);
                        $verificationContent = sprintf("%s.%s", $token, $this->base64Encoder->encode($this->getJwkThumbprint()));
                        $challenge = ["status" => $status, "url" => $url, "token" => $token, "verificationUrl" => $verificationUrl, "verificationContent" => $verificationContent];
                        break;
                    }
                }
                if (!(false === empty($challenge))) {
                    throw new \Exception(sprintf("No challenges returned for domain: %s", $domain));
                }
                $authorizationsChallenges[$domain] = $challenge;
            }
            $certificateOrder = new CertificateOrder($orderEndpoint, $authorizationsChallenges);
            return $certificateOrder;
        } catch (\Exception $e) {
            $exceptionMessage = sprintf("An error occurred while requesting an order, error message: %s", $e->getMessage());
            throw new \Exception($exceptionMessage);
        }
    }
    public function validateDomains(CertificateOrder $certificateOrder)
    {
        $authorizationsChallenges = $certificateOrder->getAuthorizationsChallenges();
        $authorizationChallengeErrors = [];
        foreach ($authorizationsChallenges as $domain => $authorizationsChallenge) {
            try {
                $this->validateDomain($authorizationsChallenge);
            } catch (\Exception $e) {
                $errorMessage = sprintf("Domain could not be validated, error message: %s", $e->getMessage());
                $authorizationChallengeErrors[$domain] = $errorMessage;
            }
        }
        return $authorizationChallengeErrors;
    }
    private function validateDomain(array $challenge, $timeout = 90)
    {
        $endTime = time() + $timeout;
        $challengeUrl = $challenge["url"];
        $accountEndpoint = $this->getAccountEndpoint();
        $request = $this->createRequest("GET", $challengeUrl);
        $response = $this->sendRequest($request);
        $status = null;
        if (!(true === $this->isResponseSuccessful($response))) {
            throw new \Exception(sprintf("request to challenge url \"%s\" failed", $challengeUrl));
        }
        $responseData = $this->getJsonResponse($response);
        $status = $responseData["status"] ?? null;
        if (true === is_null($status) || "pending" == $status) {
            $response = $this->retry(function () use($challengeUrl, $accountEndpoint) {
                $signedKidData = $this->signKidData($challengeUrl, $accountEndpoint);
                $request = $this->createRequest("POST", $challengeUrl, $signedKidData);
                $response = $this->httpClient->send($request);
                return $response;
            });
            if (true === $this->isResponseSuccessful($response)) {
                $responseData = $this->getJsonResponse($response);
                $status = $responseData["status"] ?? null;
            }
        }
        if (time() <= $endTime && (true === is_null($status) || "pending" == $status)) {
            sleep(1);
            $request = $this->createRequest("GET", $challengeUrl);
            $response = $this->sendRequest($request);
            if (true === $this->isResponseSuccessful($response)) {
                $responseData = $this->getJsonResponse($response);
                $status = $responseData["status"] ?? null;
            }
        }
        if (true === is_null($status) || "pending" == $status) {
            throw new \Exception(sprintf("validation timed out after %s seconds", $timeout));
        }
        if (true === is_null($status) || "valid" != $status) {
            $errorType = $responseData["error"]["type"] ?? '';
            $errorDetail = $responseData["error"]["detail"] ?? '';
            throw new \Exception(sprintf("error type: %s, error detail: %s", $errorType, $errorDetail));
        }
    }
    public function finalizeOrder(CertificateOrder $order, PrivateKey $privateKey, $csr, $timeout = 90)
    {
        try {
            $endTime = time() + $timeout;
            $orderEndpoint = $order->getOrderEndpoint();
            $accountEndpoint = $this->getAccountEndpoint();
            $response = $this->retry(function () use($orderEndpoint, $accountEndpoint) {
                $signedKidData = $this->signKidData($orderEndpoint, $accountEndpoint);
                $request = $this->createRequest("GET", $orderEndpoint, $signedKidData);
                $response = $this->httpClient->send($request);
                return $response;
            });
            $status = null;
            $certificateUrl = null;
            if (!(true === $this->isResponseSuccessful($response))) {
                throw new \Exception("no response returned");
            }
            $responseData = $this->getJsonResponse($response);
            $status = $responseData["status"] ?? null;
            $finalizeUrl = $responseData["finalize"];
            if (true === in_array($status, ["pending", "ready"]) && false === empty($finalizeUrl)) {
                $humanText = ["-----BEGIN CERTIFICATE REQUEST-----", "-----END CERTIFICATE REQUEST-----"];
                $csrContent = trim(str_replace($humanText, '', $csr));
                $csrContent = trim($this->base64Encoder->encode(base64_decode($csrContent)));
                $response = $this->retry(function () use($finalizeUrl, $accountEndpoint, $csrContent) {
                    $signedKidData = $this->signKidData($finalizeUrl, $accountEndpoint, ["csr" => $csrContent]);
                    $request = $this->createRequest("POST", $finalizeUrl, $signedKidData);
                    $response = $this->httpClient->send($request);
                    return $response;
                });
                if (true === $this->isResponseSuccessful($response)) {
                    $responseData = $this->getJsonResponse($response);
                    $status = $responseData["status"] ?? null;
                    $certificateUrl = $responseData["certificate"] ?? null;
                    if (time() <= $endTime && (false === isset($status) || true === in_array($status, ["pending", "processing", "ready"]))) {
                        sleep(1);
                        $response = $this->retry(function () use($orderEndpoint, $accountEndpoint) {
                            $signedKidData = $this->signKidData($orderEndpoint, $accountEndpoint);
                            $request = $this->createRequest("GET", $orderEndpoint, $signedKidData);
                            $response = $this->httpClient->send($request);
                            return $response;
                        });
                        if (true === $this->isResponseSuccessful($response)) {
                            $responseData = $this->getJsonResponse($response);
                            $status = $responseData["status"] ?? null;
                            $certificateUrl = $responseData["certificate"] ?? null;
                        }
                    }
                }
                if ("valid" !== $status) {
                    throw new \Exception("order has not been validated");
                }
                $request = $this->createRequest("GET", $certificateUrl);
                $response = $this->sendRequest($request);
                if (!(true === $this->isResponseSuccessful($response))) {
                    throw new \Exception("not able to get the certificate");
                }
                $fullCertificate = (string) $response->getBody();
                $fullCertificate = explode("\n\n", $fullCertificate);
                $mainCertificate = array_shift($fullCertificate);
                $certificateChain = implode(PHP_EOL, $fullCertificate);
                $certificate = new Certificate();
                $certificate->setCsr(trim($csr));
                $certificate->setPrivateKey(trim($privateKey->getPEM()));
                $certificate->setCertificate(trim($mainCertificate));
                if (false === empty($certificateChain)) {
                    $certificate->setCertificateChain(trim($certificateChain));
                }
                return $certificate;
            }
        } catch (\Exception $e) {
            $exceptionMessage = sprintf("An error occurred while finalizing the order, error message: %s", $e->getMessage());
            throw new \Exception($exceptionMessage);
        }
    }
    private function sendRequest(Request $request)
    {
        $response = $this->retry(function () use($request) {
            $response = $this->httpClient->send($request);
            return $response;
        });
        return $response;
    }
    private function getJsonResponse(Response $response)
    {
        $jsonResponse = json_decode((string) $response->getBody(), true);
        return $jsonResponse;
    }
    private function retry(callable $fn, $retries = 2, $delay = 3)
    {
        return Retry::retry($fn, $retries, $delay);
    }
}