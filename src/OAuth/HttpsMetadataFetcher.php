<?php
declare(strict_types=1);
namespace Websonette\ApplicationMcpServer\OAuth;

final readonly class HttpsMetadataFetcher implements MetadataFetcher
{
    /** @param list<string> $allowedHosts Empty permits any public HTTPS host, never private addresses. */
    public function __construct(private array $allowedHosts = []) {}
    /** @return array<string, mixed> */
    public function fetch(string $url): array
    {
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host'])
            || isset($parts['user'], $parts['pass']) || isset($parts['user']) || isset($parts['fragment']) || isset($parts['query'])
            || (isset($parts['port']) && $parts['port'] !== 443) || ($parts['path'] ?? '/') === '/'
            || strlen($url) > 512 || !preg_match('/^[\\x21-\\x7e]+$/D', $url)) {
            throw new \InvalidArgumentException('Invalid HTTPS client metadata URL.');
        }
        $host = strtolower($parts['host']);
        if (!preg_match('/^[a-z0-9.-]+$/D', $host) || filter_var($host, FILTER_VALIDATE_IP)
            || ($this->allowedHosts !== [] && !in_array($host, $this->allowedHosts, true))) {
            throw new \InvalidArgumentException('Client metadata host is not allowed.');
        }
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        $addresses = [];
        foreach ($records ?: [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (!is_string($ip)) { continue; }
            if (!self::isPublicAddress($ip)) { throw new \InvalidArgumentException('Client metadata DNS must contain only public addresses.'); }
            $addresses[] = $ip;
        }
        if ($addresses === []) { throw new \RuntimeException('Client metadata DNS resolution failed.'); }
        $ip = $addresses[0];
        $curl = curl_init($url);
        if ($curl === false) { throw new \RuntimeException('Cannot initialize metadata request.'); }
        $body = '';
        curl_setopt_array($curl, [CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 5, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_PROXY => '', CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_RESOLVE => [$host . ':443:' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)],
            CURLOPT_WRITEFUNCTION => static function (\CurlHandle $handle, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 65536) { return 0; }
                $body .= $chunk;
                return strlen($chunk);
            }]);
        try {
            $ok = curl_exec($curl);
            if ($ok === false || curl_getinfo($curl, CURLINFO_RESPONSE_CODE) !== 200) { throw new \RuntimeException('Client metadata fetch failed.'); }
        } finally { curl_close($curl); }
        $document = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($document) || array_is_list($document)) { throw new \InvalidArgumentException('Client metadata must be a JSON object.'); }
        /** @var array<string, mixed> $document */
        return $document;
    }
    public static function isPublicAddress(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === $address;
    }
}
