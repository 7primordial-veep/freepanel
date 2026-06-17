<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\HttpKernel\KernelInterface;
use App\Repository\ApiTokenRepository;
use App\Entity\User;
class ApiTokenAuthenticator extends AbstractAuthenticator
{
    private ApiTokenRepository $apiTokenRepository;
    private KernelInterface $kernel;
    public function __construct(ApiTokenRepository $apiTokenRepository, KernelInterface $kernel)
    {
        $this->apiTokenRepository = $apiTokenRepository;
        $this->kernel = $kernel;
    }
    public function supports(Request $request) : ?bool
    {
        return $request->headers->has("Authorization") && 0 === strpos($request->headers->get("Authorization"), "Bearer ");
    }
    public function authenticate(Request $request) : Passport
    {
        $apiToken = $this->getApiToken($request);
        if (null === $apiToken || "" === $apiToken) {
            throw new CustomUserMessageAuthenticationException("No API token provided");
        }
        $apiTokenEntity = $this->apiTokenRepository->findOneBy(["token" => $apiToken]);
        if (true === is_null($apiTokenEntity)) {
            throw new CustomUserMessageAuthenticationException("Unauthorized");
        }
        $passport = new SelfValidatingPassport(new UserBadge($apiToken, function () {
            $user = new User();
            $user->setUserName("api");
            return $user;
        }));
        return $passport;
    }
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName) : ?Response
    {
        return null;
    }
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception) : ?Response
    {
        $data = ["message" => strtr($exception->getMessageKey(), $exception->getMessageData())];
        return new JsonResponse($data, Response::HTTP_UNAUTHORIZED);
    }
    private function getApiToken(Request $request)
    {
        $apiToken = substr($request->headers->get("Authorization"), 7);
        return $apiToken;
    }
}