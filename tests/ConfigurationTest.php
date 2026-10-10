<?php
declare(strict_types=1);
namespace Websonette\ApplicationMcpServer\Tests;

use PHPUnit\Framework\TestCase;
use Websonette\ApplicationMcpServer\Config;
use Websonette\ApplicationMcpServer\Console\KeyGenerator;
use Websonette\ApplicationMcpServer\Console\SkillInstaller;
use Websonette\ApplicationMcpServer\Persistence\SchemaMigrator;

final class ConfigurationTest extends TestCase
{
    public function testExplicitRepeatableMigrations(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $migrator = new SchemaMigrator($pdo);
        self::assertFalse($migrator->installed());
        $migrator->migrate();
        $migrator->migrate();
        self::assertTrue($migrator->installed());
        $statement = $pdo->prepare('SELECT COUNT(*) FROM mcp_clients');
        $statement->execute();
        self::assertSame(0, (int) $statement->fetchColumn());
    }

    public function testUnsafeOriginsRejected(): void
    {
        foreach (['http://public.example', 'https://user@app.example', 'https://app.example/path', 'https://app.example?query=1'] as $url) {
            try { new Config($url); self::fail('Expected invalid URL'); }
            catch (\InvalidArgumentException $e) { self::assertStringContainsString('HTTPS origin', $e->getMessage()); }
        }
        self::assertSame('https://app.example/mcp', (new Config('https://app.example/'))->url('/mcp'));
        $this->expectException(\InvalidArgumentException::class);
        new Config('https://app.example', allowedOrigins: ['*']);
    }

    public function testSkillInstallIsExplicitIdempotentAndNeverOverwrites(): void
    {
        $root = sys_get_temp_dir() . '/websonette-skill-test-' . bin2hex(random_bytes(8));
        mkdir($root);
        try {
            $installer = new SkillInstaller();
            $planned = $installer->install($root, dryRun: true);
            self::assertFileDoesNotExist($planned[0]);
            $installed = $installer->install($root);
            self::assertSame($planned, $installed);
            self::assertSame($installed, $installer->install($root));
            self::assertStringContainsString('name: websonette-mcp', (string) file_get_contents($installed[0]));
            file_put_contents($installed[0], 'User-authored skill');
            try { $installer->install($root); self::fail('Expected conflict'); }
            catch (\RuntimeException) { self::assertSame('User-authored skill', file_get_contents($installed[0])); }
            try { $installer->install($root, '../outside'); self::fail('Expected traversal rejection'); }
            catch (\InvalidArgumentException $e) { self::assertStringContainsString('relative skill directory', $e->getMessage()); }
        } finally {
            foreach (['SKILL.md', 'references/security.md'] as $file) { unlink($root . '/.agents/skills/websonette-mcp/' . $file); }
            foreach (['/.agents/skills/websonette-mcp/references', '/.agents/skills/websonette-mcp', '/.agents/skills', '/.agents', ''] as $dir) { rmdir($root . $dir); }
        }
    }

    public function testKeysNeverOverwrite(): void
    {
        $root = sys_get_temp_dir() . '/websonette-key-test-' . bin2hex(random_bytes(8));
        try {
            $generator = new KeyGenerator();
            $generator->generate($root);
            $private = file_get_contents($root . '/private.pem');
            self::assertStringContainsString('PRIVATE KEY', (string) $private);
            try { $generator->generate($root); self::fail('Expected key conflict'); }
            catch (\RuntimeException) { self::assertSame($private, file_get_contents($root . '/private.pem')); }
        } finally {
            foreach (['private.pem', 'public.pem', 'encryption.key'] as $file) { if (is_file($root . '/' . $file)) { unlink($root . '/' . $file); } }
            if (is_dir($root)) { rmdir($root); }
        }
    }
}
