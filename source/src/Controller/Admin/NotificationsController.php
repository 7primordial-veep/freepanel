<?php

namespace App\Controller\Admin;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Controller\Controller;
use App\Entity\Manager\NotificationManager;
class NotificationsController extends Controller
{
    public function index(Request $request, NotificationManager $notificationManager) : Response
    {
        $user = $this->getUser();
        $notifications = $notificationManager->findAll([], ["createdAt" => "desc"], 10);
        $response = $this->render("Admin/Notification/index.html.twig", ["user" => $user, "notifications" => $notifications]);
        return $response;
    }
    public function markAsRead(Request $request, NotificationManager $notificationManager) : Response
    {
        $this->checkCsrfToken($request, "notification-mark-as-read");
        $id = (int) $request->get("id");
        $notification = $notificationManager->findOneById($id);
        try {
            $session = $request->getSession();
            if (false === is_null($notification)) {
                $notification->setIsRead(true);
                $notificationManager->updateEntity($notification);
            }
        } catch (\Exception $e) {
            $this->logger->exception($e);
            $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
        }
        $response = $this->redirect($this->generateUrl("clp_admin_notifications"));
        return $response;
    }
    public function markAsUnread(Request $request, NotificationManager $notificationManager) : Response
    {
        $this->checkCsrfToken($request, "notification-mark-as-unread");
        $id = (int) $request->get("id");
        $notification = $notificationManager->findOneById($id);
        try {
            $session = $request->getSession();
            if (false === is_null($notification)) {
                $notification->setIsRead(false);
                $notificationManager->updateEntity($notification);
            }
        } catch (\Exception $e) {
            $this->logger->exception($e);
            $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
        }
        $response = $this->redirect($this->generateUrl("clp_admin_notifications"));
        return $response;
    }
    public function unreadNotifications(Request $request, NotificationManager $notificationManager) : Response
    {
        $numberOfUnreadNotifications = $notificationManager->getNumberOfUnreadNotifications();
        $response = new Response();
        $response->setContent($numberOfUnreadNotifications);
        return $response;
    }
    public function delete(Request $request, NotificationManager $notificationManager) : Response
    {
        $this->checkCsrfToken($request, "notification-delete");
        $id = (int) $request->get("id");
        $notification = $notificationManager->findOneById($id);
        try {
            $session = $request->getSession();
            if (false === is_null($notification)) {
                $notificationManager->deleteEntity($notification);
                $session->getFlashBag()->set("success", $this->translator->trans("Notification has been deleted."));
            }
        } catch (\Exception $e) {
            $this->logger->exception($e);
            $session->getFlashBag()->set("danger", $this->translator->trans("An error has occurred, error message: %errorMessage%", ["%errorMessage%" => $e->getMessage()]));
        }
        $response = $this->redirect($this->generateUrl("clp_admin_notifications"));
        return $response;
    }
}