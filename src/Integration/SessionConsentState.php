<?php
declare(strict_types=1);
namespace Websonette\ApplicationMcpServer\Integration;

use Nette\Http\Session;
use Psr\Clock\ClockInterface;
use Websonette\ApplicationMcpServer\OAuth\ConsentState;

final readonly class SessionConsentState implements ConsentState
{
    public function __construct(private Session $session, private ClockInterface $clock) {}
    public function issue(string $binding, ?string $subject): string
    {
        $section = $this->session->getSection('websonette.mcp.consent');
        /** @var array<string, array{binding: string, subject: ?string, expires: int}> $entries */
        $entries = is_array($section->get('entries')) ? $section->get('entries') : [];
        $now = $this->clock->now()->getTimestamp();
        $entries = array_filter($entries, static fn (array $entry): bool => $entry['expires'] > $now);
        $entries = array_slice($entries, -9, null, true);
        $token = bin2hex(random_bytes(32));
        $entries[hash('sha256', $token)] = ['binding' => $binding, 'subject' => $subject, 'expires' => $now + 600];
        $section->set('entries', $entries);
        return $token;
    }
    public function consume(string $token, string $binding, ?string $subject): bool
    {
        $section = $this->session->getSection('websonette.mcp.consent');
        /** @var array<string, array{binding: string, subject: ?string, expires: int}> $entries */
        $entries = is_array($section->get('entries')) ? $section->get('entries') : [];
        $key = hash('sha256', $token);
        $entry = $entries[$key] ?? null;
        if (!is_array($entry) || $entry['expires'] <= $this->clock->now()->getTimestamp()
            || $entry['subject'] !== $subject || !hash_equals($entry['binding'], $binding)) { return false; }
        unset($entries[$key]);
        $section->set('entries', $entries);
        return true;
    }
}
