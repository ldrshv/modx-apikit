<?php

declare(strict_types=1);

namespace App\Services\Webmaster;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Throwable;

final class WebmasterClient
{
    /** Код исключения: ошибка доступа (HTTP 401/403/404). */
    public const ACCESS_DENIED = 4031;

    public static function accessDenied(string $message): RuntimeException
    {
        return new RuntimeException($message, self::ACCESS_DENIED);
    }

    private const API_URL = 'https://api.webmaster.yandex.net/v4';
    private const QUERY_INDICATORS = ['TOTAL_SHOWS', 'TOTAL_CLICKS', 'AVG_SHOW_POSITION', 'AVG_CLICK_POSITION'];
    private const DEVICE_TYPES = ['ALL', 'DESKTOP', 'MOBILE_AND_TABLET', 'MOBILE', 'TABLET'];

    /** @var string */ private $oauthToken;
    /** @var DateTimeZone */ private $timezone;
    /** @var int */ private $connectTimeout;
    /** @var int */ private $timeout;
    /** @var int */ private $maxRetries;
    /** @var int|null */ private $userId;

    public function __construct(
        string $oauthToken,
        string $timezone = 'Europe/Moscow',
        int $connectTimeout = 10,
        int $timeout = 90,
        int $maxRetries = 3
    ) {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('Расширение PHP cURL не установлено');
        }
        $oauthToken = trim($oauthToken);
        if ($oauthToken === '') {
            throw new InvalidArgumentException('Не указан OAuth-токен Яндекс Вебмастера');
        }
        $this->oauthToken = $oauthToken;
        $this->timezone = new DateTimeZone($timezone);
        $this->connectTimeout = max(1, $connectTimeout);
        $this->timeout = max(1, $timeout);
        $this->maxRetries = max(1, $maxRetries);
        $this->userId = null;
    }

    public function getUser(): array
    {
        $response = $this->request('GET', '/user/');
        if (isset($response['user_id'])) {
            $this->userId = (int) $response['user_id'];
        }
        return $response;
    }

    public function getUserId(): int
    {
        if ($this->userId === null || $this->userId <= 0) {
            $user = $this->getUser();
            $id = isset($user['user_id']) ? (int) $user['user_id'] : 0;
            if ($id <= 0) {
                throw self::accessDenied('API не вернул user_id');
            }
            $this->userId = $id;
        }
        return $this->userId;
    }

    public function listHosts(int $maxRows = 5000): array
    {
        return $this->pagedGet($this->userPath('/hosts'), [], 'hosts', $maxRows, 100);
    }

    public function getHost(string $hostId): array
    {
        return $this->request('GET', $this->hostPath($hostId));
    }

    public function getHostSummary(string $hostId): array
    {
        return $this->request('GET', $this->hostPath($hostId) . '/summary');
    }

    public function getVerification(string $hostId): array
    {
        return $this->request('GET', $this->hostPath($hostId) . '/owner-verification');
    }

    public function getOwners(string $hostId): array
    {
        return $this->request('GET', $this->hostPath($hostId) . '/owners');
    }

    public function getDiagnostics(string $hostId): array
    {
        return $this->request('GET', $this->hostPath($hostId) . '/diagnostics');
    }

    public function getSitemaps(string $hostId): array
    {
        return $this->request('GET', $this->hostPath($hostId) . '/sitemaps');
    }

    public function getSitemap(string $hostId, string $sitemapId): array
    {
        return $this->request('GET', $this->hostPath($hostId) . '/sitemaps/' . rawurlencode($this->required($sitemapId, 'sitemap_id')));
    }

    public function getUserSitemaps(string $hostId, int $limit = 500): array
    {
        return $this->pagedGet($this->hostPath($hostId) . '/user-added-sitemaps', [], 'sitemaps', $limit, 100);
    }

    public function getIndexingHistory(string $hostId, string $dateFrom, string $dateTo): array
    {
        return $this->periodGet($this->hostPath($hostId) . '/indexing/history', $dateFrom, $dateTo);
    }

    public function getIndexingSamples(string $hostId, int $limit = 100, int $offset = 0): array
    {
        return $this->request('GET', $this->hostPath($hostId) . '/indexing/samples', $this->pageParams($limit, $offset, 100));
    }

    public function getPagesInSearch(string $hostId, int $limit = 100, int $offset = 0): array
    {
        return $this->request('GET', $this->hostPath($hostId) . '/search-urls/in-search/samples', $this->pageParams($limit, $offset, 100));
    }

    public function getPagesInSearchHistory(string $hostId, string $dateFrom, string $dateTo): array
    {
        return $this->periodGet($this->hostPath($hostId) . '/search-urls/in-search/history', $dateFrom, $dateTo);
    }

    public function getSearchEvents(string $hostId, int $limit = 100, int $offset = 0): array
    {
        return $this->request('GET', $this->hostPath($hostId) . '/search-urls/events/samples', $this->pageParams($limit, $offset, 100));
    }

    public function getSearchEventsHistory(string $hostId, string $dateFrom, string $dateTo): array
    {
        return $this->periodGet($this->hostPath($hostId) . '/search-urls/events/history', $dateFrom, $dateTo);
    }

    public function getImportantUrls(string $hostId, int $limit = 100, int $offset = 0): array
    {
        return $this->request('GET', $this->hostPath($hostId) . '/important-urls', $this->pageParams($limit, $offset, 100));
    }

    public function getImportantUrlHistory(string $hostId, string $url): array
    {
        return $this->request('GET', $this->hostPath($hostId) . '/important-urls/history', ['url' => $this->required($url, 'url')]);
    }

    public function getBrokenInternalLinks(string $hostId, int $limit = 100, int $offset = 0): array
    {
        return $this->request('GET', $this->hostPath($hostId) . '/links/internal/broken/samples', $this->pageParams($limit, $offset, 100));
    }

    public function getBrokenInternalLinksHistory(string $hostId, string $dateFrom, string $dateTo): array
    {
        return $this->periodGet($this->hostPath($hostId) . '/links/internal/broken/history', $dateFrom, $dateTo);
    }

    public function getExternalLinks(string $hostId, int $limit = 100, int $offset = 0): array
    {
        return $this->request('GET', $this->hostPath($hostId) . '/links/external/samples', $this->pageParams($limit, $offset, 100));
    }

    public function getExternalLinksHistory(string $hostId, string $dateFrom, string $dateTo): array
    {
        return $this->periodGet($this->hostPath($hostId) . '/links/external/history', $dateFrom, $dateTo);
    }

    public function getSqiHistory(string $hostId, string $dateFrom, string $dateTo): array
    {
        return $this->periodGet($this->hostPath($hostId) . '/sqi-history', $dateFrom, $dateTo);
    }

    public function getFeeds(string $hostId): array
    {
        return $this->request('GET', $this->hostPath($hostId) . '/feeds/list');
    }

    public function getRecrawlQuota(string $hostId): array
    {
        return $this->request('GET', $this->hostPath($hostId) . '/recrawl/quota');
    }

    public function getRecrawlTasks(string $hostId): array
    {
        return $this->request('GET', $this->hostPath($hostId) . '/recrawl/queue');
    }

    public function getOriginalTexts(string $hostId, int $limit = 100, int $offset = 0): array
    {
        return $this->request('GET', $this->hostPath($hostId) . '/original-texts', $this->pageParams($limit, $offset, 100));
    }

    public function getOriginalTextQuota(string $hostId): array
    {
        return $this->request('GET', $this->hostPath($hostId) . '/original-texts/quota');
    }

    public function getSearchDynamics(
        string $hostId,
        string $dateFrom,
        string $dateTo,
        string $device = 'ALL'
    ): array {
        $period = $this->resolvePeriod($dateFrom, $dateTo);
        $params = [
            'date_from' => $period['resolved']['from'],
            'date_to' => $period['resolved']['to'],
            'device_type_indicator' => $this->device($device),
            'query_indicator' => self::QUERY_INDICATORS,
        ];
        $result = $this->request('GET', $this->hostPath($hostId) . '/search-queries/all/history', $params);
        return $this->attachPeriod($result, $period);
    }

    public function getPopularQueries(
        string $hostId,
        string $dateFrom,
        string $dateTo,
        string $orderBy = 'TOTAL_SHOWS',
        string $device = 'ALL',
        int $limit = 100,
        int $offset = 0
    ): array {
        $period = $this->resolvePeriod($dateFrom, $dateTo);
        $orderBy = strtoupper(trim($orderBy));
        if (!in_array($orderBy, ['TOTAL_SHOWS', 'TOTAL_CLICKS'], true)) {
            throw new InvalidArgumentException('order_by должен быть TOTAL_SHOWS или TOTAL_CLICKS');
        }
        $params = [
            'date_from' => $period['resolved']['from'],
            'date_to' => $period['resolved']['to'],
            'device_type_indicator' => $this->device($device),
            'query_indicator' => self::QUERY_INDICATORS,
            'order_by' => $orderBy,
            'limit' => max(1, min(500, $limit)),
            'offset' => max(0, $offset),
        ];
        $result = $this->request('GET', $this->hostPath($hostId) . '/search-queries/popular', $params);
        return $this->attachPeriod($result, $period);
    }

    public function getQueryHistory(
        string $hostId,
        string $queryId,
        string $dateFrom,
        string $dateTo,
        string $device = 'ALL'
    ): array {
        $period = $this->resolvePeriod($dateFrom, $dateTo);
        $params = [
            'date_from' => $period['resolved']['from'],
            'date_to' => $period['resolved']['to'],
            'device_type_indicator' => $this->device($device),
            'query_indicator' => self::QUERY_INDICATORS,
        ];
        $result = $this->request('GET', $this->hostPath($hostId) . '/search-queries/' . rawurlencode($this->required($queryId, 'query_id')) . '/history', $params);
        return $this->attachPeriod($result, $period);
    }

    public function getQueryAnalytics(string $hostId, array $body): array
    {
        $from = isset($body['date_from']) ? (string) $body['date_from'] : '14daysAgo';
        $to = isset($body['date_to']) ? (string) $body['date_to'] : 'yesterday';
        $period = $this->resolvePeriod($from, $to);
        $request = [
            'date_from' => $period['resolved']['from'],
            'date_to' => $period['resolved']['to'],
            'limit' => max(1, min(500, (int) ($body['limit'] ?? 100))),
            'offset' => max(0, (int) ($body['offset'] ?? 0)),
        ];
        foreach (['device_type_indicator', 'text_indicator', 'region_ids', 'filters', 'sort_by_date'] as $key) {
            if (array_key_exists($key, $body) && $body[$key] !== null && $body[$key] !== '') {
                $request[$key] = $body[$key];
            }
        }
        $result = $this->request('POST', $this->hostPath($hostId) . '/query-analytics/list', [], $request);
        return $this->attachPeriod($result, $period);
    }

    public function comparePeriods(
        string $hostId,
        string $aFrom,
        string $aTo,
        string $bFrom,
        string $bTo,
        int $limit = 100,
        string $device = 'ALL'
    ): array {
        return [
            'host_id' => $hostId,
            'period_a' => [
                'dynamics' => $this->getSearchDynamics($hostId, $aFrom, $aTo, $device),
                'popular_by_shows' => $this->getPopularQueries($hostId, $aFrom, $aTo, 'TOTAL_SHOWS', $device, $limit),
                'popular_by_clicks' => $this->getPopularQueries($hostId, $aFrom, $aTo, 'TOTAL_CLICKS', $device, $limit),
            ],
            'period_b' => [
                'dynamics' => $this->getSearchDynamics($hostId, $bFrom, $bTo, $device),
                'popular_by_shows' => $this->getPopularQueries($hostId, $bFrom, $bTo, 'TOTAL_SHOWS', $device, $limit),
                'popular_by_clicks' => $this->getPopularQueries($hostId, $bFrom, $bTo, 'TOTAL_CLICKS', $device, $limit),
            ],
        ];
    }

    public function getFullSiteAnalysis(
        string $hostId,
        string $dateFrom,
        string $dateTo,
        int $limit = 100,
        string $device = 'ALL'
    ): array {
        $period = $this->resolvePeriod($dateFrom, $dateTo);
        $limit = max(5, min(500, $limit));
        $result = [
            'host_id' => $hostId,
            'requested_period' => $period['requested'],
            'resolved_period' => $period['resolved'],
            'reports' => [],
            'derived' => [],
            'errors' => [],
        ];
        $from = $period['resolved']['from'];
        $to = $period['resolved']['to'];
        $reports = [
            'host' => function () use ($hostId) { return $this->getHost($hostId); },
            'summary' => function () use ($hostId) { return $this->getHostSummary($hostId); },
            'verification' => function () use ($hostId) { return $this->getVerification($hostId); },
            'diagnostics' => function () use ($hostId) { return $this->getDiagnostics($hostId); },
            'search_dynamics' => function () use ($hostId, $from, $to, $device) { return $this->getSearchDynamics($hostId, $from, $to, $device); },
            'popular_queries_by_shows' => function () use ($hostId, $from, $to, $device, $limit) { return $this->getPopularQueries($hostId, $from, $to, 'TOTAL_SHOWS', $device, $limit); },
            'popular_queries_by_clicks' => function () use ($hostId, $from, $to, $device, $limit) { return $this->getPopularQueries($hostId, $from, $to, 'TOTAL_CLICKS', $device, $limit); },
            'indexing_history' => function () use ($hostId, $from, $to) { return $this->getIndexingHistory($hostId, $from, $to); },
            'indexing_samples' => function () use ($hostId, $limit) { return $this->getIndexingSamples($hostId, $limit); },
            'pages_in_search_history' => function () use ($hostId, $from, $to) { return $this->getPagesInSearchHistory($hostId, $from, $to); },
            'pages_in_search' => function () use ($hostId, $limit) { return $this->getPagesInSearch($hostId, $limit); },
            'search_events_history' => function () use ($hostId, $from, $to) { return $this->getSearchEventsHistory($hostId, $from, $to); },
            'search_events' => function () use ($hostId, $limit) { return $this->getSearchEvents($hostId, $limit); },
            'sitemaps' => function () use ($hostId) { return $this->getSitemaps($hostId); },
            'user_sitemaps' => function () use ($hostId, $limit) { return $this->getUserSitemaps($hostId, $limit); },
            'broken_internal_links_history' => function () use ($hostId, $from, $to) { return $this->getBrokenInternalLinksHistory($hostId, $from, $to); },
            'broken_internal_links' => function () use ($hostId, $limit) { return $this->getBrokenInternalLinks($hostId, $limit); },
            'external_links_history' => function () use ($hostId, $from, $to) { return $this->getExternalLinksHistory($hostId, $from, $to); },
            'external_links' => function () use ($hostId, $limit) { return $this->getExternalLinks($hostId, $limit); },
            'important_urls' => function () use ($hostId, $limit) { return $this->getImportantUrls($hostId, $limit); },
            'sqi_history' => function () use ($hostId, $from, $to) { return $this->getSqiHistory($hostId, $from, $to); },
            'feeds' => function () use ($hostId) { return $this->getFeeds($hostId); },
            'recrawl_quota' => function () use ($hostId) { return $this->getRecrawlQuota($hostId); },
        ];
        foreach ($reports as $name => $callback) {
            try {
                $result['reports'][$name] = $callback();
            } catch (Throwable $exception) {
                $result['errors'][$name] = ['message' => $exception->getMessage()];
            }
        }
        if (isset($result['reports']['popular_queries_by_shows'])) {
            $result['derived']['search_opportunities'] = $this->deriveQueryOpportunities($result['reports']['popular_queries_by_shows'], $limit);
        }
        if (isset($result['reports']['diagnostics'])) {
            $result['derived']['diagnostics_summary'] = $this->summarizeDiagnostics($result['reports']['diagnostics']);
        }
        if (isset($result['reports']['search_events'])) {
            $result['derived']['exclusion_reasons'] = $this->summarizeField($result['reports']['search_events'], ['excluded_url_status', 'event']);
        }
        return $result;
    }

    public function deriveQueryOpportunities(array $response, int $limit = 100): array
    {
        $rows = $this->findRows($response);
        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) { continue; }
            $flat = $this->flatten($row);
            $shows = $this->number($flat, ['TOTAL_SHOWS', 'shows', 'total_shows']);
            $clicks = $this->number($flat, ['TOTAL_CLICKS', 'clicks', 'total_clicks']);
            $position = $this->number($flat, ['AVG_SHOW_POSITION', 'avg_show_position', 'position']);
            if ($shows === null) { continue; }
            $ctr = $shows > 0 && $clicks !== null ? ($clicks / $shows) * 100 : null;
            $category = 'other';
            if ($position !== null && $position >= 4 && $position <= 20) { $category = 'near_top'; }
            if ($shows >= 10 && $ctr !== null && $ctr < 2.0) { $category = 'low_ctr'; }
            if ($category === 'other') { continue; }
            $items[] = [
                'category' => $category,
                'query_id' => $flat['query_id'] ?? null,
                'query_text' => $flat['query_text'] ?? ($flat['query'] ?? null),
                'shows' => $shows,
                'clicks' => $clicks,
                'ctr_percent_calculated' => $ctr === null ? null : round($ctr, 3),
                'avg_show_position' => $position,
                'source' => $row,
            ];
        }
        usort($items, function (array $a, array $b): int { return (int) ($b['shows'] <=> $a['shows']); });
        return ['items' => array_slice($items, 0, max(1, min(500, $limit))), 'rules' => ['near_top' => 'position 4–20', 'low_ctr' => 'shows >= 10 and calculated CTR < 2%']];
    }

    public function resolvePeriod(string $dateFrom, string $dateTo): array
    {
        $from = $this->resolveDate($dateFrom);
        $to = $this->resolveDate($dateTo);
        if ($from > $to) {
            throw new InvalidArgumentException('Начальная дата позже конечной даты');
        }
        return [
            'requested' => ['from' => $dateFrom, 'to' => $dateTo],
            'resolved' => ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
            'days' => ((int) $from->diff($to)->days) + 1,
        ];
    }

    private function periodGet(string $path, string $from, string $to): array
    {
        $period = $this->resolvePeriod($from, $to);
        $result = $this->request('GET', $path, ['date_from' => $period['resolved']['from'], 'date_to' => $period['resolved']['to']]);
        return $this->attachPeriod($result, $period);
    }

    private function attachPeriod(array $response, array $period): array
    {
        $response['requested_period'] = $period['requested'];
        $response['resolved_period'] = $period['resolved'];
        return $response;
    }

    private function pageParams(int $limit, int $offset, int $max): array
    {
        return ['limit' => max(1, min($max, $limit)), 'offset' => max(0, $offset)];
    }

    private function device(string $device): string
    {
        $device = strtoupper(trim($device));
        if (!in_array($device, self::DEVICE_TYPES, true)) {
            throw new InvalidArgumentException('Некорректный device_type');
        }
        return $device;
    }

    private function userPath(string $suffix): string
    {
        return '/user/' . $this->getUserId() . $suffix;
    }

    private function hostPath(string $hostId): string
    {
        return $this->userPath('/hosts/' . rawurlencode($this->required($hostId, 'host_id')));
    }

    private function required(string $value, string $name): string
    {
        $value = trim($value);
        if ($value === '') { throw new InvalidArgumentException('Не указан ' . $name); }
        return $value;
    }

    private function pagedGet(string $path, array $params, string $listKey, int $maxRows, int $pageSize): array
    {
        $offset = 0; $items = []; $first = null; $pages = 0; $total = null;
        do {
            $remaining = max(0, $maxRows - count($items));
            if ($remaining === 0) { break; }
            $pageParams = $params + ['offset' => $offset, 'limit' => min($pageSize, $remaining)];
            $response = $this->request('GET', $path, $pageParams);
            if ($first === null) { $first = $response; }
            $page = isset($response[$listKey]) && is_array($response[$listKey]) ? $response[$listKey] : [];
            foreach ($page as $item) { $items[] = $item; }
            $received = count($page); $offset += $received; $pages++;
            if (isset($response['count'])) { $total = (int) $response['count']; }
            if ($received === 0 || $received < min($pageSize, $remaining) || $pages >= 100) { break; }
        } while ($total === null || count($items) < $total);
        if ($first === null) { $first = []; }
        $first[$listKey] = $items;
        $first['_metadata'] = ['fetched_rows' => count($items), 'pages' => $pages, 'total_rows' => $total, 'truncated' => $total !== null && count($items) < $total];
        return $first;
    }

    private function resolveDate(string $value): DateTimeImmutable
    {
        $value = trim($value);
        $today = new DateTimeImmutable('today', $this->timezone);
        if ($value === 'today') { return $today; }
        if ($value === 'yesterday') { return $today->modify('-1 day'); }
        if (preg_match('/^(\d+)daysAgo$/', $value, $matches) === 1) {
            return $today->modify('-' . (int) $matches[1] . ' days');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $this->timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new InvalidArgumentException('Некорректная дата: ' . $value);
        }
        return $date;
    }

    private function findRows(array $response): array
    {
        foreach (['queries', 'search_queries', 'text_indicator_queries', 'items', 'data'] as $key) {
            if (isset($response[$key]) && is_array($response[$key])) { return $response[$key]; }
        }
        return [];
    }

    private function flatten(array $data): array
    {
        $result = [];
        $walk = function (array $node) use (&$walk, &$result): void {
            foreach ($node as $key => $value) {
                if (is_array($value)) { $walk($value); } elseif (!array_key_exists((string) $key, $result)) { $result[(string) $key] = $value; }
            }
        };
        $walk($data);
        return $result;
    }

    private function number(array $data, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_numeric($data[$key])) { return (float) $data[$key]; }
        }
        return null;
    }

    private function summarizeDiagnostics(array $response): array
    {
        $rows = $this->findRows($response);
        if ($rows === [] && isset($response['problems']) && is_array($response['problems'])) { $rows = $response['problems']; }
        $levels = [];
        foreach ($rows as $row) {
            if (!is_array($row)) { continue; }
            $flat = $this->flatten($row);
            $level = strtoupper((string) ($flat['severity'] ?? $flat['indicator'] ?? $flat['type'] ?? 'UNKNOWN'));
            $levels[$level] = ($levels[$level] ?? 0) + 1;
        }
        arsort($levels);
        return ['total' => count($rows), 'by_severity_or_type' => $levels];
    }

    private function summarizeField(array $response, array $fields): array
    {
        $rows = $this->findRows($response);
        if ($rows === [] && isset($response['samples']) && is_array($response['samples'])) { $rows = $response['samples']; }
        $counts = [];
        foreach ($rows as $row) {
            if (!is_array($row)) { continue; }
            $flat = $this->flatten($row); $value = 'UNKNOWN';
            foreach ($fields as $field) { if (!empty($flat[$field])) { $value = (string) $flat[$field]; break; } }
            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }
        arsort($counts);
        return ['total_samples' => count($rows), 'counts' => $counts];
    }

    private function request(string $method, string $path, array $query = [], ?array $jsonBody = null): array
    {
        $url = self::API_URL . $path;
        if ($query !== []) {
            $url .= '?' . $this->buildQuery($query);
        }
        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            $curl = curl_init($url);
            if ($curl === false) { throw new RuntimeException('Не удалось инициализировать cURL'); }
            $headers = ['Authorization: OAuth ' . $this->oauthToken, 'Accept: application/json', 'User-Agent: EFFECT-Webmaster-MCP/1.0'];
            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CUSTOMREQUEST => $method,
            ];
            if ($jsonBody !== null) {
                $encoded = json_encode($jsonBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $options[CURLOPT_POSTFIELDS] = $encoded;
                $headers[] = 'Content-Type: application/json; charset=UTF-8';
            }
            $options[CURLOPT_HTTPHEADER] = $headers;
            curl_setopt_array($curl, $options);
            $body = curl_exec($curl);
            if ($body === false) {
                $error = curl_error($curl); curl_close($curl);
                if ($attempt < $this->maxRetries) { sleep($attempt); continue; }
                throw new RuntimeException('Ошибка cURL: ' . $error);
            }
            $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            $response = [];
            if ($body !== '') {
                try { $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR); $response = is_array($decoded) ? $decoded : []; }
                catch (JsonException $exception) { throw new RuntimeException('Вебмастер вернул некорректный JSON', 0, $exception); }
            }
            if ($status >= 200 && $status < 300) { return $response; }
            $message = $this->apiMessage($response);
            if (($status === 429 || $status >= 500) && $attempt < $this->maxRetries) { sleep($attempt * 2); continue; }
            if ($status === 401 || $status === 403 || $status === 404) {
                throw self::accessDenied('Ошибка доступа Вебмастера HTTP ' . $status . ': ' . $message);
            }
            throw new RuntimeException('Ошибка Вебмастера HTTP ' . $status . ': ' . $message);
        }
        throw new RuntimeException('Неизвестная ошибка Вебмастера');
    }

    private function buildQuery(array $query): string
    {
        $pairs = [];
        foreach ($query as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $values = is_array($value) ? $value : [$value];
            foreach ($values as $item) {
                if ($item === null || is_array($item) || is_object($item)) {
                    continue;
                }
                $pairs[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $item);
            }
        }
        return implode('&', $pairs);
    }

    private function apiMessage(array $response): string
    {
        if (isset($response['message'])) { return (string) $response['message']; }
        if (isset($response['error_message'])) { return (string) $response['error_message']; }
        if (isset($response['error_code'])) { return (string) $response['error_code']; }
        if (isset($response['errors'][0]['message'])) { return (string) $response['errors'][0]['message']; }
        return 'Неизвестная ошибка API';
    }
}
