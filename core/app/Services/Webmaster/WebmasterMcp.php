<?php

declare(strict_types=1);

namespace App\Services\Webmaster;

use ApiKit\Mcp\McpService;
use InvalidArgumentException;
use Throwable;

/**
 * MCP-сервер Яндекс Вебмастера: POST /api/mcp/webmaster
 */
final class WebmasterMcp extends McpService
{
    /** @var WebmasterClient */
    private $webmaster;

    /** @var WebmasterResolver */
    private $resolver;

    public function __construct(array $config)
    {
        parent::__construct($config);

        $settings = $this->settings('webmaster');

        $this->webmaster = new WebmasterClient(
            (string) ($settings['oauth_token'] ?? ''),
            $this->timezone(),
            (int) ($settings['connect_timeout'] ?? 10),
            (int) ($settings['timeout'] ?? 90),
            (int) ($settings['max_retries'] ?? 3)
        );

        $this->resolver = new WebmasterResolver($this->webmaster);
    }

    public function info(): array
    {
        return [
            'name' => 'effect-yandex-webmaster',
            'version' => '1.0.0',
            'protocolVersion' => '2025-03-26',
            'instructions' => 'Read-only анализ Яндекс Вебмастера. Пользователь может указывать URL сайта или host_id. Преобразуй русские периоды в YYYY-MM-DD. Для полного SEO-аудита используй get_full_webmaster_analysis. Для оценки роста запросов используй get_search_opportunities. Не утверждай, что показатели Метрики доступны: этот сервер работает только с Вебмастером.',
        ];
    }

    public function tools(): array
    {
        return [
            $this->tool('list_sites', 'Получить все сайты, доступные OAuth-токену. Используй, когда пользователь просит список сайтов или URL не указан.', ['limit' => $this->limit(1, 5000, 500)], []),
            $this->tool('resolve_host_by_url', 'Найти host_id Яндекс Вебмастера по URL сайта.', ['site_url' => ['type' => 'string']], ['site_url']),
            $this->siteTool('check_site_access', 'Проверить доступ к сайту и вернуть его сведения.'),
            $this->siteTool('get_site_summary', 'Получить сводку Вебмастера: ИКС, индексирование и проблемы.'),
            $this->siteTool('get_verification_info', 'Получить состояние подтверждения прав на сайт.'),
            $this->siteTool('get_site_owners', 'Получить список пользователей, подтвердивших права.'),
            $this->siteTool('get_site_diagnostics', 'Получить диагностику и обнаруженные Яндексом проблемы сайта.'),
            $this->periodTool('get_search_dynamics', 'Получить динамику показов, кликов и средних позиций по всем запросам.', false, true),
            $this->popularQueriesTool(),
            $this->queryHistoryTool(),
            $this->queryAnalyticsTool(),
            $this->periodTool('get_indexing_history', 'Получить динамику загруженных страниц, HTTP-кодов, ошибок загрузки и исключений.'),
            $this->sampleTool('get_indexing_samples', 'Получить примеры загруженных роботом URL с результатами обхода.'),
            $this->periodTool('get_pages_in_search_history', 'Получить динамику количества страниц в поиске.'),
            $this->sampleTool('get_pages_in_search', 'Получить примеры URL, участвующих в поиске Яндекса.'),
            $this->periodTool('get_search_events_history', 'Получить динамику появления и исключения страниц из поиска.'),
            $this->sampleTool('get_search_events', 'Получить URL, появившиеся или исключённые из поиска, и причины исключения.'),
            $this->sampleTool('get_important_urls', 'Получить важные страницы и их проблемы.'),
            $this->importantHistoryTool(),
            $this->siteTool('get_sitemaps', 'Получить все Sitemap, обнаруженные Яндексом, их статусы и ошибки.'),
            $this->sitemapTool(),
            $this->siteTool('get_user_sitemaps', 'Получить Sitemap, добавленные пользователем. Только чтение.'),
            $this->sampleTool('get_broken_internal_links', 'Получить примеры неработающих внутренних ссылок.'),
            $this->periodTool('get_broken_internal_links_history', 'Получить динамику количества неработающих внутренних ссылок.'),
            $this->sampleTool('get_external_links', 'Получить примеры внешних ссылок на сайт.'),
            $this->periodTool('get_external_links_history', 'Получить динамику внешних ссылок.'),
            $this->periodTool('get_sqi_history', 'Получить историю изменения ИКС.'),
            $this->siteTool('get_feeds', 'Получить загруженные YML-фиды. Только чтение.'),
            $this->siteTool('get_recrawl_quota', 'Проверить суточную квоту переобхода без отправки URL.'),
            $this->siteTool('get_recrawl_tasks', 'Получить очередь задач переобхода. Только чтение.'),
            $this->sampleTool('get_original_texts', 'Получить список оригинальных текстов. Только чтение.'),
            $this->siteTool('get_original_text_quota', 'Получить квоту оригинальных текстов.'),
            $this->opportunitiesTool(),
            $this->fullAnalysisTool(),
            $this->compareTool(),
        ];
    }

