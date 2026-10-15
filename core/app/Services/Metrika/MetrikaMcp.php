<?php

declare(strict_types=1);

namespace App\Services\Metrika;

use ApiKit\Mcp\McpService;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * MCP-сервер Яндекс Метрики: POST /api/mcp/metrika
 */
final class MetrikaMcp extends McpService
{
    private const MULTIPLE_COUNTERS = 4091;

    /** @var MetrikaClient */
    private $metrika;

    /** @var MetrikaResolver */
    private $resolver;

    public function __construct(array $config)
    {
        parent::__construct($config);

        $settings = $this->settings('metrika');
        $resolver = isset($settings['resolver']) && is_array($settings['resolver'])
            ? $settings['resolver']
            : [];

        $this->metrika = new MetrikaClient(
            (string) ($settings['oauth_token'] ?? ''),
            $this->timezone(),
            (int) ($settings['page_size'] ?? 10000),
            (int) ($settings['connect_timeout'] ?? 10),
            (int) ($settings['timeout'] ?? 90),
            (int) ($settings['max_retries'] ?? 3)
        );

        $this->resolver = new MetrikaResolver(
            (int) ($resolver['connect_timeout'] ?? 5),
            (int) ($resolver['timeout'] ?? 15),
            (int) ($resolver['max_redirects'] ?? 5),
            (int) ($resolver['max_bytes'] ?? 2097152)
        );
    }

    public function info(): array
    {
        return [
            'name' => 'effect-yandex-metrika',
            'version' => '6.0.0',
            'protocolVersion' => '2025-06-18',

            'instructions' =>
                'Пользователь может указывать '
                . 'URL сайта или counter_id. '
                . 'Периоды, заданные по-русски, '
                . 'необходимо преобразовать '
                . 'в даты YYYY-MM-DD.',
        ];
    }

    public function tools(): array
    {
        return [
            $this->resolveTool(),

            $this->analyticsTool(
                'check_counter_access',
                'Проверить доступ к счётчику.',
                false,
                false
            ),

            $this->analyticsTool(
                'get_traffic_summary',
                'Получить общие показатели сайта.',
                false,
                false
            ),

            $this->analyticsTool(
                'get_traffic_dynamics',
                'Получить динамику по дням, '
                . 'неделям или месяцам.',
                false,
                true
            ),

            $this->analyticsTool(
                'get_traffic_sources',
                'Получить источники трафика.',
                true,
                false
            ),

            $this->analyticsTool(
                'get_landing_pages',
                'Получить страницы входа.',
                true,
                false
            ),

            $this->analyticsTool(
                'get_pageviews',
                'Получить просмотры всех страниц и URL сайта, '
                . 'а не только страницы входа. '
                . 'Используй, когда пользователь спрашивает, '
                . 'какие страницы чаще всего просматривают, '
                . 'какой контент популярен, какие URL имеют мало просмотров '
                . 'или просит отчёт по просмотрам страниц.',
                true,
                false
            ),

            $this->technologyTool(),

            $this->crossSegmentTool(),

            $this->analyticsTool(
                'get_goals',
                'Получить цели и общие конверсии.',
                false,
                false
            ),

            $this->goalConversionsTool(),

            $this->fullAnalyticsTool(),

            $this->compareTool(),
        ];
    }

