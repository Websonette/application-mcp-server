<?php
declare(strict_types=1);
use Websonette\ApplicationMcpServer\Content\Catalog;
use Websonette\ApplicationMcpServer\Integration\ToolProvider;
use Websonette\ApplicationMcpServer\Persistence\Store;
use Websonette\ApplicationMcpServer\Security\Principal;
use Websonette\ApplicationMcpServer\Tool\Definition;
use Websonette\ApplicationMcpServer\Tool\Registry;
use Websonette\ApplicationMcpServer\Tool\Risk;
use Websonette\ApplicationMcpServer\Tool\ToolException;

final readonly class DemoTools implements ToolProvider
{
    public function __construct(private Store $store) {}
    public function register(Registry $tools, Catalog $content): void
    {
        $authorize = static function (Principal $principal): void {
            // Replace this with current user + tenant + object ACL checks in the real application.
            if ($principal->userId !== 'demo-user') { throw new ToolException('access_denied', 'Only the demo user can access demo properties.'); }
        };
        $tools->add(new Definition('properties_get', 'Read an example draft property.', [
            'type' => 'object', 'properties' => ['id' => ['type' => 'integer', 'const' => 1]], 'required' => ['id'],
        ], ['properties:read'], Risk::Read, function (array $args, Principal $p) use ($authorize): array {
            $authorize($p);
            return $this->store->row('SELECT * FROM demo_properties WHERE id = :id', ['id' => (int) $args['id']]) ?? throw new ToolException('not_found', 'Property not found.');
        }));
        $tools->add(new Definition('properties_update_draft', 'Update a draft title. Does not publish it.', [
            'type' => 'object', 'properties' => ['id' => ['type' => 'integer', 'const' => 1], 'expected_version' => ['type' => 'integer', 'minimum' => 1],
                'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255]], 'required' => ['id', 'expected_version', 'title'],
        ], ['properties:write'], Risk::Write, function (array $args, Principal $p) use ($authorize): array {
            $authorize($p);
            $changed = $this->store->execute('UPDATE demo_properties SET title = :title, version = version + 1 WHERE id = :id AND version = :version AND published = 0',
                ['title' => (string) $args['title'], 'id' => (int) $args['id'], 'version' => (int) $args['expected_version']]);
            if ($changed !== 1) { throw ToolException::versionConflict(); }
            return ['id' => $args['id'], 'version' => (int) $args['expected_version'] + 1, 'status' => 'draft'];
        }));
        $tools->add(new Definition('properties_publish', 'Publish a property after exact human approval.', [
            'type' => 'object', 'properties' => ['id' => ['type' => 'integer', 'const' => 1], 'expected_version' => ['type' => 'integer', 'minimum' => 1]],
            'required' => ['id', 'expected_version'],
        ], ['properties:publish'], Risk::Sensitive, function (array $args, Principal $p) use ($authorize): array {
            $authorize($p);
            $changed = $this->store->execute('UPDATE demo_properties SET published = 1, version = version + 1 WHERE id = :id AND version = :version AND published = 0',
                ['id' => (int) $args['id'], 'version' => (int) $args['expected_version']]);
            if ($changed !== 1) { throw ToolException::versionConflict(); }
            return ['id' => $args['id'], 'version' => (int) $args['expected_version'] + 1, 'status' => 'published'];
        }));
        $content->resource('project://profile', 'project_profile', 'Application description and business boundaries.', ['project:read'],
            static fn (Principal $p): string => 'Demo real-estate administration. Work with drafts; publication requires human approval. No production property system is connected.');
        $content->resource('project://tone-of-voice', 'tone_of_voice', 'Writing style for demo property copy.', ['project:read'],
            static fn (Principal $p): string => 'Write factual, clear Czech. Never invent dimensions, prices or legal facts.');
        $content->prompt('prepare_property_copy', 'Prepare a factual draft description.', ['project:read'],
            static fn (Principal $p): string => 'Read project profile and tone of voice, inspect the draft, propose copy, request missing facts. Save only when authorized. Publication is a separate human-approved operation.');
    }
}
