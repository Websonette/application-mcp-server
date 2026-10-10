<?php
declare(strict_types=1);
namespace Websonette\ApplicationMcpServer\Security;

final class Scopes
{
    /** @param list<string> $scopes */
    public static function validate(array $scopes): void
    {
        foreach ($scopes as $scope) {
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9:_.-]{0,127}$/D', $scope)) {
                throw new \InvalidArgumentException('Scopes must be non-empty, header-safe identifiers.');
            }
        }
    }
}