    protected function call(string $name, array $arguments): array
    {
        $a = $arguments;

        if ($name === 'list_sites') {
            return $this->webmaster->listHosts((int) ($a['limit'] ?? 500));
        }

        if ($name === 'resolve_host_by_url') {
            return $this->resolver->resolve((string) ($a['site_url'] ?? ''));
        }

        $subject = $this->resolveSubject($a);
        $hostId = (string) $subject['host_id'];
        $from = (string) ($a['date_from'] ?? '30daysAgo');
        $to = (string) ($a['date_to'] ?? 'yesterday');
        $limit = max(1, min(500, (int) ($a['limit'] ?? 100)));
        $offset = max(0, (int) ($a['offset'] ?? 0));
        $device = (string) ($a['device_type'] ?? 'ALL');

        switch ($name) {
            case 'check_site_access': $result = $subject; break;
            case 'get_site_summary': $result = $this->webmaster->getHostSummary($hostId); break;
            case 'get_verification_info': $result = $this->webmaster->getVerification($hostId); break;
            case 'get_site_owners': $result = $this->webmaster->getOwners($hostId); break;
            case 'get_site_diagnostics': $result = $this->webmaster->getDiagnostics($hostId); break;
            case 'get_search_dynamics': $result = $this->webmaster->getSearchDynamics($hostId, $from, $to, $device); break;
            case 'get_popular_queries': $result = $this->webmaster->getPopularQueries($hostId, $from, $to, (string) ($a['order_by'] ?? 'TOTAL_SHOWS'), $device, $limit, $offset); break;
            case 'get_query_history': $result = $this->webmaster->getQueryHistory($hostId, (string) ($a['query_id'] ?? ''), $from, $to, $device); break;
            case 'get_query_analytics': $result = $this->webmaster->getQueryAnalytics($hostId, $a); break;
            case 'get_indexing_history': $result = $this->webmaster->getIndexingHistory($hostId, $from, $to); break;
            case 'get_indexing_samples': $result = $this->webmaster->getIndexingSamples($hostId, $limit, $offset); break;
            case 'get_pages_in_search_history': $result = $this->webmaster->getPagesInSearchHistory($hostId, $from, $to); break;
            case 'get_pages_in_search': $result = $this->webmaster->getPagesInSearch($hostId, $limit, $offset); break;
            case 'get_search_events_history': $result = $this->webmaster->getSearchEventsHistory($hostId, $from, $to); break;
            case 'get_search_events': $result = $this->webmaster->getSearchEvents($hostId, $limit, $offset); break;
            case 'get_important_urls': $result = $this->webmaster->getImportantUrls($hostId, $limit, $offset); break;
            case 'get_important_url_history': $result = $this->webmaster->getImportantUrlHistory($hostId, (string) ($a['url'] ?? '')); break;
            case 'get_sitemaps': $result = $this->webmaster->getSitemaps($hostId); break;
            case 'get_sitemap_info': $result = $this->webmaster->getSitemap($hostId, (string) ($a['sitemap_id'] ?? '')); break;
            case 'get_user_sitemaps': $result = $this->webmaster->getUserSitemaps($hostId, $limit); break;
            case 'get_broken_internal_links': $result = $this->webmaster->getBrokenInternalLinks($hostId, $limit, $offset); break;
            case 'get_broken_internal_links_history': $result = $this->webmaster->getBrokenInternalLinksHistory($hostId, $from, $to); break;
            case 'get_external_links': $result = $this->webmaster->getExternalLinks($hostId, $limit, $offset); break;
            case 'get_external_links_history': $result = $this->webmaster->getExternalLinksHistory($hostId, $from, $to); break;
            case 'get_sqi_history': $result = $this->webmaster->getSqiHistory($hostId, $from, $to); break;
            case 'get_feeds': $result = $this->webmaster->getFeeds($hostId); break;
            case 'get_recrawl_quota': $result = $this->webmaster->getRecrawlQuota($hostId); break;
            case 'get_recrawl_tasks': $result = $this->webmaster->getRecrawlTasks($hostId); break;
            case 'get_original_texts': $result = $this->webmaster->getOriginalTexts($hostId, $limit, $offset); break;
            case 'get_original_text_quota': $result = $this->webmaster->getOriginalTextQuota($hostId); break;
            case 'get_search_opportunities':
                $popular = $this->webmaster->getPopularQueries($hostId, $from, $to, 'TOTAL_SHOWS', $device, $limit, 0);
                $result = ['source' => $popular, 'opportunities' => $this->webmaster->deriveQueryOpportunities($popular, $limit)];
                break;
            case 'get_full_webmaster_analysis': $result = $this->webmaster->getFullSiteAnalysis($hostId, $from, $to, $limit, $device); break;
            case 'compare_periods': $result = $this->webmaster->comparePeriods($hostId, (string) ($a['period_a_from'] ?? ''), (string) ($a['period_a_to'] ?? ''), (string) ($a['period_b_from'] ?? ''), (string) ($a['period_b_to'] ?? ''), $limit, $device); break;
            default: throw new InvalidArgumentException('Неизвестный инструмент: ' . $name);
        }

        return ['subject' => $subject, 'result' => $result];
    }

