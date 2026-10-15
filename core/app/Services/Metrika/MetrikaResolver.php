<?php

declare(strict_types=1);

namespace App\Services\Metrika;

use InvalidArgumentException;
use RuntimeException;

/**
 * Находит ID счётчиков Яндекс Метрики в HTML страницы.
 *
 * Защищён от запросов к локальным и приватным адресам.
 */
final class MetrikaResolver
{
    /** Коды исключений резолвера. */
    public const SITE_URL_ERROR = 4001;
    public const UNSAFE_SITE_URL = 4002;
    public const COUNTER_NOT_FOUND = 4041;

    private static function siteError(string $message): RuntimeException
    {
        return new RuntimeException($message, self::SITE_URL_ERROR);
    }

    private static function unsafeUrl(string $message): RuntimeException
    {
        return new RuntimeException($message, self::UNSAFE_SITE_URL);
    }

    private static function counterNotFound(string $message): RuntimeException
    {
        return new RuntimeException($message, self::COUNTER_NOT_FOUND);
    }

    /** @var int */
    private $connectTimeout;

    /** @var int */
    private $timeout;

    /** @var int */
    private $maxRedirects;

    /** @var int */
    private $maxBytes;

    public function __construct(
        int $connectTimeout = 5,
        int $timeout = 15,
        int $maxRedirects = 5,
        int $maxBytes = 2097152
    ) {
        if (!extension_loaded('curl')) {
            throw new RuntimeException(
                'Расширение PHP cURL не установлено'
            );
        }

        $this->connectTimeout = max(1, $connectTimeout);
        $this->timeout = max(1, $timeout);
        $this->maxRedirects = max(0, min(10, $maxRedirects));
        $this->maxBytes = max(1024, $maxBytes);
    }

    public function resolve(string $inputUrl): array
    {
        $requestedUrl = $this->normalizeUrl($inputUrl);
        $currentUrl = $requestedUrl;
        $redirects = [];

        for (
            $redirectNumber = 0;
            $redirectNumber <= $this->maxRedirects;
            $redirectNumber++
        ) {
            $this->validatePublicUrl($currentUrl);

            $response = $this->fetch($currentUrl);
            $status = $response['status'];

            if ($status >= 300 && $status < 400) {
                $location = $response['location'];

                if (!$location) {
                    throw self::siteError(
                        'Сайт вернул перенаправление без заголовка Location'
                    );
                }

                if ($redirectNumber >= $this->maxRedirects) {
                    throw self::siteError(
                        'Превышено максимальное количество перенаправлений'
                    );
                }

                $nextUrl = $this->resolveRedirectUrl(
                    $currentUrl,
                    $location
                );

                $redirects[] = [
                    'from' => $currentUrl,
                    'to' => $nextUrl,
                    'status' => $status,
                ];

                $currentUrl = $nextUrl;

                continue;
            }

            if ($status < 200 || $status >= 300) {
                throw self::siteError(
                    'Сайт вернул HTTP ' . $status
                );
            }

            $contentType = strtolower(
                (string) $response['content_type']
            );

            if (
                $contentType !== ''
                && strpos($contentType, 'text/html') === false
                && strpos($contentType, 'application/xhtml+xml') === false
                && strpos($contentType, 'text/plain') === false
            ) {
                throw self::siteError(
                    'URL вернул не HTML-документ: '
                    . $contentType
                );
            }

            $counterIds = $this->extractCounterIds(
                $response['body']
            );

            if ($counterIds === []) {
                throw self::counterNotFound(
                    'В HTML страницы не найден счётчик Яндекс Метрики. '
                    . 'Возможно, он подключён через менеджер тегов, '
                    . 'загружается JavaScript-кодом или появляется '
                    . 'после согласия на cookie. Укажите counter_id вручную.'
                );
            }

            return [
                'requested_url' => $requestedUrl,
                'final_url' => $currentUrl,
                'redirects' => $redirects,
                'detected_counter_ids' => $counterIds,
            ];
        }

        throw self::siteError(
            'Не удалось загрузить страницу'
        );
    }

    private function normalizeUrl(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            throw new InvalidArgumentException(
                'Не указан URL сайта'
            );
        }

        if (
            !preg_match(
                '~^https?://~i',
                $url
            )
        ) {
            $url = 'https://' . $url;
        }

        $parts = parse_url($url);

        if (
            $parts === false
            || empty($parts['scheme'])
            || empty($parts['host'])
        ) {
            throw self::siteError(
                'Некорректный URL сайта'
            );
        }

        $scheme = strtolower((string) $parts['scheme']);