    protected function call(string $name, array $arguments): array
    {
        if ($name === 'resolve_counter_by_url') {
            return $this->resolveUrl(
                (string) (
                    $arguments['site_url'] ?? ''
                )
            );
        }

        $subject = $this->resolveSubject(
            $arguments
        );

        $counterId =
            (int) $subject['counter_id'];

        $dateFrom = (string) (
            $arguments['date_from']
            ?? '30daysAgo'
        );

        $dateTo = (string) (
            $arguments['date_to']
            ?? 'yesterday'
        );

        $topLimit = max(
            5,
            min(
                50,
                (int) (
                    $arguments['top_limit']
                    ?? 20
                )
            )
        );

        $groupBy = (string) (
            $arguments['group_by']
            ?? 'auto'
        );

        $goalId = isset($arguments['goal_id'])
            ? (int) $arguments['goal_id']
            : 0;

        switch ($name) {
            case 'check_counter_access':
                $result = $subject;
                break;

            case 'get_traffic_summary':
                $result =
                    $this->metrika
                        ->getTrafficSummary(
                            $counterId,
                            $dateFrom,
                            $dateTo
                        );
                break;

            case 'get_traffic_dynamics':
                $result =
                    $this->metrika
                        ->getTrafficDynamics(
                            $counterId,
                            $dateFrom,
                            $dateTo,
                            $groupBy
                        );
                break;

            case 'get_traffic_sources':
                $result =
                    $this->metrika
                        ->getTrafficSources(
                            $counterId,
                            $dateFrom,
                            $dateTo,
                            $topLimit
                        );
                break;

            case 'get_landing_pages':
                $result =
                    $this->metrika
                        ->getLandingPages(
                            $counterId,
                            $dateFrom,
                            $dateTo,
                            $topLimit
                        );
                break;

            case 'get_pageviews':
                $result =
                    $this->metrika
                        ->getPageviews(
                            $counterId,
                            $dateFrom,
                            $dateTo,
                            $topLimit
                        );
                break;

            case 'get_technology_report':
                $result =
                    $this->metrika
                        ->getTechnologyReport(
                            $counterId,
                            $dateFrom,
                            $dateTo,
                            (string) (
                                $arguments['technology']
                                ?? ''
                            ),
                            $topLimit
                        );
                break;

            case 'get_cross_segment':
                $result =
                    $this->metrika
                        ->getCrossSegment(
                            $counterId,
                            $dateFrom,
                            $dateTo,
                            (string) (
                                $arguments['cross_type']
                                ?? ''
                            ),
                            $topLimit
                        );
                break;

            case 'get_goals':
                $result =
                    $this->metrika
                        ->getGoalsReport(
                            $counterId,
                            $dateFrom,
                            $dateTo
                        );
                break;

            case 'get_goal_conversions':
                $result =
                    $this->metrika
                        ->getGoalConversions(
                            $counterId,
                            $dateFrom,
                            $dateTo,
                            $goalId,

                            (string) (
                                $arguments['breakdown']
                                ?? ''
                            ),

                            $topLimit
                        );
                break;

            case 'get_full_site_analytics':
                $result =
                    $this->metrika
                        ->getFullSiteAnalytics(
                            $counterId,
                            $dateFrom,
                            $dateTo,
                            $topLimit,
                            $groupBy,
                            $goalId
                        );
                break;

            case 'compare_periods':
                $result =
                    $this->metrika
                        ->comparePeriods(
                            $counterId,

                            (string) (
                                $arguments[
                                    'period_a_from'
                                ] ?? ''
                            ),

                            (string) (
                                $arguments[
                                    'period_a_to'
                                ] ?? ''
                            ),

                            (string) (
                                $arguments[
                                    'period_b_from'
                                ] ?? ''
                            ),

                            (string) (
                                $arguments[
                                    'period_b_to'
                                ] ?? ''
                            ),

                            $topLimit,
                            $groupBy,
                            $goalId
                        );
                break;

            default:
                throw new InvalidArgumentException(
                    'Неизвестный инструмент: '
                    . $name
                );
        }

        return [
            'subject' => $subject,
            'result' => $result,
        ];
    }

    protected function errorCode(Throwable $exception): string
    {
        switch ($exception->getCode()) {
            case MetrikaResolver::UNSAFE_SITE_URL:
                return 'UNSAFE_SITE_URL';

            case MetrikaResolver::COUNTER_NOT_FOUND:
                return 'METRIKA_COUNTER_NOT_FOUND';

            case self::MULTIPLE_COUNTERS:
                return 'MULTIPLE_METRIKA_COUNTERS';

            case MetrikaClient::ACCESS_DENIED:
                return 'METRIKA_ACCESS_DENIED';

            default:
                return 'METRIKA_REQUEST_ERROR';
        }
    }

    private static function multipleCounters(string $message): RuntimeException
    {
        return new RuntimeException($message, self::MULTIPLE_COUNTERS);
    }

    private function resolveTool(): array
    {
        return $this->tool(
            'resolve_counter_by_url',
            'Найти счётчик Метрики по URL сайта.',
            [
                'site_url' => [
                    'type' => 'string',
                ],
            ],
            ['site_url']
        );
    }

    private function analyticsTool(
        string $name,
        string $description,
        bool $withLimit,
        bool $withGroup
    ): array {
        $properties = $this->subjectProperties()
            + $this->periodProperties();

        if ($withLimit) {
            $properties['top_limit'] =
                $this->limitProperty();
        }

        if ($withGroup) {
            $properties['group_by'] =
                $this->groupProperty();
        }

        return $this->tool(
            $name,
            $description,
            $properties
        );
    }

    private function technologyTool(): array
    {
        $properties = $this->subjectProperties()
            + $this->periodProperties();

        $properties['technology'] = [
            'type' => 'string',
            'enum' => [
                'browser',
                'operating_system',
            ],
        ];

        $properties['top_limit'] =
            $this->limitProperty();

        return $this->tool(
            'get_technology_report',

            'Получить показатели сайта по браузерам '
            . 'или операционным системам. '
            . 'Используй для поиска технических проблем: '
            . 'высоких отказов, низкой глубины, короткого времени '
            . 'или слабой конверсии в отдельных браузерах и ОС.',

            $properties,
            ['technology']
        );
    }

