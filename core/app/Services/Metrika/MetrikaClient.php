<?php

declare(strict_types=1);

namespace App\Services\Metrika;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Throwable;

final class MetrikaClient
{
    /** Код исключения: нет доступа к счётчику или токен недействителен. */
    public const ACCESS_DENIED = 4031;

    public static function accessDenied(string $message): RuntimeException
    {
        return new RuntimeException($message, self::ACCESS_DENIED);
    }

    private const API_URL = 'https://api-metrika.yandex.net';

    private const VISIT_METRICS = [
        'ym:s:visits',
        'ym:s:users',
        'ym:s:pageviews',
        'ym:s:bounceRate',
        'ym:s:pageDepth',
        'ym:s:avgVisitDurationSeconds',
    ];

    private const VISIT_SEGMENTS = [
        'traffic_source' => 'ym:s:lastSignTrafficSource',
        'landing_page' => 'ym:s:startURL',
        'exit_page' => 'ym:s:endURL',
        'device' => 'ym:s:deviceCategory',
        'browser' => 'ym:s:browser',
        'operating_system' => 'ym:s:operatingSystem',
        'country' => 'ym:s:regionCountry',
        'region' => 'ym:s:regionArea',
        'new_returning' => 'ym:s:isNewUser',
    ];

    private const CROSS_SEGMENTS = [
        'source_landing' => [
            'ym:s:lastSignTrafficSource',
            'ym:s:startURL',
        ],
        'device_landing' => [
            'ym:s:deviceCategory',
            'ym:s:startURL',
        ],
        'source_device' => [
            'ym:s:lastSignTrafficSource',
            'ym:s:deviceCategory',
        ],
        'browser_device' => [
            'ym:s:browser',
            'ym:s:deviceCategory',
        ],
    ];

    private const CONVERSION_BREAKDOWNS = [
        'source' => 'ym:s:lastSignTrafficSource',
        'landing_page' => 'ym:s:startURL',
        'device' => 'ym:s:deviceCategory',
        'browser' => 'ym:s:browser',
        'operating_system' => 'ym:s:operatingSystem',
        'country' => 'ym:s:regionCountry',
        'region' => 'ym:s:regionArea',
    ];

    /** @var string */
    private $oauthToken;

    /** @var DateTimeZone */
    private $timezone;

    /** @var int */
    private $pageSize;

    /** @var int */
    private $connectTimeout;

    /** @var int */
    private $timeout;

    /** @var int */
    private $maxRetries;

    public function __construct(
        string $oauthToken,
        string $timezone = 'Europe/Moscow',
        int $pageSize = 10000,
        int $connectTimeout = 10,
        int $timeout = 90,
        int $maxRetries = 3
    ) {
        if (!extension_loaded('curl')) {
            throw new RuntimeException(
                'Расширение PHP cURL не установлено'
            );
        }

        $oauthToken = trim($oauthToken);

        if ($oauthToken === '') {
            throw new InvalidArgumentException(
                'Не указан OAuth-токен Яндекс Метрики'
            );
        }

        $this->oauthToken = $oauthToken;
        $this->timezone = new DateTimeZone($timezone);
        $this->pageSize = max(1, min(10000, $pageSize));
        $this->connectTimeout = max(1, $connectTimeout);
        $this->timeout = max(1, $timeout);
        $this->maxRetries = max(1, $maxRetries);
    }

    public function checkCounterAccess(
        int $counterId
    ): array {
        $this->validateCounterId($counterId);

        $response = $this->request(
            '/management/v1/counter/' . $counterId
        );

        if (
            !isset($response['counter'])
            || !is_array($response['counter'])
        ) {
            throw self::accessDenied(
                'Метрика не вернула информацию о счётчике '
                . $counterId
            );
        }

        $counter = $response['counter'];

        return [
            'counter_id' => $counterId,
            'name' => $counter['name'] ?? null,
            'site' => $counter['site'] ?? null,
            'status' => $counter['status'] ?? null,
            'permission' => $counter['permission'] ?? null,
            'access_granted' => true,
        ];
    }

    public function resolvePeriod(
        string $dateFrom,
        string $dateTo
    ): array {
        $from = $this->resolveDate($dateFrom);
        $to = $this->resolveDate($dateTo);

        if ($from > $to) {
            throw new InvalidArgumentException(
                'Начальная дата позже конечной даты'
            );
        }

        return [
            'requested' => [
                'from' => $dateFrom,
                'to' => $dateTo,
            ],
            'resolved' => [
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
            ],
            'days' => ((int) $from->diff($to)->days) + 1,
        ];
    }