        if (!in_array($scheme, ['http', 'https'], true)) {
            throw self::unsafeUrl(
                'Разрешены только HTTP и HTTPS'
            );
        }

        if (
            isset($parts['user'])
            || isset($parts['pass'])
        ) {
            throw self::unsafeUrl(
                'URL с логином или паролем запрещён'
            );
        }

        $host = strtolower((string) $parts['host']);

        if (
            function_exists('idn_to_ascii')
            && preg_match('/[^\x20-\x7f]/', $host)
        ) {
            $asciiHost = idn_to_ascii(
                $host,
                IDNA_DEFAULT,
                INTL_IDNA_VARIANT_UTS46
            );

            if (is_string($asciiHost) && $asciiHost !== '') {
                $host = strtolower($asciiHost);
            }
        }

        $port = isset($parts['port'])
            ? (int) $parts['port']
            : null;

        if (
            $port !== null
            && $port !== 80
            && $port !== 443
        ) {
            throw self::unsafeUrl(
                'Нестандартные порты запрещены'
            );
        }

        $path = isset($parts['path'])
            ? $parts['path']
            : '/';

        if ($path === '') {
            $path = '/';
        }

        $normalized = $scheme . '://' . $host;

        if ($port !== null) {
            $normalized .= ':' . $port;
        }

        $normalized .= $path;

        if (isset($parts['query'])) {
            $normalized .= '?' . $parts['query'];
        }

