<?php
declare(strict_types=1);
namespace Websonette\ApplicationMcpServer\Console;

final class SkillInstaller
{
    /**
     * Explicit installation only. The destination is project-owned, not vendor or a global profile.
     * @return list<string> Installed or already-identical file paths.
     */
    public function install(string $projectRoot, string $relativeTarget = '.agents/skills', bool $dryRun = false): array
    {
        $root = realpath($projectRoot);
        $target = str_replace('\\', '/', $relativeTarget);
        if ($root === false || !is_dir($root) || $target === '' || preg_match('~(^/|^[A-Za-z]:|(^|/)\.\.?(/|$))~', $target)) {
            throw new \InvalidArgumentException('Use an existing project root and a relative skill directory without traversal.');
        }
        $destination = $root;
        foreach (explode('/', $target . '/websonette-mcp') as $component) {
            if ($component === '') { throw new \InvalidArgumentException('Empty path component.'); }
            $destination .= '/' . $component;
            if (is_link($destination) || (file_exists($destination) && !is_dir($destination))) {
                throw new \RuntimeException('Skill installation refuses symlinks and non-directory parents.');
            }
        }
        $source = dirname(__DIR__, 2) . '/resources/skills/websonette-mcp';
        $files = ['SKILL.md', 'references/security.md'];
        $paths = [];
        foreach ($files as $file) {
            $path = $destination . '/' . $file;
            $content = file_get_contents($source . '/' . $file);
            if ($content === false) { throw new \RuntimeException('Packaged skill resource missing.'); }
            $parent = dirname($path);
            if (is_link($parent) || is_link($path) || (file_exists($parent) && !is_dir($parent))) {
                throw new \RuntimeException('Skill installation refuses symlinks.');
            }
            if (file_exists($path) && (!is_file($path) || file_get_contents($path) !== $content)) {
                throw new \RuntimeException('Existing skill differs; review and update it manually. Nothing was overwritten.');
            }
            $paths[] = $path;
        }
        if ($dryRun) { return $paths; }
        foreach ($files as $index => $file) {
            $path = $paths[$index];
            if (is_file($path)) { continue; }
            if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0755, true) && !is_dir(dirname($path))) {
                throw new \RuntimeException('Cannot create skill directory.');
            }
            $output = fopen($path, 'x');
            $content = file_get_contents($source . '/' . $file);
            if ($output === false || $content === false) { throw new \RuntimeException('Cannot install skill file.'); }
            try {
                if (fwrite($output, $content) !== strlen($content)) { throw new \RuntimeException('Cannot write skill file.'); }
            } finally { fclose($output); }
        }
        return $paths;
    }
}