    private function crossSegmentTool(): array
    {
        $properties = $this->subjectProperties()
            + $this->periodProperties();

        $properties['cross_type'] = [
            'type' => 'string',
            'enum' => [
                'source_landing',
                'device_landing',
                'source_device',
                'browser_device',
            ],
        ];

        $properties['top_limit'] =
            $this->limitProperty();

        return $this->tool(
            'get_cross_segment',
            'Получить перекрёстный анализ сегментов: '
            . 'источник трафика и посадочная страница; '
            . 'устройство и посадочная страница; '
            . 'источник и устройство; браузер и устройство. '
            . 'Используй, когда пользователь спрашивает, '
            . 'какие страницы эффективны для конкретных источников, '
            . 'какие страницы плохо работают на мобильных устройствах '
            . 'или какой канал лучше работает на разных устройствах.',
            $properties,
            ['cross_type']
        );
    }

    private function goalConversionsTool(): array
    {
        $properties = $this->subjectProperties()
            + $this->periodProperties();

        $properties['goal_id'] = [
            'type' => 'integer',
            'minimum' => 1,
        ];

        $properties['breakdown'] = [
            'type' => 'string',
            'enum' => [
                'source',
                'landing_page',
                'device',
                'browser',
                'operating_system',
                'country',
                'region',
            ],
        ];

        $properties['top_limit'] =
            $this->limitProperty();

        return $this->tool(
            'get_goal_conversions',
            'Получить конверсии, достижения цели и целевые действия '
            . 'в разрезе источников трафика, страниц входа, устройств, '
            . 'браузеров, операционных систем или регионов. '
            . 'Используй этот инструмент, когда пользователь спрашивает: '
            . 'какие источники приносят заявки; какие страницы лучше '
            . 'конвертируют; где больше целевых действий; '
            . 'чем отличается конверсия мобильных устройств и компьютеров. '
            . 'Сначала определи goal_id через инструмент get_goals. '
            . 'Если нельзя однозначно выбрать бизнес-цель, '
            . 'покажи пользователю список целей и попроси уточнить.',
            $properties,
            [
                'goal_id',
                'breakdown',
            ]
        );
    }

    private function fullAnalyticsTool(): array
    {
        $properties = $this->subjectProperties()
            + $this->periodProperties();

        $properties['top_limit'] =
            $this->limitProperty();

        $properties['group_by'] =
            $this->groupProperty();

        $properties['goal_id'] = [
            'type' => 'integer',
            'minimum' => 1,

            'description' =>
                'Необязательный ID цели. '
                . 'Если передан, полный отчёт '
                . 'добавит конверсии по источникам, '
                . 'страницам и устройствам.',
        ];

        return $this->tool(
            'get_full_site_analytics',
            'Получить комплексную аналитику сайта за один период: '
            . 'посещаемость, динамику, источники трафика, '
            . 'страницы входа и выхода, просмотры URL, устройства, '
            . 'браузеры, операционные системы, географию, '
            . 'новых и вернувшихся посетителей, '
            . 'перекрёстные сегменты, цели и общие конверсии. '
            . 'Используй этот инструмент, когда пользователь просит '
            . 'полный анализ сайта, аудит посещаемости, '
            . 'анализ за месяц, квартал или год. '
            . 'Преобразуй период, указанный по-русски, '
            . 'в точные даты YYYY-MM-DD. '
            . 'Для периода до 62 дней используй группировку day, '
            . 'от 63 до 180 дней — week, более 180 дней — month.',
            $properties
        );
    }

    private function compareTool(): array
    {
        $properties = $this->subjectProperties();

        $properties += [
            'period_a_from' => [
                'type' => 'string',
            ],
            'period_a_to' => [
                'type' => 'string',
            ],
            'period_b_from' => [
                'type' => 'string',
            ],
            'period_b_to' => [
                'type' => 'string',
            ],
            'top_limit' => $this->limitProperty(),
            'group_by' => $this->groupProperty(),
            'goal_id' => [
                'type' => 'integer',
                'minimum' => 1,
            ],
        ];

        return $this->tool(
            'compare_periods',

            'Сравнить показатели сайта за два периода: '
            . 'посещаемость, поведение, источники трафика, страницы, '
            . 'устройства, цели и конверсии. '
            . 'Используй этот инструмент, когда пользователь просит '
            . 'сравнить месяцы, кварталы, годы, текущий период с прошлым, '
            . 'последние 30 дней с предыдущими 30 днями '
            . 'или показатели год к году. '
            . 'Преобразуй русские названия периодов в точные даты. '
            . 'Если текущий месяц не завершён, сравни период '
            . 'с первого числа по вчерашний день с таким же количеством '
            . 'дней предыдущего месяца. '
            . 'Если сравниваются завершённые месяцы или кварталы, '
            . 'используй полные календарные периоды. '
            . 'В итоговом ответе обязательно укажи фактические даты '
            . 'обоих сравниваемых периодов.',

            $properties,

            [
                'period_a_from',
                'period_a_to',
                'period_b_from',
                'period_b_to',
            ]
        );
    }