    public function getTrafficSummary(
        int $counterId,
        string $dateFrom,
        string $dateTo
    ): array {
        $period = $this->resolvePeriod(
            $dateFrom,
            $dateTo
        );

        $report = $this->getReport(
            $counterId,
            [
                'date1' => $period['resolved']['from'],
                'date2' => $period['resolved']['to'],
                'metrics' => implode(
                    ',',
                    self::VISIT_METRICS
                ),
                'accuracy' => 'full',
                'lang' => 'ru',
            ],
            1
        );

        return $this->attachPeriod(
            $report,
            $period
        );
    }

    public function getTrafficDynamics(
        int $counterId,
        string $dateFrom,
        string $dateTo,
        string $groupBy = 'auto'
    ): array {
        $period = $this->resolvePeriod(
            $dateFrom,
            $dateTo
        );

        $resolvedGroup = $this->resolveGroupBy(
            $groupBy,
            $period['days']
        );

        if ($resolvedGroup === 'day') {
            $report = $this->getReport(
                $counterId,
                [
                    'date1' => $period['resolved']['from'],
                    'date2' => $period['resolved']['to'],
                    'dimensions' => 'ym:s:date',
                    'metrics' => implode(
                        ',',
                        self::VISIT_METRICS
                    ),
                    'sort' => 'ym:s:date',
                    'accuracy' => 'full',
                    'lang' => 'ru',
                ],
                $period['days'] + 5
            );

            return [
                'requested_period' =>
                    $period['requested'],

                'resolved_period' =>
                    $period['resolved'],

                'group_by' => 'day',
                'rows' => $report['rows'],
                'metadata' => $report['metadata'],
            ];
        }

        return $this->getGroupedDynamics(
            $counterId,
            $period,
            $resolvedGroup
        );
    }

    public function getVisitSegment(
        int $counterId,
        string $dateFrom,
        string $dateTo,
        string $segment,
        int $limit = 20
    ): array {
        if (!isset(self::VISIT_SEGMENTS[$segment])) {
            throw new InvalidArgumentException(
                'Неизвестный сегмент: ' . $segment
            );
        }

        return $this->getVisitReportByDimensions(
            $counterId,
            $dateFrom,
            $dateTo,
            [
                self::VISIT_SEGMENTS[$segment],
            ],
            $limit
        );
    }

    public function getTrafficSources(
        int $counterId,
        string $dateFrom,
        string $dateTo,
        int $limit = 20
    ): array {
        return $this->getVisitSegment(
            $counterId,
            $dateFrom,
            $dateTo,
            'traffic_source',
            $limit
        );
    }

    public function getLandingPages(
        int $counterId,
        string $dateFrom,
        string $dateTo,
        int $limit = 20
    ): array {
        return $this->getVisitSegment(
            $counterId,
            $dateFrom,
            $dateTo,
            'landing_page',
            $limit
        );
    }

    public function getExitPages(
        int $counterId,
        string $dateFrom,
        string $dateTo,
        int $limit = 20
    ): array {
        return $this->getVisitSegment(
            $counterId,
            $dateFrom,
            $dateTo,
            'exit_page',
            $limit
        );
    }

    public function getDevices(
        int $counterId,
        string $dateFrom,
        string $dateTo,
        int $limit = 20
    ): array {
        return $this->getVisitSegment(
            $counterId,
            $dateFrom,
            $dateTo,
            'device',
            $limit
        );
    }

    public function getTechnologyReport(
        int $counterId,
        string $dateFrom,
        string $dateTo,
        string $technology,
        int $limit = 20
    ): array {
        $allowed = [
            'browser',
            'operating_system',
        ];

        if (!in_array($technology, $allowed, true)) {
            throw new InvalidArgumentException(
                'technology должен быть browser '
                . 'или operating_system'
            );
        }

        return $this->getVisitSegment(
            $counterId,
            $dateFrom,
            $dateTo,
            $technology,
            $limit
        );
    }

