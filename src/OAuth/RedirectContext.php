<?php
declare(strict_types=1);
namespace Websonette\ApplicationMcpServer\OAuth;

/** Request-local context; never persisted and always cleared after authorization. */
final class RedirectContext
{
    public ?string $clientId = null;
    public ?string $uri = null;
}
