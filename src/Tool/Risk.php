<?php
declare(strict_types=1);

namespace Websonette\ApplicationMcpServer\Tool;

enum Risk: string
{
    case Read = 'read';
    case Write = 'write';
    case Sensitive = 'sensitive';
}
