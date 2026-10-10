<?php
declare(strict_types=1);
namespace Websonette\ApplicationMcpServer\OAuth;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\Traits\ClientTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
final class Client implements ClientEntityInterface
{
    use ClientTrait;
    use EntityTrait;
    /**
     * @param non-empty-string $id
     * @param list<string> $redirects
     */
    public function __construct(string $id, string $name, array $redirects, bool $confidential)
    {
        $this->setIdentifier($id);
        $this->name = $name;
        $this->redirectUri = $redirects;
        $this->isConfidential = $confidential;
    }
}