    protected function errorCode(Throwable $exception): string
    {
        if ($exception instanceof InvalidArgumentException) {
            return 'INVALID_ARGUMENT';
        }

        switch ($exception->getCode()) {
            case WebmasterResolver::HOST_NOT_FOUND:
                return 'WEBMASTER_HOST_NOT_FOUND';

            case WebmasterResolver::MULTIPLE_HOSTS:
                return 'MULTIPLE_WEBMASTER_HOSTS';

            case WebmasterClient::ACCESS_DENIED:
                return 'WEBMASTER_ACCESS_DENIED';

            default:
                return 'WEBMASTER_REQUEST_ERROR';
        }
    }

    private function siteTool(string $name, string $description): array
    {
        return $this->tool($name, $description, $this->subjectProperties(), []);
    }

    private function periodTool(string $name, string $description, bool $withLimit = false, bool $withDevice = false): array
    {
        $properties = $this->subjectProperties() + $this->periodProperties();
        if ($withLimit) { $properties['limit'] = $this->limit(); }
        if ($withDevice) { $properties['device_type'] = $this->deviceProperty(); }
        return $this->tool($name, $description, $properties, []);
    }

    private function sampleTool(string $name, string $description): array
    {
        $properties = $this->subjectProperties();
        $properties['limit'] = $this->limit(1, 500, 100);
        $properties['offset'] = ['type' => 'integer', 'minimum' => 0, 'default' => 0];
        return $this->tool($name, $description, $properties, []);
    }

    private function popularQueriesTool(): array
    {
        $properties = $this->subjectProperties() + $this->periodProperties();
        $properties['order_by'] = ['type' => 'string', 'enum' => ['TOTAL_SHOWS', 'TOTAL_CLICKS'], 'default' => 'TOTAL_SHOWS'];
        $properties['device_type'] = $this->deviceProperty();
        $properties['limit'] = $this->limit(1, 500, 100);
        $properties['offset'] = ['type' => 'integer', 'minimum' => 0, 'default' => 0];
        return $this->tool('get_popular_queries', 'Получить популярные запросы с показами, кликами и средними позициями. Используй для анализа видимости, CTR и точек роста.', $properties, []);
    }

    private function queryHistoryTool(): array
    {
        $properties = $this->subjectProperties() + $this->periodProperties();
        $properties['query_id'] = ['type' => 'string'];
        $properties['device_type'] = $this->deviceProperty();
        return $this->tool('get_query_history', 'Получить историю показателей конкретного поискового запроса. query_id сначала возьми из get_popular_queries.', $properties, ['query_id']);
    }

    private function queryAnalyticsTool(): array
    {
        $properties = $this->subjectProperties() + $this->periodProperties('14daysAgo');
        $properties += [
            'device_type_indicator' => ['type' => 'string'],
            'text_indicator' => ['type' => 'string'],
            'region_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
            'filters' => ['type' => 'object', 'additionalProperties' => true],
            'sort_by_date' => ['type' => 'string'],
            'limit' => $this->limit(1, 500, 100),
            'offset' => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
        ];
        return $this->tool('get_query_analytics', 'Расширенная read-only аналитика сочетаний запросов и URL с фильтрами. Данные доступны за последние две недели.', $properties, []);
    }

