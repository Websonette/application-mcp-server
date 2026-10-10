<?php
declare(strict_types=1);
namespace Websonette\ApplicationMcpServer\Tests\Support;

use Psr\Clock\ClockInterface;
final class TestClock implements ClockInterface
{
    public int $offset = 0;
    public function now(): \DateTimeImmutable { return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+' . $this->offset . ' seconds'); }
}
