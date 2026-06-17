<?php

namespace App\Controller\Frontend;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use App\Controller\Controller;
use App\Entity\Manager\InstanceCpuManager as InstanceCpuEntityManager;
use App\Entity\Manager\InstanceMemoryManager as InstanceMemoryEntityManager;
use App\Entity\Manager\InstanceDiskUsageManager as InstanceDiskUsageEntityManager;
use App\Entity\Manager\InstanceLoadAverageManager as InstanceLoadAverageEntityManager;
use App\System\CommandExecutor;
use App\System\Command\MemoryInformationCommand;
use App\System\Command\ProcessorCoresCommand;
use App\Entity\InstanceDiskUsage as InstanceDiskUsageEntity;
use App\Entity\InstanceLoadAverage as InstanceLoadAverageEntity;
use App\Monitoring\Chart;
use App\Monitoring\LoadAverageChart;
use App\Util\HumanFileSize as HumanFileSizeUtil;
use App\Util\Time as TimeUtil;
use App\Service\Logger;
use App\Entity\User;
class DashboardController extends Controller
{
    public const TIME_RANGE_LAST_THIRTY_MINUTES = 1;
    public const TIME_RANGE_LAST_HOUR = 2;
    public const TIME_RANGE_LAST_THREE_HOURS = 3;
    public const TIME_RANGE_LAST_SIX_HOURS = 4;
    public const TIME_RANGE_LAST_TWELVE_HOURS = 5;
    private static array $timeRanges = [self::TIME_RANGE_LAST_THIRTY_MINUTES, self::TIME_RANGE_LAST_HOUR, self::TIME_RANGE_LAST_THREE_HOURS, self::TIME_RANGE_LAST_SIX_HOURS, self::TIME_RANGE_LAST_TWELVE_HOURS];
    private InstanceCpuEntityManager $instanceCpuEntityManager;
    private InstanceMemoryEntityManager $instanceMemoryEntityManager;
    private InstanceDiskUsageEntityManager $instanceDiskUsageEntityManager;
    private InstanceLoadAverageEntityManager $instanceLoadAverageEntityManager;
    public function __construct(InstanceCpuEntityManager $instanceCpuEntityManager, InstanceMemoryEntityManager $instanceMemoryEntityManager, InstanceDiskUsageEntityManager $instanceDiskUsageEntityManager, InstanceLoadAverageEntityManager $instanceLoadAverageEntityManager, TranslatorInterface $translator, Logger $logger)
    {
        $this->instanceCpuEntityManager = $instanceCpuEntityManager;
        $this->instanceMemoryEntityManager = $instanceMemoryEntityManager;
        $this->instanceDiskUsageEntityManager = $instanceDiskUsageEntityManager;
        $this->instanceLoadAverageEntityManager = $instanceLoadAverageEntityManager;
        parent::__construct($translator, $logger);
    }
    public function index(Request $request) : Response
    {
        $user = $this->getUser();
        $instance = $request->attributes->get("instance");
        $environment = $instance->getEnvironment();
        $cloud = $environment->getCloudProvider();
        $timeRange = $request->get("timeRange");
        $timeRange = true === is_null($timeRange) ? self::TIME_RANGE_LAST_THIRTY_MINUTES : (true === in_array($timeRange, self::$timeRanges) ? $timeRange : self::TIME_RANGE_LAST_THIRTY_MINUTES);
        $totalMemory = '';
        $numberOfProcessorCores = 0;
        try {
            $commandExecutor = new CommandExecutor();
            $memoryInformationCommand = new MemoryInformationCommand();
            $commandExecutor->execute($memoryInformationCommand);
            $totalMemoryInBytes = $memoryInformationCommand->getTotalMemoryInBytes();
            $totalMemory = HumanFileSizeUtil::convert($totalMemoryInBytes, "GB", 0);
            $processorCoresCommand = new ProcessorCoresCommand();
            $commandExecutor->execute($processorCoresCommand);
            $numberOfProcessorCores = $processorCoresCommand->getNumberOfProcessorCores();
        } catch (\Exception $e) {
            $this->logger->exception($e);
        }
        $rootDiskSizeInBytes = disk_total_space(InstanceDiskUsageEntity::DISK_ROOT);
        $rootDiskSize = HumanFileSizeUtil::convert($rootDiskSizeInBytes, "GB", 0);
        $homeDiskSizeInBytes = disk_total_space(InstanceDiskUsageEntity::DISK_HOME);
        $homeDiskSize = HumanFileSizeUtil::convert($homeDiskSizeInBytes, "GB", 0);
        $dateTime = new \DateTime();
        $endTime = TimeUtil::roundToNearestMinuteInterval($dateTime, 5);
        switch ($timeRange) {
            case self::TIME_RANGE_LAST_THIRTY_MINUTES:
                $minuteInterval = 5;
                $startTime = (clone $endTime)->modify("-30 minutes");
                break;
            case self::TIME_RANGE_LAST_HOUR:
                $minuteInterval = 10;
                $startTime = (clone $endTime)->modify("-1 hour");
                break;
            case self::TIME_RANGE_LAST_THREE_HOURS:
                $minuteInterval = 30;
                $startTime = (clone $dateTime)->modify("-3 hours");
                break;
            case self::TIME_RANGE_LAST_SIX_HOURS:
                $minuteInterval = 60;
                $startTime = (clone $dateTime)->modify("-6 hours");
                break;
            case self::TIME_RANGE_LAST_TWELVE_HOURS:
                $minuteInterval = 120;
                $startTime = (clone $dateTime)->modify("-12 hours");
                break;
        }
        $categories = $this->getCategoriesForTimeRange($user, $startTime, $endTime, $minuteInterval);
        $cpuData = $this->getCpuDataForTimeRange($startTime, $endTime, $minuteInterval);
        $memoryData = $this->getMemoryDataForTimeRange($startTime, $endTime, $minuteInterval);
        $diskData = $this->getDiskDataForTimeRange($startTime, $endTime, $minuteInterval);
        if ($homeDiskSize != $rootDiskSize) {
            $diskData = [["name" => sprintf("/ (%s)", $rootDiskSize), "color" => "#73bf4c", "data" => $diskData["root"] ?: []], ["name" => sprintf("/home (%s)", $homeDiskSize), "color" => "#f3bf00", "data" => $diskData["home"] ?: []]];
        } else {
            $diskData = [["name" => sprintf("/ (%s)", $rootDiskSize), "color" => "#73bf4c", "data" => $diskData["root"] ?: []]];
        }
        $loadAverageRawData = $this->getLoadAverageDataForTimeRange($startTime, $endTime, $minuteInterval);
        $loadAverageData = [["name" => $this->translator->trans("1 Minute"), "color" => "#774aa4", "data" => $loadAverageRawData[InstanceLoadAverageEntity::PERIOD_ONE_MINUTE] ?? []], ["name" => $this->translator->trans("5 Minutes"), "color" => "#eb586c", "data" => $loadAverageRawData[InstanceLoadAverageEntity::PERIOD_FIVE_MINUTES] ?? []], ["name" => $this->translator->trans("15 Minutes"), "color" => "#0078d4", "data" => $loadAverageRawData[InstanceLoadAverageEntity::PERIOD_FIVETEEN_MINUTES] ?? []]];
        $cpuInformation = sprintf("%s %s", $numberOfProcessorCores, $this->translator->trans("CPU"));
        $cpuChart = new Chart("CPU Usage");
        $cpuChart->setInformation($cpuInformation);
        $cpuChart->setData($cpuData);
        $cpuChart->setCategories($categories);
        $memoryChart = new Chart("Memory Usage");
        $memoryChart->setInformation($totalMemory);
        $memoryChart->setData($memoryData);
        $memoryChart->setCategories($categories);
        $diskChart = new Chart("Disk Usage");
        $diskChart->setData($diskData);
        $diskChart->setCategories($categories);
        $loadAverageChartInformation = sprintf("%s %s", $numberOfProcessorCores, $this->translator->trans("CPU"));
        $loadAverageChart = new LoadAverageChart("Load Average");
        $loadAverageChart->setInformation($loadAverageChartInformation);
        $loadAverageChart->setData($loadAverageData);
        $loadAverageChart->setCategories($categories);
        $response = $this->render("Frontend/Dashboard/index.html.twig", ["cloud" => $cloud, "instance" => $instance, "user" => $user, "timeRange" => $timeRange, "cpuChart" => $cpuChart, "memoryChart" => $memoryChart, "diskChart" => $diskChart, "loadAverageChart" => $loadAverageChart]);
        return $response;
    }
    private function getCategoriesForTimeRange(User $user, \DateTimeInterface $startTime, \DateTimeInterface $endTime, $minuteInterval) : array
    {
        $categories = [];
        $startDataDate = (clone $startTime)->modify(sprintf("-%05d minutes", $minuteInterval));
        $endDataDate = clone $startTime;
        while ($endDataDate <= $endTime) {
            $categoryDateTime = clone $endDataDate;
            $categoryDateTime->setTimezone(new \DateTimeZone($user->getTimezone()));
            $categories[] = $categoryDateTime->format("H:i");
            $startDataDate->modify(sprintf("+%05d minutes", $minuteInterval));
            $endDataDate->modify(sprintf("+%05d minutes", $minuteInterval));
        }
        return $categories;
    }
    private function getCpuDataForTimeRange(\DateTimeInterface $startTime, \DateTimeInterface $endTime, $minuteInterval) : array
    {
        $cpuData = [];
        $startDataDate = (clone $startTime)->modify(sprintf("-%05d minutes", $minuteInterval));
        $endDataDate = clone $startTime;
        while ($endDataDate <= $endTime) {
            $averageCpuValue = $this->instanceCpuEntityManager->getAverageCpuValue($startDataDate, $endDataDate);
            $cpuData[] = $averageCpuValue;
            $startDataDate->modify(sprintf("+%05d minutes", $minuteInterval));
            $endDataDate->modify(sprintf("+%05d minutes", $minuteInterval));
        }
        return $cpuData;
    }
    private function getMemoryDataForTimeRange(\DateTimeInterface $startTime, \DateTimeInterface $endTime, $minuteInterval) : array
    {
        $memoryData = [];
        $startDataDate = (clone $startTime)->modify(sprintf("-%05d minutes", $minuteInterval));
        $endDataDate = clone $startTime;
        while ($endDataDate <= $endTime) {
            $averageMemoryValue = $this->instanceMemoryEntityManager->getAverageMemoryValue($startDataDate, $endDataDate);
            $memoryData[] = (int) $averageMemoryValue;
            $startDataDate->modify(sprintf("+%05d minutes", $minuteInterval));
            $endDataDate->modify(sprintf("+%05d minutes", $minuteInterval));
        }
        return $memoryData;
    }
    private function getDiskDataForTimeRange($startTime, $endTime, $minuteInterval) : array
    {
        $diskData = [];
        $startDataDate = (clone $startTime)->modify(sprintf("-%05d minutes", $minuteInterval));
        $endDataDate = clone $startTime;
        while ($endDataDate <= $endTime) {
            foreach (["/", "/home"] as $disk) {
                $averageDiskSizeValue = (int) $this->instanceDiskUsageEntityManager->getAverageDiskSizeValue($disk, $startDataDate, $endDataDate);
                if (!("/" == $disk)) {
                    $diskData["home"][] = (int) $averageDiskSizeValue;
                    continue;
                }
                $diskData["root"][] = (int) $averageDiskSizeValue;
            }
            $startDataDate->modify(sprintf("+%05d minutes", $minuteInterval));
            $endDataDate->modify(sprintf("+%05d minutes", $minuteInterval));
        }
        return $diskData;
    }
    private function getLoadAverageDataForTimeRange($startTime, $endTime, $minuteInterval) : array
    {
        $loadAverageData = [];
        $startDataDate = (clone $startTime)->modify(sprintf("-%05d minutes", $minuteInterval));
        $endDataDate = clone $startTime;
        while ($endDataDate <= $endTime) {
            foreach ([InstanceLoadAverageEntity::PERIOD_ONE_MINUTE, InstanceLoadAverageEntity::PERIOD_FIVE_MINUTES, InstanceLoadAverageEntity::PERIOD_FIVETEEN_MINUTES] as $period) {
                $loadAverageValue = $this->instanceLoadAverageEntityManager->getLoadAverageValue($period, $startDataDate, $endDataDate);
                $loadAverageData[$period][] = round($loadAverageValue, 2);
            }
            $startDataDate->modify(sprintf("+%05d minutes", $minuteInterval));
            $endDataDate->modify(sprintf("+%05d minutes", $minuteInterval));
        }
        return $loadAverageData;
    }
}