    /**
     * Просмотры всех URL, а не только страниц входа.
     */
    public function getPageviews(
        int $counterId,
        string $dateFrom,
        string $dateTo,
        int $limit = 20
    ): array {
        $period = $this->resolvePeriod(
            $dateFrom,
            $dateTo
        );

        $report = $this->getReport(
            $counterId,
            [
                'date1' => $period['resolved']['from'],
                'date2' => $period['resolved']['to'],
                'dimensions' => 'ym:pv:URL',
                'metrics' => implode(
                    ',',
                    [
                        'ym:pv:pageviews',
                        'ym:pv:users',
                    ]
                ),
                'sort' => '-ym:pv:pageviews',
                'include_undefined' => 'true',
                'accuracy' => 'full',
                'lang' => 'ru',
            ],
            $this->normalizeLimit($limit)
        );

        return $this->attachPeriod(
            $report,
            $period
        );
    }

    public function getCrossSegment(
        int $counterId,
        string $dateFrom,
        string $dateTo,
        string $crossType,
        int $limit = 20
    ): array {
        if (!isset(self::CROSS_SEGMENTS[$crossType])) {
            throw new InvalidArgumentException(
                'Неизвестный cross_type: '
                . $crossType
            );
        }

        return $this->getVisitReportByDimensions(
            $counterId,
            $dateFrom,
            $dateTo,
            self::CROSS_SEGMENTS[$crossType],
            $limit
        );
    }

    public function getGoalsReport(
        int $counterId,
        string $dateFrom,
        string $dateTo
    ): array {
        $period = $this->resolvePeriod(
            $dateFrom,
            $dateTo
        );

        $goals = $this->getGoals($counterId);

        return [
            'requested_period' =>
                $period['requested'],

            'resolved_period' =>
                $period['resolved'],

            'items' => $goals,

            'statistics' => $this->getGoalStatistics(
                $counterId,
                $goals,
                $period['resolved']['from'],
                $period['resolved']['to']
            ),
        ];
    }

    public function getGoalConversions(
        int $counterId,
        string $dateFrom,
        string $dateTo,
        int $goalId,
        string $breakdown,
        int $limit = 20
    ): array {
        if ($goalId <= 0) {
            throw new InvalidArgumentException(
                'Не указан корректный goal_id'
            );
        }

        if (
            !isset(
                self::CONVERSION_BREAKDOWNS[$breakdown]
            )
        ) {
            throw new InvalidArgumentException(
                'Неизвестный breakdown: '
                . $breakdown
            );
        }

        $period = $this->resolvePeriod(
            $dateFrom,
            $dateTo
        );

        $reachesMetric =
            'ym:s:goal' . $goalId . 'reaches';

        $conversionMetric =
            'ym:s:goal'
            . $goalId
            . 'conversionRate';

        $report = $this->getReport(
            $counterId,
            [
                'date1' => $period['resolved']['from'],
                'date2' => $period['resolved']['to'],

                'dimensions' =>
                    self::CONVERSION_BREAKDOWNS[
                        $breakdown
                    ],

                'metrics' => implode(
                    ',',
                    [
                        'ym:s:visits',
                        'ym:s:users',
                        $reachesMetric,
                        $conversionMetric,
                    ]
                ),

                'sort' => '-' . $reachesMetric,
                'include_undefined' => 'true',
                'accuracy' => 'full',
                'lang' => 'ru',
            ],
            $this->normalizeLimit($limit)
        );

        $report['goal_id'] = $goalId;
        $report['breakdown'] = $breakdown;

        return $this->attachPeriod(
            $report,
            $period
        );
    }

