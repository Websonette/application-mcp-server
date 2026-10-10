<?php
declare(strict_types=1);

namespace Websonette\ApplicationMcpServer\Tool;

final class ToolException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public static function versionConflict(): self
    {
        return new self('version_conflict', 'The object changed. Read its current version before retrying.');
    }
}
