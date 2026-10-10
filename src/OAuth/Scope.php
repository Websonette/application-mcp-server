<?php
declare(strict_types=1);
namespace Websonette\ApplicationMcpServer\OAuth;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\ScopeTrait;
final class Scope implements ScopeEntityInterface
{
    use EntityTrait;
    use ScopeTrait;
    /** @param non-empty-string $id */
    public function __construct(string $id) { $this->setIdentifier($id); }
}