    public function getFullSiteAnalytics(
        int $counterId,
        string $dateFrom,
        string $dateTo,
        int $topLimit = 20,
        string $groupBy = 'auto',
        int $goalId = 0
    ): array {
        $this->validateCounterId($counterId);

        $period = $this->resolvePeriod(
            $dateFrom,
            $dateTo
        );

        $topLimit = $this->normalizeLimit($topLimit);

        $result = [
            'counter_id' => $counterId,

            'requested_period' =>
                $period['requested'],

            'resolved_period' =>
                $period['resolved'],

            'group_by' => $this->resolveGroupBy(
                $groupBy,
                $period['days']
            ),

            'goal_id_for_segments' =>
                $goalId > 0 ? $goalId : null,

            'reports' => [],
            'goals' => [],
            'goal_conversions' => [],
            'errors' => [],
        ];

        $reports = [
            'summary' => function () use (
                $counterId,
                $dateFrom,
                $dateTo
            ): array {
                return $this->getTrafficSummary(
                    $counterId,
                    $dateFrom,
                    $dateTo
                );
            },

            'dynamics' => function () use (
                $counterId,
                $dateFrom,
                $dateTo,
                $groupBy
            ): array {
                return $this->getTrafficDynamics(
                    $counterId,
                    $dateFrom,
                    $dateTo,
                    $groupBy
                );
            },

            'traffic_sources' => function () use (
                $counterId,
                $dateFrom,
                $dateTo,
                $topLimit
            ): array {
                return $this->getTrafficSources(
                    $counterId,
                    $dateFrom,
                    $dateTo,
                    $topLimit
                );
            },

            'landing_pages' => function () use (
                $counterId,
                $dateFrom,
                $dateTo,
                $topLimit
            ): array {
                return $this->getLandingPages(
                    $counterId,
                    $dateFrom,
                    $dateTo,
                    $topLimit
                );
            },

            'exit_pages' => function () use (
                $counterId,
                $dateFrom,
                $dateTo,
                $topLimit
            ): array {
                return $this->getExitPages(
                    $counterId,
                    $dateFrom,
                    $dateTo,
                    $topLimit
                );
            },

            'pageviews' => function () use (
                $counterId,
                $dateFrom,
                $dateTo,
                $topLimit
            ): array {
                return $this->getPageviews(
                    $counterId,
                    $dateFrom,
                    $dateTo,
                    $topLimit
                );
            },

            'devices' => function () use (
                $counterId,
                $dateFrom,
                $dateTo,
                $topLimit
            ): array {
                return $this->getDevices(
                    $counterId,
                    $dateFrom,
                    $dateTo,
                    $topLimit
                );
            },

            'browsers' => function () use (
                $counterId,
                $dateFrom,
                $dateTo,
                $topLimit
            ): array {
                return $this->getTechnologyReport(
                    $counterId,
                    $dateFrom,
                    $dateTo,
                    'browser',
                    $topLimit
                );
            },

            'operating_systems' => function () use (
                $counterId,
                $dateFrom,
                $dateTo,
                $topLimit
            ): array {
                return $this->getTechnologyReport(
                    $counterId,
                    $dateFrom,
                    $dateTo,
                    'operating_system',
                    $topLimit
                );
            },

            'new_returning' => function () use (
                $counterId,
                $dateFrom,
                $dateTo
            ): array {
                return $this->getVisitSegment(
                    $counterId,
                    $dateFrom,
                    $dateTo,
                    'new_returning',
                    10
                );
            },

            'countries' => function () use (
                $counterId,
                $dateFrom,
                $dateTo,
                $topLimit
            ): array {
                return $this->getVisitSegment(
                    $counterId,
                    $dateFrom,
                    $dateTo,
                    'country',
                    $topLimit
                );
            },

            'regions' => function () use (
                $counterId,
                $dateFrom,
                $dateTo,
                $topLimit
            ): array {
                return $this->getVisitSegment(
                    $counterId,
                    $dateFrom,
                    $dateTo,
                    'region',
                    $topLimit
                );
            },

            'source_landing' => function () use (
                $counterId,
                $dateFrom,
                $dateTo,
                $topLimit
            ): array {
                return $this->getCrossSegment(
                    $counterId,
                    $dateFrom,
                    $dateTo,
                    'source_landing',
                    $topLimit
                );
            },

            'device_landing' => function () use (
                $counterId,
                $dateFrom,
                $dateTo,
                $topLimit
            ): array {
                return $this->getCrossSegment(
                    $counterId,
                    $dateFrom,
                    $dateTo,
                    'device_landing',
                    $topLimit
                );
            },

            'source_device' => function () use (
                $counterId,
                $dateFrom,
                $dateTo,
                $topLimit
            ): array {
                return $this->getCrossSegment(
                    $counterId,
                    $dateFrom,
                    $dateTo,
                    'source_device',
                    $topLimit
                );
            },
        ];

        foreach ($reports as $name => $callback) {
            try {
                $result['reports'][$name] =
                    $callback();
            } catch (Throwable $exception) {
                $result['errors'][$name] = [
                    'message' =>
                        $exception->getMessage(),
                ];
            }
        }

        try {
            $result['goals'] =
                $this->getGoalsReport(
                    $counterId,
                    $dateFrom,
                    $dateTo
                );
        } catch (Throwable $exception) {
            $result['errors']['goals'] = [
                'message' => $exception->getMessage(),
            ];
        }

        if ($goalId > 0) {
            foreach (
                [
                    'source',
                    'landing_page',
                    'device',
                ] as $breakdown
            ) {
                try {
                    $result['goal_conversions'][$breakdown] =
                        $this->getGoalConversions(
                            $counterId,
                            $dateFrom,
                            $dateTo,
                            $goalId,
                            $breakdown,
                            $topLimit
                        );
                } catch (Throwable $exception) {
                    $result['errors'][
                        'goal_conversions_' . $breakdown
                    ] = [
                        'message' =>
                            $exception->getMessage(),
                    ];
                }
            }
        }

        return $result;
    }

