<?php
declare(strict_types=1);
namespace Websonette\ApplicationMcpServer\Integration;

use Websonette\ApplicationMcpServer\Content\Catalog;
use Websonette\ApplicationMcpServer\Tool\Registry;

interface ToolProvider
{
    public function register(Registry $tools, Catalog $content): void;
}