    private function importantHistoryTool(): array
    {
        $properties = $this->subjectProperties();
        $properties['url'] = ['type' => 'string'];
        return $this->tool('get_important_url_history', 'Получить историю изменений конкретной важной страницы: индексирование, HTTP-код, Title и Description.', $properties, ['url']);
    }

    private function sitemapTool(): array
    {
        $properties = $this->subjectProperties();
        $properties['sitemap_id'] = ['type' => 'string'];
        return $this->tool('get_sitemap_info', 'Получить подробности Sitemap, количество URL и ошибки.', $properties, ['sitemap_id']);
    }

    private function opportunitiesTool(): array
    {
        $properties = $this->subjectProperties() + $this->periodProperties();
        $properties['device_type'] = $this->deviceProperty();
        $properties['limit'] = $this->limit(5, 500, 100);
        return $this->tool('get_search_opportunities', 'Найти точки роста: запросы на позициях 4–20 и запросы с показами, но CTR ниже 2%. Пороговая классификация выполняется сервером, вывод интерпретируй с учётом бренда и интента.', $properties, []);
    }

    private function fullAnalysisTool(): array
    {
        $properties = $this->subjectProperties() + $this->periodProperties();
        $properties['device_type'] = $this->deviceProperty();
        $properties['limit'] = $this->limit(5, 500, 100);
        return $this->tool('get_full_webmaster_analysis', 'Получить максимально полный read-only SEO-анализ сайта: сведения, сводку, диагностику, поисковую динамику, популярные запросы, точки роста, индексирование, страницы в поиске, исключения, Sitemap, внутренние и внешние ссылки, важные URL, ИКС, фиды и квоты. Используй при просьбе провести полный аудит или оценить состояние сайта.', $properties, []);
    }

    private function compareTool(): array
    {
        $properties = $this->subjectProperties() + [
            'period_a_from' => ['type' => 'string'], 'period_a_to' => ['type' => 'string'],
            'period_b_from' => ['type' => 'string'], 'period_b_to' => ['type' => 'string'],
            'device_type' => $this->deviceProperty(), 'limit' => $this->limit(5, 500, 100),
        ];
        return $this->tool('compare_periods', 'Сравнить поисковые показы, клики, позиции и популярные запросы за два периода. В ответе менеджеру обязательно укажи фактические даты обоих периодов.', $properties, ['period_a_from', 'period_a_to', 'period_b_from', 'period_b_to']);
    }

    private function subjectProperties(): array
    {
        return [
            'host_id' => ['type' => 'string', 'description' => 'ID сайта вида https:example.com:443. Передай host_id или site_url.'],
            'site_url' => ['type' => 'string', 'description' => 'URL сайта. Передай site_url или host_id.'],
        ];
    }

    private function periodProperties(string $defaultFrom = '30daysAgo'): array
    {
        return [
            'date_from' => ['type' => 'string', 'default' => $defaultFrom, 'description' => 'Начало периода YYYY-MM-DD. Преобразуй русское описание периода.'],
            'date_to' => ['type' => 'string', 'default' => 'yesterday', 'description' => 'Конец периода YYYY-MM-DD.'],
        ];
    }

    private function limit(int $min = 5, int $max = 500, int $default = 100): array
    {
        return ['type' => 'integer', 'minimum' => $min, 'maximum' => $max, 'default' => $default];
    }

    private function deviceProperty(): array
    {
        return ['type' => 'string', 'enum' => ['ALL', 'DESKTOP', 'MOBILE_AND_TABLET', 'MOBILE', 'TABLET'], 'default' => 'ALL'];
    }

    private function resolveSubject(array $arguments): array
    {
        $hostId = trim((string) ($arguments['host_id'] ?? ''));
        $siteUrl = trim((string) ($arguments['site_url'] ?? ''));
        if ($hostId === '' && $siteUrl === '') { throw new InvalidArgumentException('Передайте host_id или site_url'); }
        if ($hostId !== '' && $siteUrl === '') { return $this->resolver->resolveByHostId($hostId); }
        $resolved = $this->resolver->resolve($siteUrl);
        if ($hostId !== '' && $resolved['host_id'] !== $hostId) { throw new InvalidArgumentException('Указанный host_id не соответствует site_url'); }
        return $resolved;
    }
}
