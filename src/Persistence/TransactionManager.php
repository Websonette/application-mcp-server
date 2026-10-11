<?php
declare(strict_types=1);
namespace Websonette\ApplicationMcpServer\Persistence;

interface TransactionManager
{
    /**
     * @template T
     * @param \Closure(): T $operation
     * @return T
     */
    public function run(\Closure $operation): mixed;
}