        return $normalized;
    }

    private function validatePublicUrl(string $url): void
    {
        $parts = parse_url($url);

        if ($parts === false || empty($parts['host'])) {
            throw self::siteError(
                'Некорректный URL'
            );
        }

        $host = strtolower((string) $parts['host']);

        $forbiddenHosts = [
            'localhost',
            'localhost.localdomain',
            'metadata.google.internal',
        ];

        if (
            in_array($host, $forbiddenHosts, true)
            || substr($host, -6) === '.local'
            || substr($host, -9) === '.internal'
        ) {
            throw self::unsafeUrl(
                'Обращение к локальному адресу запрещено'
            );
        }

        $ips = $this->resolvePublicIps($host);

        if ($ips === []) {
            throw self::unsafeUrl(
                'Не удалось получить публичный IP сайта'
            );
        }
    }

    private function resolvePublicIps(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (!$this->isPublicIp($host)) {
                throw self::unsafeUrl(
                    'Приватный или служебный IP запрещён'
                );
            }

            return [$host];
        }

        $ips = [];

        if (function_exists('dns_get_record')) {
            $records = @dns_get_record(
                $host,
                DNS_A | DNS_AAAA
            );

            if (is_array($records)) {
                foreach ($records as $record) {
                    if (!empty($record['ip'])) {
                        $ips[] = $record['ip'];
                    }

                    if (!empty($record['ipv6'])) {
                        $ips[] = $record['ipv6'];
                    }
                }
            }
        }

        if ($ips === []) {
            $ipv4Addresses = @gethostbynamel($host);

            if (is_array($ipv4Addresses)) {
                $ips = array_merge(
                    $ips,
                    $ipv4Addresses
                );
            }
        }

        $ips = array_values(array_unique($ips));

        foreach ($ips as $ip) {
            if (!$this->isPublicIp($ip)) {
                throw self::unsafeUrl(
                    'Домен указывает на приватный '
                    . 'или служебный IP'
                );
            }
        }

        return $ips;
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE
            | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    private function fetch(string $url): array
    {
        $parts = parse_url($url);

        if ($parts === false || empty($parts['host'])) {
            throw self::siteError(
                'Некорректный URL'
            );
        }

        $host = (string) $parts['host'];
        $scheme = strtolower((string) $parts['scheme']);

        $port = isset($parts['port'])
            ? (int) $parts['port']
            : ($scheme === 'https' ? 443 : 80);

        $publicIps = $this->resolvePublicIps($host);
        $selectedIp = $publicIps[0];

        $curl = curl_init($url);

        if ($curl === false) {
            throw new RuntimeException(
                'Не удалось инициализировать cURL'
            );
        }

        $body = '';
        $headers = [];
        $tooLarge = false;

        $resolveIp = strpos($selectedIp, ':') !== false
            ? '[' . $selectedIp . ']'
            : $selectedIp;

        curl_setopt_array(
            $curl,
            [
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_ENCODING => '',
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,

                CURLOPT_HTTPHEADER => [
                    'Accept: text/html,application/xhtml+xml',
                    'Accept-Language: ru,en;q=0.8',
                    'Connection: close',
                ],

                CURLOPT_USERAGENT =>
                    'EFFECT-Metrika-Resolver/1.0',

                CURLOPT_RESOLVE => [
                    $host . ':' . $port . ':' . $resolveIp,
                ],

                CURLOPT_HEADERFUNCTION =>
                    function (
                        $curlHandle,
                        string $headerLine
                    ) use (&$headers): int {
                        $length = strlen($headerLine);
                        $line = trim($headerLine);

                        if ($line === '') {
                            return $length;
                        }

                        if (stripos($line, 'HTTP/') === 0) {
                            $headers = [];

                            return $length;
                        }

                        $separatorPosition = strpos(
                            $line,
                            ':'
                        );

                        if ($separatorPosition !== false) {
                            $name = strtolower(
                                trim(
                                    substr(
                                        $line,
                                        0,
                                        $separatorPosition
                                    )
                                )
                            );

                            $value = trim(
                                substr(
                                    $line,
                                    $separatorPosition + 1
                                )
                            );

                            $headers[$name] = $value;
                        }

                        return $length;
                    },

                CURLOPT_WRITEFUNCTION =>
                    function (
                        $curlHandle,
                        string $chunk
                    ) use (&$body, &$tooLarge): int {
                        if (
                            strlen($body) + strlen($chunk)
                            > $this->maxBytes
                        ) {
                            $tooLarge = true;

                            return 0;
                        }

                        $body .= $chunk;

                        return strlen($chunk);
                    },
            ]
        );

        $success = curl_exec($curl);
        $curlError = curl_error($curl);
        $status = (int) curl_getinfo(
            $curl,
            CURLINFO_HTTP_CODE
        );

        $contentType = (string) curl_getinfo(
            $curl,
            CURLINFO_CONTENT_TYPE
        );

        curl_close($curl);

        if ($tooLarge) {
            throw self::siteError(
                'Размер HTML превышает допустимый лимит'
            );
        }

        if ($success === false) {
            throw self::siteError(
                'Ошибка загрузки сайта: ' . $curlError
            );
        }

        return [
            'status' => $status,
            'content_type' => $contentType,
            'location' => $headers['location'] ?? null,
            'body' => $body,
        ];
    }

    private function resolveRedirectUrl(
        string $baseUrl,
        string $location
    ): string {
        $location = trim($location);

        if ($location === '') {
            throw self::siteError(
                'Получено пустое перенаправление'
            );
        }

        if (preg_match('~^https?://~i', $location)) {
            return $this->normalizeUrl($location);
        }

        $base = parse_url($baseUrl);

        if (
            $base === false
            || empty($base['scheme'])
            || empty($base['host'])
        ) {
            throw self::siteError(
                'Не удалось обработать перенаправление'
            );
        }

        $origin = $base['scheme']
            . '://'
            . $base['host'];

        if (isset($base['port'])) {
            $origin .= ':' . $base['port'];
        }

        if (strpos($location, '//') === 0) {
            return $this->normalizeUrl(
                $base['scheme'] . ':' . $location
            );
        }

        if (strpos($location, '/') === 0) {
            return $this->normalizeUrl(
                $origin . $location
            );
        }

        $basePath = isset($base['path'])
            ? $base['path']
            : '/';

        $directory = rtrim(
            str_replace(
                '\\',
                '/',
                dirname($basePath)
            ),
            '/'
        );

        return $this->normalizeUrl(
            $origin
            . ($directory !== '' ? $directory : '')
            . '/'
            . $location
        );
    }

    private function extractCounterIds(string $html): array
    {
        $patterns = [
            '~\bym\s*\(\s*["\']?(\d{5,12})["\']?\s*,~i',

            '~new\s+Ya\.Metrika(?:2)?\s*\(\s*\{'
            . '[\s\S]{0,3000}?'
            . '\bid\s*:\s*["\']?(\d{5,12})~i',

            '~mc\.yandex\.(?:ru|com)/watch/'
            . '(\d{5,12})~i',

            '~mc\.yandex\.(?:ru|com)/metrika/'
            . 'tag\.js\?[^"\'<>\s]*\bid=(\d{5,12})~i',

            '~\byaCounter(\d{5,12})\b~i',

            '~data-(?:counter|counter-id|metrika-id)'
            . '\s*=\s*["\'](\d{5,12})["\']~i',
        ];

        $ids = [];

        foreach ($patterns as $pattern) {
            if (
                preg_match_all(
                    $pattern,
                    $html,
                    $matches
                )
            ) {
                foreach ($matches[1] as $id) {
                    $id = (int) $id;

                    if ($id > 0) {
                        $ids[$id] = $id;
                    }
                }
            }
        }

        ksort($ids);

        return array_values($ids);
    }
}