<?php

declare(strict_types=1);


namespace WapplerSystems\Events2Extended\Controller;

use JWeiland\Events2\Configuration\ExtConf;
use JWeiland\Events2\Controller\AbstractController;
use JWeiland\Events2\Event\ModifyDaysForMonthEvent;
use JWeiland\Events2\Session\UserSession;
use JWeiland\Events2\Traits\InjectCalendarHelperTrait;
use JWeiland\Events2\Traits\Typo3RequestTrait;
use JWeiland\Events2\Utility\DateTimeUtility;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\DebugUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use WapplerSystems\Events2Extended\Service\DatabaseService;

class CalendarController extends AbstractController
{
    use InjectCalendarHelperTrait;
    use Typo3RequestTrait;


    public function __construct(
        readonly ConnectionPool   $connectionPool,
        protected ExtConf         $extConf,
        protected DateTimeUtility $dateTimeUtility,
        protected UserSession     $userSession,
        protected DatabaseService $databaseService)
    {

    }


    public function showAction(?string $yearAndMonth = null, ?string $categories = null): ResponseInterface
    {

        $frameworkConfiguration = $this->getMergedFrameworkConfiguration();

        if ($yearAndMonth === null || $yearAndMonth === '') {
            // get current month and year
            $dateTime = new \DateTimeImmutable('now');
            $month = (int)$dateTime->format('n');
            $year = (int)$dateTime->format('Y');
        } else {
            $year = (int)substr($yearAndMonth, 0, 4);
            $month = (int)substr($yearAndMonth, 4, 2);
        }

        $month = MathUtility::forceIntegerInRange($month, 1, 12);
        $year = MathUtility::forceIntegerInRange($year, 1500, 2500);
        if ($categories) {
            $categories = GeneralUtility::intExplode(',', $categories, true);
        } else {
            $categories = $this->settings['categories'] ?? [];
        }
        $storagePages = GeneralUtility::intExplode(',', (string)$frameworkConfiguration['persistence']['storagePid'], true);

        // Save a session for selected month
        //$this->userSession->setMonthAndYear($month, $year);

        $daysOfMonth = $this->findAllDaysInMonth($month, $year, $categories, $storagePages);


        //$this->addHolidays($daysOfMonth, $month);

        /** @var ModifyDaysForMonthEvent $event */
        $event = $this->eventDispatcher->dispatch(
            new ModifyDaysForMonthEvent($daysOfMonth),
        );


        $calendarWeeks = $this->getCalendarWeeks(
            $month,
            $year
        );
        foreach ($calendarWeeks as $calendarWeekNum => $weekDays) {
            foreach ($weekDays as $dayNum => $day) {
                $dayOfMonth = $day['day'] ?? -1;
                $calendarWeeks[$calendarWeekNum][$dayNum]['events'] = $daysOfMonth[$dayOfMonth] ?? [];
            }
        }

        //DebugUtility::debug($calendarWeeks);

        $this->postProcessAndAssignFluidVariables([
            'settings' => $this->settings,
            'month' => $month,
            'days' => $daysOfMonth,
            'calendarWeeks' => $calendarWeeks,
            //'startOfMonth' => $startOfMonth,
            //'endOfMonth' => $endOfMonth,
            'pidOfListPage' => $this->settings['pidOfListPage'] ?: $this->getTypoScriptFrontendController($this->request)->id
        ]);

        return $this->htmlResponse();
    }


