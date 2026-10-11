<?php
declare(strict_types=1);

namespace Websonette\ApplicationMcpServer;

final readonly class Config
{
    /** @param list<string> $allowedOrigins */
    public function __construct(
        public string $baseUrl,
        public string $name = 'Websonette application',
        public string $version = '0.2.0',
        public bool $development = false,
        public array $allowedOrigins = [],
        public int $maxBodyBytes = 1048576,
        public int $requestsPerMinute = 120,
    ) {
        $parts = parse_url($baseUrl);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || rtrim($parts['path'] ?? '', '/') !== ''
            || (!($parts['scheme'] === 'https')
                && !($development && $parts['scheme'] === 'http' && in_array($parts['host'], ['localhost', '127.0.0.1', '[::1]'], true)))) {
            throw new \InvalidArgumentException('baseUrl must be an HTTPS origin; development permits loopback HTTP.');
        }
        if ($maxBodyBytes < 1 || $requestsPerMinute < 1) {
            throw new \InvalidArgumentException('Request limits must be positive.');
        }
        foreach ($allowedOrigins as $origin) {
            $p = parse_url($origin);
            if ($p === false || !isset($p['host'], $p['scheme']) || !in_array($p['scheme'], ['https', 'http'], true)
                || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment'])
                || isset($p['path']) || ($p['scheme'] === 'http' && (!$development || !in_array($p['host'], ['localhost', '127.0.0.1', '[::1]'], true)))) {
                throw new \InvalidArgumentException('Allowed origins must be explicit HTTPS origins (loopback HTTP in development).');
            }
        }
    }

    /** @return non-empty-string */
    public function url(string $path): string
    {
        return rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
    }
}
