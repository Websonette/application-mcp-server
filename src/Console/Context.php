<?php
declare(strict_types=1);
namespace Websonette\ApplicationMcpServer\Console;

use Websonette\ApplicationMcpServer\Admin\Administration;
use Websonette\ApplicationMcpServer\Config;
use Websonette\ApplicationMcpServer\Persistence\Store;
use Websonette\ApplicationMcpServer\Security\Tokens;

final readonly class Context
{
    public Administration $admin;
    public Tokens $tokens;
    public function __construct(public Config $config, public Store $store)
    {
        $this->admin = new Administration($store);
        $this->tokens = new Tokens($store, $config);
    }
}
