<?php
declare(strict_types=1);
namespace Websonette\ApplicationMcpServer\Integration;

use Nette\Http\IRequest;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Websonette\ApplicationMcpServer\Http\Handler;

/** Configure trusted proxies in Nette's HTTP request factory, not in this bridge. */
final readonly class HttpApplication
{
    public function __construct(private Handler $handler, private IRequest $request) {}
    public function run(): void
    {
        $factory = new Psr17Factory();
        $request = (new ServerRequestCreator($factory, $factory, $factory, $factory))->fromGlobals();
        $request = $request->withUri($factory->createUri((string) $this->request->getUrl()));
        $response = $this->handler->handle($request);
        http_response_code($response->getStatusCode());
        header_remove('Content-Type');
        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $index => $value) { header($name . ': ' . $value, $index === 0); }
        }
        echo (string) $response->getBody();
    }
}
