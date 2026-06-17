<?php

namespace App\Security;

use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Doctrine\ORM\EntityManagerInterface;
use App\Repository\UserRepository;
use App\Entity\User;
class UserProvider implements UserProviderInterface, PasswordUpgraderInterface
{
    private UserRepository $userRepository;
    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->userRepository = $entityManager->getRepository(User::class);
    }
    public function loadUserByIdentifier(string $identifier) : UserInterface
    {
        $user = $this->userRepository->findOneByUserName($identifier);
        if (true === is_null($user) || false === is_null($user) && $user->getStatus() == User::STATUS_NOT_ACTIVE) {
            throw new UserNotFoundException();
        }
        $user->addRole($user->getRole());
        return $user;
    }
    public function refreshUser(UserInterface $user)
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf("Invalid user class \"%s\".", get_class($user)));
        }
        $userId = $user->getId();
        $user = $this->userRepository->findOneById($userId);
        if (true === is_null($user) || false === is_null($user) && $user->getStatus() == User::STATUS_NOT_ACTIVE) {
            throw new UserNotFoundException();
        }
        $user->addRole($user->getRole());
        return $user;
    }
    public function supportsClass(string $class) : bool
    {
        return User::class === $class || is_subclass_of($class, User::class);
    }
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword) : void
    {
    }
    public function loadUserByUsername(string $username)
    {
    }
}