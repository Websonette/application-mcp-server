<?php
declare(strict_types=1);
namespace Websonette\ApplicationMcpServer\OAuth;

final readonly class KeyMaterial
{
    public string $privateKey;
    public string $publicKey;
    private string $encryptionFile;

    public function __construct(string $directory)
    {
        $this->privateKey = rtrim($directory, '/\\') . '/private.pem';
        $this->publicKey = rtrim($directory, '/\\') . '/public.pem';
        $this->encryptionFile = rtrim($directory, '/\\') . '/encryption.key';
    }
    public function privateKey(): string { return $this->privateKey; }
    public function publicKey(): string { return $this->publicKey; }
    public function encryptionKey(): string
    {
        $value = @file_get_contents($this->encryptionFile);
        if (!is_string($value) || strlen(trim($value)) < 32) {
            throw new \RuntimeException('OAuth encryption key is missing or invalid. Generate keys with websonette-mcp keys.');
        }
        return trim($value);
    }
}
