<?php

declare(strict_types=1);

namespace App\Services\Webmaster;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class WebmasterResolver
{
    /** Коды исключений резолвера. */
    public const HOST_NOT_FOUND = 4041;
    public const MULTIPLE_HOSTS = 4091;

    private static function hostNotFound(string $message): RuntimeException
    {
        return new RuntimeException($message, self::HOST_NOT_FOUND);
    }

    private static function multipleHosts(string $message): RuntimeException
    {
        return new RuntimeException($message, self::MULTIPLE_HOSTS);
    }

    /** @var WebmasterClient */
    private $client;

    public function __construct(WebmasterClient $client)
    {
        $this->client = $client;
    }

    public function resolve(string $siteUrl): array
    {
        $requested = $this->normalizeUrl($siteUrl);
        $requestedKey = $this->urlKey($requested);
        $requestedDomain = $this->domainKey($requested);
        $hostsResponse = $this->client->listHosts(5000);
        $hosts = isset($hostsResponse['hosts']) && is_array($hostsResponse['hosts'])
            ? $hostsResponse['hosts'] : [];
        $exact = [];
        $domain = [];

        foreach ($hosts as $host) {
            if (!is_array($host)) {
                continue;
            }
            $urls = [];
            foreach (['ascii_host_url', 'unicode_host_url', 'host_url'] as $key) {
                if (!empty($host[$key]) && is_string($host[$key])) {
                    $urls[] = $host[$key];
                }
            }
            if (!empty($host['main_mirror']) && is_array($host['main_mirror'])) {
                foreach (['ascii_host_url', 'unicode_host_url', 'host_url'] as $key) {
                    if (!empty($host['main_mirror'][$key]) && is_string($host['main_mirror'][$key])) {
                        $urls[] = $host['main_mirror'][$key];
                    }
                }
            }
            foreach (array_unique($urls) as $url) {
                try {
                    $normalized = $this->normalizeUrl($url);
                } catch (Throwable $exception) {
                    continue;
                }
                if ($this->urlKey($normalized) === $requestedKey) {
                    $exact[$this->hostId($host)] = $host;
                } elseif ($this->domainKey($normalized) === $requestedDomain) {
                    $domain[$this->hostId($host)] = $host;
                }
            }
        }

        $matches = $exact !== [] ? array_values($exact) : array_values($domain);
        if ($matches === []) {
            throw self::hostNotFound(
                'Сайт не найден среди доступных OAuth-токену сайтов Яндекс Вебмастера'
            );
        }
        if (count($matches) > 1) {
            $ids = [];
            foreach ($matches as $host) {
                $ids[] = $this->hostId($host);
            }
            throw self::multipleHosts(
                'Найдено несколько подходящих сайтов: ' . implode(', ', $ids) . '. Укажите host_id.'
            );
        }

        return [
            'requested_url' => $siteUrl,
            'normalized_url' => $requested,
            'match_type' => $exact !== [] ? 'exact' : 'domain',
            'host_id' => $this->hostId($matches[0]),
            'host' => $matches[0],
        ];
    }

    public function resolveByHostId(string $hostId): array
    {
        $hostId = trim($hostId);
        if ($hostId === '') {
            throw new InvalidArgumentException('Не указан host_id');
        }
        $host = $this->client->getHost($hostId);
        return [
            'requested_url' => null,
            'normalized_url' => null,
            'match_type' => 'host_id',
            'host_id' => $hostId,
            'host' => $host,
        ];
    }

    private function hostId(array $host): string
    {
        $id = isset($host['host_id']) ? trim((string) $host['host_id']) : '';
        if ($id === '') {
            throw new RuntimeException('API вернул сайт без host_id');
        }
        return $id;
    }

    private function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            throw new InvalidArgumentException('Не указан URL сайта');
        }
        if (!preg_match('~^https?://~i', $url)) {
            $url = 'https://' . $url;
        }
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            throw new InvalidArgumentException('Некорректный URL сайта');
        }
        $scheme = strtolower(isset($parts['scheme']) ? (string) $parts['scheme'] : 'https');
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('Разрешены только HTTP и HTTPS');
        }
        $host = strtolower((string) $parts['host']);
        if (function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7f]/', $host)) {
            $flags = defined('IDNA_DEFAULT') ? IDNA_DEFAULT : 0;
            $variant = defined('INTL_IDNA_VARIANT_UTS46') ? INTL_IDNA_VARIANT_UTS46 : 1;
            $ascii = @idn_to_ascii($host, $flags, $variant);
            if (is_string($ascii) && $ascii !== '') {
                $host = strtolower($ascii);
            }
        }
        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        return $scheme . '://' . $host . (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80) ? '' : ':' . $port) . '/';
    }

    private function urlKey(string $url): string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        return $scheme . '|' . $host . '|' . $port;
    }

    private function domainKey(string $url): string
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        return preg_replace('/^www\./i', '', $host) ?: $host;
    }
}
