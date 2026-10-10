<?php
declare(strict_types=1);

namespace Websonette\ApplicationMcpServer\Security;

final readonly class Principal
{
    /** @param list<string> $scopes */
    public function __construct(public string $clientId, public ?string $userId, public array $scopes)
    {
    }

    /** @param list<string> $required */
    public function permits(array $required): bool
    {
        return array_diff($required, $this->scopes) === [];
    }

    public function key(): string
    {
        return hash('sha256', json_encode([$this->clientId, $this->userId], JSON_THROW_ON_ERROR));
    }
}