    private function subjectProperties(): array
    {
        return [
            'counter_id' => [
                'type' => 'integer',
                'minimum' => 1,

                'description' =>
                    'ID счётчика. Передай counter_id '
                    . 'или site_url.',
            ],

            'site_url' => [
                'type' => 'string',

                'description' =>
                    'URL сайта. Передай site_url '
                    . 'или counter_id.',
            ],
        ];
    }

    private function periodProperties(): array
    {
        return [
            'date_from' => [
                'type' => 'string',
                'default' => '30daysAgo',

                'description' =>
                    'Начало периода. Преобразуй '
                    . 'русское описание периода '
                    . 'в YYYY-MM-DD.',
            ],

            'date_to' => [
                'type' => 'string',
                'default' => 'yesterday',

                'description' =>
                    'Конец периода YYYY-MM-DD. '
                    . 'Для завершённого периода '
                    . 'обычно используй вчера.',
            ],
        ];
    }

    private function limitProperty(): array
    {
        return [
            'type' => 'integer',
            'minimum' => 5,
            'maximum' => 50,
            'default' => 20,
        ];
    }

    private function groupProperty(): array
    {
        return [
            'type' => 'string',
            'enum' => [
                'auto',
                'day',
                'week',
                'month',
            ],
            'default' => 'auto',
        ];
    }

    private function resolveSubject(
        array $arguments
    ): array {
        $counterId =
            isset($arguments['counter_id'])
                ? (int) $arguments['counter_id']
                : 0;

        $siteUrl = isset($arguments['site_url'])
            ? trim(
                (string) $arguments['site_url']
            )
            : '';

        if ($counterId <= 0 && $siteUrl === '') {
            throw new InvalidArgumentException(
                'Передайте counter_id или site_url'
            );
        }

        if ($siteUrl === '') {
            return [
                'counter_id' => $counterId,

                'counter' =>
                    $this->metrika
                        ->checkCounterAccess(
                            $counterId
                        ),

                'resolution' => null,
            ];
        }

        $resolution = $this->resolveUrl(
            $siteUrl
        );

        if ($counterId > 0) {
            if (
                !in_array(
                    $counterId,
                    $resolution[
                        'detected_counter_ids'
                    ],
                    true
                )
            ) {
                throw new RuntimeException(
                    'Указанный counter_id '
                    . 'не найден на странице'
                );
            }

            return [
                'counter_id' => $counterId,

                'counter' =>
                    $this->metrika
                        ->checkCounterAccess(
                            $counterId
                        ),

                'resolution' => $resolution,
            ];
        }

        $accessible =
            $resolution['accessible_counters'];

        if ($accessible === []) {
            throw MetrikaClient::accessDenied(
                'Найденные на сайте счётчики '
                . 'недоступны OAuth-токену'
            );
        }

        if (count($accessible) > 1) {
            throw self::multipleCounters(
                'Найдено несколько доступных '
                . 'счётчиков: '
                . implode(
                    ', ',
                    array_column(
                        $accessible,
                        'counter_id'
                    )
                )
                . '. Укажите counter_id.'
            );
        }

        return [
            'counter_id' =>
                $accessible[0]['counter_id'],

            'counter' => $accessible[0],
            'resolution' => $resolution,
        ];
    }

    private function resolveUrl(
        string $siteUrl
    ): array {
        $resolved = $this->resolver->resolve(
            $siteUrl
        );

        $accessible = [];
        $inaccessible = [];

        foreach (
            $resolved['detected_counter_ids']
            as $counterId
        ) {
            try {
                $accessible[] =
                    $this->metrika
                        ->checkCounterAccess(
                            (int) $counterId
                        );
            } catch (RuntimeException $exception) {
                if ($exception->getCode() !== MetrikaClient::ACCESS_DENIED) {
                    throw $exception;
                }

                $inaccessible[] =
                    (int) $counterId;
            }
        }

        return [
            'requested_url' =>
                $resolved['requested_url'],

            'final_url' =>
                $resolved['final_url'],

            'detected_counter_ids' =>
                $resolved[
                    'detected_counter_ids'
                ],

            'accessible_counters' =>
                $accessible,

            'inaccessible_counter_ids' =>
                $inaccessible,
        ];
    }
}