    protected function findAllDaysInMonth(int $month, int $year, array $categories, array $storagePages): array
    {
        $earliestAllowedDate = new \DateTimeImmutable('now midnight');
        $earliestAllowedDate = $earliestAllowedDate->modify(sprintf('-%d months', $this->extConf->getRecurringPast()));

        $latestAllowedDate = new \DateTimeImmutable('now midnight');
        $latestAllowedDate = $latestAllowedDate->modify(sprintf('+%d months', $this->extConf->getRecurringFuture()));

        // get start and ending of given month
        // j => day without leading 0, n => month without leading 0
        $firstDayOfMonth = $this->dateTimeUtility->standardizeDateTimeObject(
            \DateTimeImmutable::createFromFormat('j.n.Y', '1.' . $month . '.' . $year),
        );
        $lastDayOfMonth = $firstDayOfMonth->modify('last day of this month');

        if (
            $earliestAllowedDate > $firstDayOfMonth &&
            $earliestAllowedDate->format('mY') === $firstDayOfMonth->format('mY')
        ) {
            // if $earliestAllowedDate 17.01.2008 is greater than $firstDayOfMonth (01.01.2008)
            // and both dates are in same month, then set date to $earliestAllowedDate 17.01.2008
            $firstDayOfMonth = $earliestAllowedDate;
        } elseif (
            $latestAllowedDate < $lastDayOfMonth &&
            $latestAllowedDate->format('mY') === $lastDayOfMonth->format('mY')
        ) {
            // if $latestAllowedDate 23.09.2008 is lower than $lastDayOfMonth (30.09.2008)
            // and both dates are in same month, then set date to $latestAllowedDate 23.09.2008
            $lastDayOfMonth = $latestAllowedDate;
        } elseif (
            $earliestAllowedDate > $firstDayOfMonth ||
            $latestAllowedDate < $lastDayOfMonth
        ) {
            // if both values are out of range, do not return any date
            return [];
        }

        $events = $this->databaseService->getEventsInRange(
            $firstDayOfMonth,
            $lastDayOfMonth->modify('tomorrow'),
            $storagePages,
            $categories,
        );

        $days = [];

        foreach ($events as $event) {
            //DebugUtility::debug($event, 'Event');

            $date = new \DateTimeImmutable(date('c', (int)$event['day']));
            if ($date->getTimezone()->getLocation() === false) {
                $date->setTimezone(new \DateTimeZone(date_default_timezone_get()));
            }
            $day = (int)$date->format('j');
            if (!isset($days[$day])) {
                $days[$day] = [];
            }
            $days[$day][] = [
                'uid' => (int)$event['uid'],
                'title' => $event['title'],
                'teaser' => $event['teaser'],
                'details' => $event['detail_information'],
                'multiple_times' => (bool)$event['multiple_times'],
                'images' => $event['images'],
            ];

        }

        return $days;
    }

    protected function addHolidays(array &$days, int $month): void
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_events2_domain_model_holiday');
        $queryResult = $queryBuilder
            ->select('day')
            ->from('tx_events2_domain_model_holiday')
            ->where(
                $queryBuilder->expr()->eq(
                    'month',
                    $queryBuilder->createNamedParameter($month, Connection::PARAM_INT),
                ),
            )
            ->executeQuery();

        while ($holiday = $queryResult->fetchAssociative()) {
            $days[] = [
                'dayOfMonth' => (int)$holiday['day'],
                'isHoliday' => true,
                'additionalClasses' => ['holiday'],
            ];
        }
    }


    /**
     * Returns the merged (TypoScript + FlexForm) plugin configuration
     */
    protected function getMergedFrameworkConfiguration(): array
    {
        return $this->configurationManager->getConfiguration(
            ConfigurationManagerInterface::CONFIGURATION_TYPE_FRAMEWORK,
        );
    }

    private function getCalendarWeeks(int $month, int $year)
    {
        $firstDay = new \DateTimeImmutable("$year-$month-01");
        $lastDay = $firstDay->modify('last day of this month');
        $weeks = [];

        for ($day = $firstDay; $day <= $lastDay; $day = $day->modify('+1 day')) {
            $week = (int)$day->format('W');
            if (isset($weeks[$week]) === false) {
                $weeks[$week] = [
                    1 => [],
                    2 => [],
                    3 => [],
                    4 => [],
                    5 => [],
                    6 => [],
                    7 => [],
                ];
            }
            $weeks[$week][(int)$day->format('N')] = [
                'date' => $day,
                'day' => (int)$day->format('j'),
                'month' => (int)$day->format('n'),
                'year' => (int)$day->format('Y'),
            ];
        }
        return $weeks;
    }
}
