<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\ConstraintViolationList;
use App\Entity\Manager\UserManager;
use App\Entity\Manager\TimezoneManager;
use App\Api\Error as ApiError;
use App\Entity\User;
class ApiController extends Controller
{
    public function test(Request $request) : Response
    {
        return $this->json([]);
    }
    public function createUser(Request $request, UserManager $userManager, TimezoneManager $timezoneManager) : Response
    {
        try {
            $payload = $request->toArray();
            $role = $payload["role"] ?? null;
            $userName = $payload["userName"] ?? null;
            $firstName = $payload["firstName"] ?? null;
            $lastName = $payload["lastName"] ?? null;
            $email = $payload["email"] ?? null;
            $password = $payload["password"] ?? null;
            $status = isset($payload["status"]) && 1 == $payload["status"] ? User::STATUS_ACTIVE : User::STATUS_NOT_ACTIVE;
            $timezone = $payload["timezone"] ?? null;
            $user = $userManager->createEntity();
            $user->setRole($role);
            $user->setUserName($userName);
            $user->setFirstName($firstName);
            $user->setLastName($lastName);
            $user->setEmail($email);
            $user->setPassword($password);
            $user->setPlainPassword($password);
            $user->setStatus($status);
            $timezoneEntity = $timezoneManager->findOneByName($timezone);
            if (false === is_null($timezoneEntity)) {
                $user->setTimezone($timezoneEntity);
            }
            $validator = $this->get("validator");
            $metadata = $validator->getMetadataFor($user);
            $metadata->addPropertyConstraint("password", new Assert\Length(["min" => User::PASSWORD_MIN_LENGTH, "max" => User::PASSWORD_MAX_LENGTH]));
            $violations = $validator->validate($user);
            if (!(count($violations) > 0)) {
                $userManager->updateUser($user);
                $data = ["user" => ["id" => $user->getId(), "userName" => $user->getUserName(), "firstName" => $user->getFirstName(), "lastName" => $user->getLastName(), "email" => $user->getEmail(), "status" => (int) $user->getStatus(), "timezone" => (string) $user->getTimezone()]];
                return $this->json($data);
            }
            return $this->renderViolations($violations);
        } catch (\Exception $exception) {
            $apiError = new ApiError();
            $apiError->setMessage($exception->getMessage());
            return $this->returnErrors([$apiError]);
        }
    }
    private function returnErrors(array $apiErrors, $status = Response::HTTP_BAD_REQUEST) : Response
    {
        $errors = [];
        foreach ($apiErrors as $apiError) {
            $error = ["message" => $apiError->getMessage()];
            $data = $apiError->getData();
            foreach ($data as $key => $value) {
                $error[$key] = $value;
            }
            $errors[] = $error;
        }
        return $this->json(["errors" => $errors], $status);
    }
    private function renderViolations(ConstraintViolationList $violations) : Response
    {
        $apiErrors = [];
        foreach ($violations as $violation) {
            $apiError = new ApiError();
            $apiError->setMessage($violation->getMessage());
            $apiError->setData("property", $violation->getPropertyPath());
            $apiErrors[] = $apiError;
        }
        return $this->returnErrors($apiErrors);
    }
}