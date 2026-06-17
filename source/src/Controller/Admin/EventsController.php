<?php

namespace App\Controller\Admin;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Doctrine\Common\Collections\Criteria;
use App\Controller\Controller;
use App\Entity\Manager\EventManager;
use App\Util\Time as TimeUtil;
use App\Transformer\EventDataTransformer;
class EventsController extends Controller
{
    const EVENTS_LIMIT = 100;
    public function index(Request $request, EventManager $eventManager) : Response
    {
        $user = $this->getUser();
        $startTimeTimestamp = $request->get("startTime");
        $endTimeTimestamp = $request->get("endTime");
        $startTime = new \DateTime("now");
        $startTime->setTimezone(new \DateTimeZone($user->getTimezone()));
        $startTime->modify("-12 hours");
        $endTime = new \DateTime("now");
        $endTime->setTimezone(new \DateTimeZone($user->getTimezone()));
        $endTime->modify("+1 hour");
        $criteria = new Criteria(null, ["createdAt" => Criteria::DESC], null, self::EVENTS_LIMIT);
        if (false === empty($startTimeTimestamp) && true === TimeUtil::isValidTimestamp($startTimeTimestamp) && false === empty($endTimeTimestamp) && true === TimeUtil::isValidTimestamp($endTimeTimestamp)) {
            $startTime->setTimestamp($startTimeTimestamp);
            $endTime->setTimestamp($endTimeTimestamp);
        }
        $startTimeUtc = clone $startTime;
        $startTimeUtc->setTimezone(new \DateTimeZone("UTC"));
        $endTimeUtc = clone $endTime;
        $endTimeUtc->setTimezone(new \DateTimeZone("UTC"));
        $criteria->where($criteria::expr()->gte("createdAt", $startTimeUtc));
        $criteria->andWhere($criteria::expr()->lte("createdAt", $endTimeUtc));
        $events = $eventManager->findEventsByCriteria($criteria);
        if (count($events)) {
            foreach ($events as $event) {
                $eventDataTransformer = new EventDataTransformer($event, $user);
                $eventDataTransformer->transform();
            }
        }
        $datetimePicker = ["startTime" => $startTime->format("Y/m/d H:00:00"), "endTime" => $endTime->format("Y/m/d H:00:00")];
        $response = $this->render("Admin/Event/index.html.twig", ["events" => $events, "datetimePicker" => $datetimePicker]);
        return $response;
    }
}