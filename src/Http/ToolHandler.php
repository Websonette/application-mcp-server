<?php
declare(strict_types=1);

namespace Websonette\ApplicationMcpServer\Http;

use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\ToolHandlerInterface;
use Websonette\ApplicationMcpServer\Security\Principal;
use Websonette\ApplicationMcpServer\Tool\Dispatcher;
use Websonette\ApplicationMcpServer\Tool\ToolException;

final readonly class ToolHandler implements ToolHandlerInterface
{
    public function __construct(private Dispatcher $dispatcher, private string $name, private Principal $principal, private string $requestId)
    {
    }

    public function execute(array $arguments, ClientGateway $gateway): CallToolResult
    {
        try {
            $result = $this->dispatcher->call($this->name, $arguments, $this->principal, $this->requestId);
            return new CallToolResult([new TextContent(json_encode((object) $result, JSON_THROW_ON_ERROR))], false, (object) $result);
        } catch (ToolException $e) {
            return new CallToolResult([new TextContent(json_encode(['code' => $e->errorCode, 'message' => $e->getMessage()], JSON_THROW_ON_ERROR))], true);
        } catch (\Throwable) {
            return new CallToolResult([new TextContent('{"code":"internal_error","message":"The operation failed. Contact the application administrator with the request ID."}')], true);
        }
    }
}
