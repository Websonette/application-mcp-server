<?php
declare(strict_types=1);
namespace Websonette\ApplicationMcpServer\OAuth;
use League\OAuth2\Server\Entities\UserEntityInterface;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
final class User implements UserEntityInterface
{
    use EntityTrait;
    /** @param non-empty-string $id */
    public function __construct(string $id) { $this->setIdentifier($id); }
}