    public function comparePeriods(
        int $counterId,
        string $periodAFrom,
        string $periodATo,
        string $periodBFrom,
        string $periodBTo,
        int $topLimit = 20,
        string $groupBy = 'auto',
        int $goalId = 0
    ): array {
        $this->validateCounterId($counterId);

        return [
            'counter_id' => $counterId,

            'period_a' =>
                $this->getComparisonDataset(
                    $counterId,
                    $periodAFrom,
                    $periodATo,
                    $topLimit,
                    $groupBy,
                    $goalId
                ),

            'period_b' =>
                $this->getComparisonDataset(
                    $counterId,
                    $periodBFrom,
                    $periodBTo,
                    $topLimit,
                    $groupBy,
                    $goalId
                ),
        ];
    }

    private function getComparisonDataset(
        int $counterId,
        string $dateFrom,
        string $dateTo,
        int $topLimit,
        string $groupBy,
        int $goalId
    ): array {
        $result = [
            'summary' => $this->getTrafficSummary(
                $counterId,
                $dateFrom,
                $dateTo
            ),

            'dynamics' => $this->getTrafficDynamics(
                $counterId,
                $dateFrom,
                $dateTo,
                $groupBy
            ),

            'traffic_sources' =>
                $this->getTrafficSources(
                    $counterId,
                    $dateFrom,
                    $dateTo,
                    $topLimit
                ),

            'landing_pages' =>
                $this->getLandingPages(
                    $counterId,
                    $dateFrom,
                    $dateTo,
                    $topLimit
                ),

            'pageviews' => $this->getPageviews(
                $counterId,
                $dateFrom,
                $dateTo,
                $topLimit
            ),

            'devices' => $this->getDevices(
                $counterId,
                $dateFrom,
                $dateTo,
                $topLimit
            ),

            'goals' => $this->getGoalsReport(
                $counterId,
                $dateFrom,
                $dateTo
            ),

            'goal_conversions' => [],
        ];

        if ($goalId > 0) {
            foreach (
                [
                    'source',
                    'landing_page',
                    'device',
                ] as $breakdown
            ) {
                $result['goal_conversions'][$breakdown] =
                    $this->getGoalConversions(
                        $counterId,
                        $dateFrom,
                        $dateTo,
                        $goalId,
                        $breakdown,
                        $topLimit
                    );
            }
        }

        return $result;
    }

    private function getVisitReportByDimensions(
        int $counterId,
        string $dateFrom,
        string $dateTo,
        array $dimensions,
        int $limit
    ): array {
        $period = $this->resolvePeriod(
            $dateFrom,
            $dateTo
        );

        $report = $this->getReport(
            $counterId,
            [
                'date1' => $period['resolved']['from'],
                'date2' => $period['resolved']['to'],

                'dimensions' => implode(
                    ',',
                    $dimensions
                ),

                'metrics' => implode(
                    ',',
                    self::VISIT_METRICS
                ),

                'sort' => '-ym:s:visits',
                'include_undefined' => 'true',
                'accuracy' => 'full',
                'lang' => 'ru',
            ],
            $this->normalizeLimit($limit)
        );

        return $this->attachPeriod(
            $report,
            $period
        );
    }

    private function getGoals(
        int $counterId
    ): array {
        $this->validateCounterId($counterId);

        $response = $this->request(
            '/management/v1/counter/'
            . $counterId
            . '/goals'
        );

        return isset($response['goals'])
            && is_array($response['goals'])
                ? $response['goals']
                : [];
    }

