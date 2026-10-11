<?php
declare(strict_types=1);
namespace Websonette\ApplicationMcpServer\OAuth;

interface ConsentState
{
    public function issue(string $binding, ?string $subject): string;
    public function consume(string $token, string $binding, ?string $subject): bool;
}
