<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\HttpKernel\KernelInterface;
use App\Entity\User;
use App\Repository\UserRepository;
class AutoLoginAuthenticator extends AbstractAuthenticator
{
    private KernelInterface $kernel;
    private UserRepository $userRepository;
    public function __construct(KernelInterface $kernel, UserRepository $userRepository)
    {
        $this->kernel = $kernel;
        $this->userRepository = $userRepository;
    }
    public function supports(Request $request) : ?bool
    {
        $supports = false;
        $path = $request->getPathInfo();
        if (false === empty($path) && "autologin" == substr($path, 1, 10) && false === empty($request->get("token"))) {
            $supports = true;
        }
        return $supports;
    }
    public function authenticate(Request $request) : Passport
    {
        $token = $request->get("token");
        if (!(false === empty($token))) {
            throw new CustomUserMessageAuthenticationException("No token provided");
        }
        if (false === $this->isValidTokenFormat($token)) {
            throw new CustomUserMessageAuthenticationException("Invalid token");
        }
        try {
            $tokenData = $this->getTokenData($token);
            $userName = $tokenData["userName"];
            $expiration = (int) $tokenData["expiration"];
            if (!(false === empty($userName) && false === empty($expiration))) {
                throw new \Exception("Unauthorized");
            }
            $user = $this->userRepository->findOneByUserName($userName);
            if (!(false === is_null($user) && User::STATUS_ACTIVE === $user->getStatus())) {
                throw new \Exception("Unauthorized");
            }
            $now = new \DateTime("now");
            $expirationDate = new \DateTime();
            $expirationDate->setTimestamp($expiration);
            if (!($expirationDate > $now)) {
                throw new \Exception("Token expired");
            }
            return new SelfValidatingPassport(new UserBadge($user->getUsername(), null));
        } catch (\Exception $e) {
            throw new CustomUserMessageAuthenticationException($e->getMessage());
        } finally {
            $tokenFile = $this->getTokenFile($token);
            @unlink($tokenFile);
        }
    }
    private function isValidTokenFormat(string $token) : bool
    {
        return 1 === preg_match("/^[A-Za-z0-9_-]{8,128}\$/", $token);
    }
    private function getTokenFile(string $token) : string
    {
        $tokenFile = sprintf("%s/var/.token_%s", $this->kernel->getProjectDir(), $token);
        return $tokenFile;
    }
    private function getTokenData(string $token) : array
    {
        $tokenFile = $this->getTokenFile($token);
        if (!(true === file_exists($tokenFile))) {
            throw new \Exception("Token file not found");
        }
        $tokenData = file_get_contents($tokenFile);
        if (!(false === empty($tokenData))) {
            throw new \Exception("Token file cannot be empty");
        }
        $tokenData = json_decode($tokenData, true);
        if (!(true === is_array($tokenData) && true === isset($tokenData["userName"]) && true === isset($tokenData["expiration"]))) {
            throw new \Exception("Token file cannot be empty");
        }
        return $tokenData;
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
}