    private function getGoalStatistics(
        int $counterId,
        array $goals,
        string $dateFrom,
        string $dateTo
    ): array {
        $statistics = [];

        foreach (array_chunk($goals, 10) as $chunk) {
            $metrics = [];
            $goalMap = [];

            foreach ($chunk as $goal) {
                if (empty($goal['id'])) {
                    continue;
                }

                $goalId = (int) $goal['id'];

                $reaches =
                    'ym:s:goal'
                    . $goalId
                    . 'reaches';

                $conversion =
                    'ym:s:goal'
                    . $goalId
                    . 'conversionRate';

                $metrics[] = $reaches;
                $metrics[] = $conversion;

                $goalMap[$goalId] = [
                    'id' => $goalId,
                    'name' => $goal['name'] ?? null,
                    'type' => $goal['type'] ?? null,
                    'reaches_metric' => $reaches,
                    'conversion_metric' => $conversion,
                ];
            }

            if ($metrics === []) {
                continue;
            }

            try {
                $report = $this->getReport(
                    $counterId,
                    [
                        'date1' => $dateFrom,
                        'date2' => $dateTo,
                        'metrics' => implode(
                            ',',
                            $metrics
                        ),
                        'accuracy' => 'full',
                        'lang' => 'ru',
                    ],
                    1
                );

                foreach ($goalMap as $goalId => $goal) {
                    $statistics[] = [
                        'id' => $goalId,
                        'name' => $goal['name'],
                        'type' => $goal['type'],

                        'reaches' =>
                            $report['totals'][
                                $goal['reaches_metric']
                            ] ?? null,

                        'conversion_rate' =>
                            $report['totals'][
                                $goal['conversion_metric']
                            ] ?? null,
                    ];
                }
            } catch (Throwable $exception) {
                foreach ($goalMap as $goalId => $goal) {
                    $statistics[] = [
                        'id' => $goalId,
                        'name' => $goal['name'],
                        'type' => $goal['type'],
                        'error' =>
                            $exception->getMessage(),
                    ];
                }
            }
        }

        return $statistics;
    }

    private function getGroupedDynamics(
        int $counterId,
        array $period,
        string $groupBy
    ): array {
        $from = new DateTimeImmutable(
            $period['resolved']['from'],
            $this->timezone
        );

        $to = new DateTimeImmutable(
            $period['resolved']['to'],
            $this->timezone
        );

        $rows = [];

        foreach (
            $this->buildIntervals(
                $from,
                $to,
                $groupBy
            ) as $interval
        ) {
            $summary = $this->getTrafficSummary(
                $counterId,
                $interval['from'],
                $interval['to']
            );

            $rows[] = [
                'period' => $interval,
                'metrics' => $summary['totals'],
                'metadata' => $summary['metadata'],
            ];
        }

        return [
            'requested_period' =>
                $period['requested'],

            'resolved_period' =>
                $period['resolved'],

            'group_by' => $groupBy,
            'rows' => $rows,

            'metadata' => [
                'total_rows' => count($rows),
            ],
        ];
    }

    private function buildIntervals(
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        string $groupBy
    ): array {
        $intervals = [];
        $current = $from;

        while ($current <= $to) {
            if ($groupBy === 'week') {
                $daysUntilSunday =
                    7 - (int) $current->format('N');

                $end = $current->modify(
                    '+' . $daysUntilSunday . ' days'
                );
            } else {
                $end = $current->modify(
                    'last day of this month'
                );
            }

            if ($end > $to) {
                $end = $to;
            }

            $intervals[] = [
                'from' => $current->format('Y-m-d'),
                'to' => $end->format('Y-m-d'),

                'label' =>
                    $groupBy === 'month'
                        ? $current->format('Y-m')
                        : $current->format('d.m.Y')
                            . '–'
                            . $end->format('d.m.Y'),
            ];

            $current = $end->modify('+1 day');
        }

        return $intervals;
    }

    private function resolveGroupBy(
        string $groupBy,
        int $days
    ): string {
        $groupBy = strtolower(trim($groupBy));

        if (
            !in_array(
                $groupBy,
                ['auto', 'day', 'week', 'month'],
                true
            )
        ) {
            throw new InvalidArgumentException(
                'group_by должен быть auto, '
                . 'day, week или month'
            );
        }

        if ($groupBy !== 'auto') {
            return $groupBy;
        }

        if ($days <= 62) {
            return 'day';
        }

        if ($days <= 180) {
            return 'week';
        }

        return 'month';
    }

