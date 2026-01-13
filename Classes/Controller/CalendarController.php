<?php

declare(strict_types=1);


namespace WapplerSystems\Events2Extended\Controller;

use JWeiland\Events2\Configuration\ExtConf;
use JWeiland\Events2\Controller\AbstractController;
use JWeiland\Events2\Domain\Model\Category;
use JWeiland\Events2\Domain\Model\Event;
use JWeiland\Events2\Domain\Model\Location;
use JWeiland\Events2\Domain\Repository\CategoryRepository;
use JWeiland\Events2\Domain\Repository\LocationRepository;
use JWeiland\Events2\Event\ModifyDaysForMonthEvent;
use JWeiland\Events2\Session\UserSession;
use JWeiland\Events2\Traits\InjectCalendarHelperTrait;
use JWeiland\Events2\Traits\Typo3RequestTrait;
use JWeiland\Events2\Utility\DateTimeUtility;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Extbase\Persistence\Generic\Mapper\DataMapper;
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
        protected DatabaseService $databaseService,
        readonly protected LocationRepository $locationRepository,
        readonly protected CategoryRepository $categoryRepository,
    )
    {

    }


    public function showAction(?string $yearAndMonth = null, ?int $category = null, ?int $location = null): ResponseInterface
    {

        $flexFormSettings = $this->configurationManager->getConfiguration(
            ConfigurationManagerInterface::CONFIGURATION_TYPE_SETTINGS
        );
        $this->settings = array_merge(
            $this->settings,
            $flexFormSettings ?? []
        );

        $locations = GeneralUtility::intExplode(',', (string)($this->settings['locations'] ?? ''), true);
        $locationsArray = [];
        foreach ($locations as $locationUid) {
            /** @var Location $locationObject */
            $locationObject = $this->locationRepository->findByUid($locationUid);
            if ($locationObject) {
                $locationsArray[$locationObject->getUid()] = $locationObject->getLocationAsString();
            }
        }

        $categories = GeneralUtility::intExplode(',', (string)($this->settings['categories'] ?? ''), true);
        $categoriesArray = [];
        foreach ($categories as $categoryUid) {
            /** @var Category $categoryObject */
            $categoryObject = $this->categoryRepository->findByUid($categoryUid);
            if ($categoryObject) {
                $categoriesArray[$categoryObject->getUid()] = $categoryObject->getTitle();
            }
        }

        $frameworkConfiguration = $this->configurationManager->getConfiguration(
            ConfigurationManagerInterface::CONFIGURATION_TYPE_FRAMEWORK,
        );

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
        $year = MathUtility::forceIntegerInRange($year, 1500, 2800);

        $storagePages = GeneralUtility::intExplode(',', (string)$frameworkConfiguration['persistence']['storagePid'], true);

        $selectedLocation = $location;
        $selectedCategory = $category;

        // Save a session for selected month
        //$this->userSession->setMonthAndYear($month, $year);

        $categories = [];
        if ($category !== null) {
            $categories = [$category];
        }
        $locations = [];
        if ($location !== null) {
            $locations = [$location];
        }

        $daysOfMonth = $this->findAllDaysInMonth($month, $year, $storagePages, $categories, $locations);


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
                $events = $daysOfMonth[$dayOfMonth] ?? [];
                usort($events, function (Event $a, Event $b) {
                    if ($a->getEventTime() === null || $b->getEventTime() === null) {
                        return 0;
                    }
                    $timeA = strtotime($a->getEventTime()->getTimeBegin());
                    $timeB = strtotime($b->getEventTime()->getTimeBegin());
                    return $timeA <=> $timeB;
                });
                $calendarWeeks[$calendarWeekNum][$dayNum]['events'] = $events;

                if (isset($day['date']) && $day['date'] instanceof \DateTimeImmutable) {
                    if ($day['date']->format('Y-m-d') === (new \DateTimeImmutable('today'))->format('Y-m-d')) {
                        $calendarWeeks[$calendarWeekNum][$dayNum]['isToday'] = true;
                    }
                }
            }
        }

        if ($month === 12) {
            $nextMonth = 1;
            $nextYear = $year + 1;
        } else {
            $nextMonth = $month + 1;
            $nextYear = $year;
        }
        $nextYearAndMonth = $nextYear.str_pad((string)$nextMonth, 2, '0', STR_PAD_LEFT);
        if ($month === 1) {
            $previousMonth = 12;
            $previousYear = $year - 1;
        } else {
            $previousMonth = $month - 1;
            $previousYear = $year;
        }
        $previousYearAndMonth = $previousYear.str_pad((string)$previousMonth, 2, '0', STR_PAD_LEFT);

        $this->postProcessAndAssignFluidVariables([
            'settings' => $this->settings,
            'month' => $month,
            'year' => $year,
            'nextYearAndMonth' => $nextYearAndMonth,
            'previousYearAndMonth' => $previousYearAndMonth,
            'nextMonth' => $nextMonth,
            'previousMonth' => $previousMonth,
            'days' => $daysOfMonth,
            'calendarWeeks' => $calendarWeeks,
            'showCategoryFilter' => $this->settings['showCategoryFilter'] ?? false,
            'selectedLocation' => $selectedLocation,
            'selectedCategory' => $selectedCategory,
            'showLocationFilter' => $this->settings['showLocationFilter'] ?? false,
            'pidOfListPage' => $this->settings['pidOfListPage'] ?: $this->getPageArguments($this->request)->getPageId(),
            'locationsArray' => $locationsArray,
            'categoriesArray' => $categoriesArray,
        ]);

        return $this->htmlResponse();
    }


    protected function findAllDaysInMonth(int $month, int $year, array $storagePages, array $categories = [], array $locations = []): array
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

        /** @var DataMapper $dataMapper */
        $dataMapper = GeneralUtility::makeInstance(DataMapper::class);


        $days = [];

        foreach ($events as $event) {
            if (count($locations) > 0) {
                if (!in_array($event['location'], $locations, true)) {
                    // skip event if location is not in selected locations
                    continue;
                }
            }

            $date = new \DateTimeImmutable(date('c', (int)$event['day']));
            if ($date->getTimezone()->getLocation() === false) {
                $date->setTimezone(new \DateTimeZone(date_default_timezone_get()));
            }
            $day = (int)$date->format('j');
            if (!isset($days[$day])) {
                $days[$day] = [];
            }
            $eventObjects = $dataMapper->map(
                \JWeiland\Events2\Domain\Model\Event::class,
                [$event]
            );
            //DebugUtility::debug($eventObjects, 'Event');
            $days[$day][] = $eventObjects[0];

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
                'timestamp' => (int)$day->format('U'),
                'day' => (int)$day->format('j'),
                'month' => (int)$day->format('n'),
                'year' => (int)$day->format('Y'),
            ];
        }
        return $weeks;
    }


    public function gotoAction(string $yearAndMonth, ?int $category = null, ?int $location = null): ResponseInterface
    {

        $redirectUri = $this->uriBuilder->reset()->setTargetPageUid($GLOBALS['TSFE']->id)->setArguments([
            'tx_events2extended_calendar' => [
                'action' => 'show',
                'yearAndMonth' => $yearAndMonth,
                'category' => $category,
                'location' => $location,
            ],

        ])->buildFrontendUri();

        return $this->redirectToUri($redirectUri);


    }

}
