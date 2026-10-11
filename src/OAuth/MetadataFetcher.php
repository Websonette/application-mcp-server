<?php
declare(strict_types=1);
namespace Websonette\ApplicationMcpServer\OAuth;

interface MetadataFetcher
{
    /**
     * Fetch an HTTPS document with SSRF, size, time and redirect protections.
     * @return array<string, mixed>
     */
    public function fetch(string $url): array;
}
