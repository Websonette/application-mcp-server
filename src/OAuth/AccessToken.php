<?php
declare(strict_types=1);
namespace Websonette\ApplicationMcpServer\OAuth;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\Traits\AccessTokenTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\TokenEntityTrait;

final class AccessToken implements AccessTokenEntityInterface
{
    use AccessTokenTrait;
    use EntityTrait;
    use TokenEntityTrait;

    public function __construct(private readonly string $resource, private readonly string $issuer)
    {
        if ($resource === '' || $issuer === '') { throw new \InvalidArgumentException('Token resource and issuer are required.'); }
    }

    // League's default audience is client_id. MCP requires the resource audience instead.
    public function toString(): string
    {
        $this->initJwtConfiguration();
        /** @var non-empty-string $issuer */
        $issuer = $this->issuer;
        /** @var non-empty-string $resource */
        $resource = $this->resource;
        return $this->jwtConfiguration->builder()
            ->issuedBy($issuer)
            ->permittedFor($resource)
            ->identifiedBy($this->getIdentifier())
            ->issuedAt(new \DateTimeImmutable())
            ->canOnlyBeUsedAfter(new \DateTimeImmutable())
            ->expiresAt($this->getExpiryDateTime())
            ->relatedTo($this->getUserIdentifier() ?? $this->getClient()->getIdentifier())
            ->withClaim('client_id', $this->getClient()->getIdentifier())
            ->withClaim('scopes', $this->getScopes())
            ->getToken($this->jwtConfiguration->signer(), $this->jwtConfiguration->signingKey())
            ->toString();
    }
}
