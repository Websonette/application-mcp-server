<?php
declare(strict_types=1);
namespace Websonette\ApplicationMcpServer\OAuth;

use Websonette\ApplicationMcpServer\Security\ScopeDefinition;

final readonly class HtmlConsentRenderer implements ConsentRenderer
{
    public function __construct(private string $locale = 'en')
    {
        if (!in_array($locale, ['en', 'cs'], true)) { throw new \InvalidArgumentException('Consent locale must be en or cs.'); }
    }
    private function t(string $en, string $cs): string { return $this->locale === 'cs' ? $cs : $en; }
    public function login(string $token, bool $failed): string
    {
        return $this->layout('<h1>' . $this->t('Sign in', 'Přihlášení') . '</h1>'
            . ($failed ? '<p role="alert">' . $this->t('Sign-in failed.', 'Přihlášení se nezdařilo.') . '</p>' : '')
            . '<form method="post">' . $this->token($token) . '<input type="hidden" name="action" value="login">'
            . '<label>' . $this->t('Username', 'Jméno') . ' <input name="username" autocomplete="username" required></label>'
            . '<label>' . $this->t('Password', 'Heslo') . ' <input name="password" type="password" autocomplete="current-password" required></label>'
            . '<button type="submit">' . $this->t('Continue', 'Pokračovat') . '</button></form>');
    }
    public function consent(string $clientName, string $clientId, array $scopes, string $token): string
    {
        $items = '';
        foreach ($scopes as $scope) {
            $items .= '<li><strong>' . self::e($scope->label) . '</strong> <code>' . self::e($scope->id)
                . '</code><p>' . self::e($scope->description) . '</p></li>';
        }
        return $this->layout('<h1>' . $this->t('Allow access?', 'Povolit přístup?') . '</h1><p>'
            . self::e($clientName) . '</p><code>' . self::e($clientId) . '</code><p>'
            . $this->t('A client name alone does not verify its identity. Only allow clients you trust.',
                'Samotný název klienta neověřuje jeho identitu. Povolujte jen klienty, kterým důvěřujete.')
            . '</p><ul>' . $items . '</ul><form method="post">' . $this->token($token)
            . '<button name="action" value="approve">' . $this->t('Allow', 'Povolit') . '</button>'
            . '<button name="action" value="deny">' . $this->t('Deny', 'Zamítnout') . '</button></form>');
    }
    public function message(string $message): string { return $this->layout('<p role="alert">' . self::e($message) . '</p>'); }
    private function token(string $token): string { return '<input type="hidden" name="csrf" value="' . self::e($token) . '">'; }
    private function layout(string $body): string
    {
        return '<!doctype html><html lang="' . $this->locale . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>OAuth</title><style>body{font-family:system-ui;max-width:40rem;margin:3rem auto;padding:0 1rem}label{display:block;margin:1rem 0}button{padding:.5rem 1rem;margin-right:1rem}code{overflow-wrap:anywhere}</style></head><body>' . $body . '</body></html>';
    }
    private static function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
