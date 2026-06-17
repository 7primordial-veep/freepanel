<?php

namespace App\Controller\Admin;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Form;
use App\Controller\Controller;
use Symfony\Contracts\Translation\TranslatorInterface;
use App\Entity\Manager\SiteManager as SiteEntityManager;
use App\Entity\Manager\UserManager as UserEntityManager;
use App\Entity\Manager\TimezoneManager as TimezoneEntityManager;
use App\Entity\User as UserEntity;
use App\Event\EventQueue;
use App\Service\Logger;
class UserController extends Controller
{
    private UserEntityManager $userEntityManager;
    private SiteEntityManager $siteEntityManager;
    private TimezoneEntityManager $timezoneEntityManager;
    public function __construct(UserEntityManager $userEntityManager, SiteEntityManager $siteEntityManager, TimezoneEntityManager $timezoneEntityManager, TranslatorInterface $translator, Logger $logger)
    {
        $this->userEntityManager = $userEntityManager;
        $this->siteEntityManager = $siteEntityManager;
        $this->timezoneEntityManager = $timezoneEntityManager;
        parent::__construct($translator, $logger);
    }
    public function index(Request $request) : Response
    {
        $currentUser = $this->getUser();
        $users = $this->userEntityManager->findAll([], ["userName" => "asc"]);
        $users = array_filter($users, function ($user) use($currentUser) {
            return $currentUser->getId() != $user->getId();
        });
        $response = $this->render("Admin/User/index.html.twig", ["users" => $users]);
        return $response;
    }
    public function new(Request $request) : Response
    {
        $userEntity = $this->userEntityManager->createEntity();
        $defaultTimezone = $this->timezoneEntityManager->findOneByName(UserEntity::DEFAULT_TIMEZONE);
        $sites = $this->siteEntityManager->findAll([], ["domainName" => "ASC"]);
        $userEntity->setTimezone($defaultTimezone);
        $form = $this->createUserForm($userEntity);
        if (true === $request->isMethod("POST")) {
            $form->handleRequest($request);
            if (true === $form->isSubmitted()) {
                $response = $this->handleUserForm($request, $form);
                if (false === is_null($response)) {
                    return $response;
                }
            }
        }
        $response = $this->render("Admin/User/new.html.twig", ["user" => $userEntity, "sites" => $sites, "form" => $form->createView(), "formErrors" => $this->formErrors]);
        return $response;
    }
    private function createUserForm(UserEntity $userEntity) : Form
    {
        $form = $this->createForm("App\\Form\\AdminUserType", $userEntity, ["action" => $this->generateUrl("clp_admin_user_new"), "method" => "POST", "attr" => []]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Add User"]);
        return $form;
    }
    private function handleUserForm(Request $request, Form $form)
    {
        if (true === $form->isValid()) {
            try {
                $currentUser = $this->getUser();
                $session = $request->getSession();
                $userEntity = $form->getData();
                $sites = $userEntity->getSites();
                $domainNames = [];
                if (count($sites) && UserEntity::ROLE_USER == $userEntity->getRole()) {
                    foreach ($sites as $site) {
                        $domainNames[] = $site->getDomainName();
                    }
                }
                sort($domainNames);
                $this->userEntityManager->updateUser($userEntity);
                $eventData = ["userName" => $userEntity->getUserName(), "firstName" => $userEntity->getFirstName(), "lastName" => $userEntity->getLastName(), "email" => $userEntity->getEMail(), "role" => $userEntity->getRole(), "sites" => implode(", ", $domainNames)];
                EventQueue::addEvent(EventQueue::EVENT_ADMIN_USER_ADD, $currentUser, $eventData, $request);
                $session->getFlashBag()->set("success", $this->translator->trans("User has been added."));
                $response = $this->redirect($this->generateUrl("clp_admin_users"));
                return $response;
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        } else {
            $this->formErrors = $this->getErrorMessages($form);
        }
    }
    public function edit(Request $request) : Response
    {
        $id = (int) $request->get("id");
        $userEntity = $this->userEntityManager->findOneById($id);
        if (false === is_null($userEntity)) {
            $sites = $this->siteEntityManager->findAll([], ["domainName" => "ASC"]);
            $form = $this->createUserEditForm($userEntity);
            if (true === $request->isMethod("POST")) {
                $form->handleRequest($request);
                if (true === $form->isSubmitted()) {
                    $response = $this->handleUserEditForm($request, $form);
                    if (false === is_null($response)) {
                        return $response;
                    }
                }
            }
            $response = $this->render("Admin/User/edit.html.twig", ["user" => $userEntity, "sites" => $sites, "form" => $form->createView(), "formErrors" => $this->formErrors]);
        } else {
            $response = $this->redirect($this->generateUrl("clp_admin_users"));
        }
        return $response;
    }
    private function createUserEditForm(UserEntity $userEntity) : Form
    {
        $form = $this->createForm("App\\Form\\AdminUserEditType", $userEntity, ["action" => $this->generateUrl("clp_admin_user_edit", ["id" => $userEntity->getId()]), "method" => "POST", "attr" => []]);
        $form->add("submit", SubmitType::class, ["attr" => ["class" => "btn btn-blue btn-lg"], "label" => "Save"]);
        return $form;
    }
    private function handleUserEditForm(Request $request, Form $form)
    {
        if (true === $form->isValid()) {
            try {
                $currentUser = $this->getUser();
                $session = $request->getSession();
                $userEntity = $form->getData();
                $plainPassword = $userEntity->getPlainPassword();
                $sites = $userEntity->getSites();
                $domainNames = [];
                if (count($sites) && UserEntity::ROLE_USER == $userEntity->getRole()) {
                    foreach ($sites as $site) {
                        $domainNames[] = $site->getDomainName();
                    }
                }
                sort($domainNames);
                if (false === empty($plainPassword)) {
                    $this->userEntityManager->updateUser($userEntity, true, true);
                } else {
                    $this->userEntityManager->updateUser($userEntity);
                }
                $eventData = ["userName" => $userEntity->getUserName(), "firstName" => $userEntity->getFirstName(), "lastName" => $userEntity->getLastName(), "email" => $userEntity->getEMail(), "role" => $userEntity->getRole(), "status" => (int) $userEntity->getStatus(), "sites" => implode(", ", $domainNames)];
                EventQueue::addEvent(EventQueue::EVENT_ADMIN_USER_UPDATE, $currentUser, $eventData, $request);
                $session->getFlashBag()->set("success", $this->translator->trans("User has been updated."));
                $response = $this->redirect($this->generateUrl("clp_admin_user_edit", ["id" => $userEntity->getId()]));
                return $response;
            } catch (\Exception $e) {
                $this->logger->exception($e);
                $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
            }
        } else {
            $this->formErrors = $this->getErrorMessages($form);
        }
    }
    public function delete(Request $request) : Response
    {
        $currentUser = $this->getUser();
        $id = (int) $request->get("id");
        $this->checkCsrfToken($request, "delete-user");
        if ($id != $currentUser->getId()) {
            $userEntity = $this->userEntityManager->findOneById($id);
            if (false === is_null($userEntity)) {
                $session = $request->getSession();
                $eventData = ["userName" => $userEntity->getUserName(), "firstName" => $userEntity->getFirstName(), "lastName" => $userEntity->getLastName(), "email" => $userEntity->getEMail(), "role" => $userEntity->getRole()];
                EventQueue::addEvent(EventQueue::EVENT_ADMIN_USER_DELETE, $currentUser, $eventData, $request);
                $this->userEntityManager->deleteUser($userEntity);
                $session->getFlashBag()->set("success", $this->translator->trans("User has been deleted."));
            }
        }
        $response = $this->redirect($this->generateUrl("clp_admin_users"));
        return $response;
    }
}