    private function attachPeriod(
        array $report,
        array $period
    ): array {
        $report['requested_period'] =
            $period['requested'];

        $report['resolved_period'] =
            $period['resolved'];

        return $report;
    }

    private function normalizeLimit(
        int $limit
    ): int {
        return max(5, min(50, $limit));
    }

    private function resolveDate(
        string $value
    ): DateTimeImmutable {
        $value = trim($value);

        $today = new DateTimeImmutable(
            'today',
            $this->timezone
        );

        if ($value === 'today') {
            return $today;
        }

        if ($value === 'yesterday') {
            return $today->modify('-1 day');
        }

        if (
            preg_match(
                '/^(\d+)daysAgo$/',
                $value,
                $matches
            ) === 1
        ) {
            return $today->modify(
                '-' . (int) $matches[1] . ' days'
            );
        }

        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $value,
            $this->timezone
        );

        $errors = DateTimeImmutable::getLastErrors();

        if (
            $date === false
            || (
                is_array($errors)
                && (
                    $errors['warning_count'] > 0
                    || $errors['error_count'] > 0
                )
            )
        ) {
            throw new InvalidArgumentException(
                'Некорректная дата: ' . $value
            );
        }

        return $date;
    }

    private function validateCounterId(
        int $counterId
    ): void {
        if ($counterId <= 0) {
            throw new InvalidArgumentException(
                'Некорректный counter_id'
            );
        }
    }

    private function getReport(
        int $counterId,
        array $parameters,
        int $maxRows
    ): array {
        $this->validateCounterId($counterId);

        $response = $this->requestReportPages(
            $counterId,
            $parameters,
            max(1, $maxRows)
        );

        return $this->normalizeReport($response);
    }

    private function requestReportPages(
        int $counterId,
        array $parameters,
        int $maxRows
    ): array {
        $offset = 1;
        $rows = [];
        $firstResponse = null;
        $pageCount = 0;
        $totalRows = 0;

        do {
            $remaining = $maxRows - count($rows);

            if ($remaining <= 0) {
                break;
            }

            $pageParameters = $parameters;
            $pageParameters['ids'] = $counterId;
            $pageParameters['offset'] = $offset;
            $pageParameters['limit'] = min(
                $remaining,
                $this->pageSize
            );

            $response = $this->request(
                '/stat/v1/data',
                $pageParameters
            );

            if ($firstResponse === null) {
                $firstResponse = $response;
            }

            $pageRows = isset($response['data'])
                && is_array($response['data'])
                    ? $response['data']
                    : [];

            foreach ($pageRows as $row) {
                $rows[] = $row;
            }

            $received = count($pageRows);
            $offset += $received;
            $pageCount++;

            $totalRows =
                isset($response['total_rows'])
                    ? (int) $response['total_rows']
                    : count($rows);

            if ($received === 0 || $pageCount >= 100) {
                break;
            }
        } while (
            count($rows) < $totalRows
            && count($rows) < $maxRows
        );

        if ($firstResponse === null) {
            $firstResponse = [
                'query' => [],
                'data' => [],
                'totals' => [],
                'total_rows' => 0,
            ];
        }

        $firstResponse['data'] = $rows;
        $firstResponse['fetched_rows'] = count($rows);
        $firstResponse['fetched_pages'] = $pageCount;
        $firstResponse['truncated'] =
            count($rows) < $totalRows;

        return $firstResponse;
    }

    private function normalizeReport(
        array $response
    ): array {
        $query = isset($response['query'])
            && is_array($response['query'])
                ? $response['query']
                : [];

        $metricNames =
            isset($query['metrics'])
            && is_array($query['metrics'])
                ? $query['metrics']
                : [];

        $dimensionNames =
            isset($query['dimensions'])
            && is_array($query['dimensions'])
                ? $query['dimensions']
                : [];

        $rows = [];

        foreach ($response['data'] ?? [] as $row) {
            $dimensions = [];

            foreach (
                $dimensionNames
                as $index => $dimensionName
            ) {
                $value =
                    $row['dimensions'][$index]
                    ?? null;

                $dimensions[$dimensionName] =
                    is_array($value)
                        ? $value
                        : ['name' => $value];
            }

            $rows[] = [
                'dimensions' => $dimensions,

                'metrics' => $this->mapValues(
                    $metricNames,
                    isset($row['metrics'])
                    && is_array($row['metrics'])
                        ? $row['metrics']
                        : []
                ),
            ];
        }

        return [
            'period' => [
                'from' => $query['date1'] ?? null,
                'to' => $query['date2'] ?? null,
            ],

            'totals' => $this->mapValues(
                $metricNames,
                isset($response['totals'])
                && is_array($response['totals'])
                    ? $response['totals']
                    : []
            ),

            'rows' => $rows,

            'metadata' => [
                'total_rows' =>
                    $response['total_rows']
                    ?? count($rows),

                'fetched_rows' =>
                    $response['fetched_rows']
                    ?? count($rows),

                'fetched_pages' =>
                    $response['fetched_pages']
                    ?? 1,

                'truncated' =>
                    $response['truncated'] ?? false,

                'sampled' =>
                    $response['sampled'] ?? null,

                'sample_share' =>
                    $response['sample_share'] ?? null,

                'sample_size' =>
                    $response['sample_size'] ?? null,

                'sample_space' =>
                    $response['sample_space'] ?? null,

                'data_lag_seconds' =>
                    $response['data_lag'] ?? null,
            ],
        ];
    }

    private function mapValues(
        array $names,
        array $values
    ): array {
        $result = [];

        foreach ($names as $index => $name) {
            $result[$name] =
                array_key_exists($index, $values)
                    ? $values[$index]
                    : null;
        }

        return $result;
    }

    private function request(
        string $path,
        array $parameters = []
    ): array {
        $url = self::API_URL . $path;

        if ($parameters !== []) {
            $url .= '?' . http_build_query(
                $parameters,
                '',
                '&',
                PHP_QUERY_RFC3986
            );
        }

        for (
            $attempt = 1;
            $attempt <= $this->maxRetries;
            $attempt++
        ) {
            $curl = curl_init($url);

            if ($curl === false) {
                throw new RuntimeException(
                    'Не удалось инициализировать cURL'
                );
            }

            curl_setopt_array(
                $curl,
                [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPGET => true,
                    CURLOPT_ENCODING => '',

                    CURLOPT_CONNECTTIMEOUT =>
                        $this->connectTimeout,

                    CURLOPT_TIMEOUT => $this->timeout,

                    CURLOPT_HTTPHEADER => [
                        'Authorization: OAuth '
                        . $this->oauthToken,

                        'Accept: application/json',

                        'User-Agent: '
                        . 'EFFECT-Metrika-MCP/6.0',
                    ],
                ]
            );

            $body = curl_exec($curl);

            if ($body === false) {
                $error = curl_error($curl);
                curl_close($curl);

                if ($attempt < $this->maxRetries) {
                    sleep($attempt);
                    continue;
                }

                throw new RuntimeException(
                    'Ошибка cURL: ' . $error
                );
            }

            $httpCode = (int) curl_getinfo(
                $curl,
                CURLINFO_HTTP_CODE
            );

            curl_close($curl);

            try {
                $response = json_decode(
                    $body,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
            } catch (JsonException $exception) {
                throw new RuntimeException(
                    'Метрика вернула некорректный JSON',
                    0,
                    $exception
                );
            }

            if (
                $httpCode >= 200
                && $httpCode < 300
            ) {
                return is_array($response)
                    ? $response
                    : [];
            }

            $message =
                $response['message']
                ?? $response['errors'][0]['message']
                ?? 'Неизвестная ошибка Метрики';

            if ($httpCode === 401) {
                throw self::accessDenied(
                    'OAuth-токен Метрики '
                    . 'недействителен или отозван'
                );
            }

            if (
                $httpCode === 403
                || $httpCode === 404
            ) {
                throw self::accessDenied(
                    'Счётчик не найден или '
                    . 'OAuth-токен не имеет доступа'
                );
            }

            if (
                ($httpCode === 429 || $httpCode >= 500)
                && $attempt < $this->maxRetries
            ) {
                sleep($attempt * 2);
                continue;
            }

            throw new RuntimeException(
                'Ошибка Метрики HTTP '
                . $httpCode
                . ': '
                . $message
            );
        }

        throw new RuntimeException(
            'Неизвестная ошибка Метрики'
        );
    }
}