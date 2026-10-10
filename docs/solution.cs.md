# Souhrn řešení

Balíček `websonette/application-mcp-server` přidává MCP rozhraní do konkrétní PHP aplikace. Každá aplikace má vlastní endpoint, OAuth issuer, uživatele, klienty a databázi. Centrální Websonette přihlašovací služba není potřeba.

## Základ

Oficiální MCP SDK obsluhuje protokol a Streamable HTTP na `/mcp`. PHP/Nette jsou implementační prostředí, nikoli jiný typ MCP protokolu. Název neobsahuje transport: přesto musí konkrétní klient podporovat použité HTTP, verzi protokolu a způsob přihlášení. Stdio server v této verzi přibalen není.

PHP 8.3+, PSR-7 rozhraní, Nette DI extension, explicitní SQLite/MySQL migrace, PHPUnit a PHPStan level 8 odpovídají stylu ostatních Websonette balíčků. CI využívá sdílený Websonette workflow.

## Přístup a bezpečné akce

Produkční přístup používá autorizační kód se S256 PKCE, lidský souhlas v hostitelské aplikaci, krátkodobé podepsané JWT pro konkrétní MCP resource a rotované refresh tokeny. Identifikátory tokenů se ukládají hashované a přístup lze okamžitě odvolat. Neprůhledné ručně vydané tokeny jsou pouze pro explicitní lokální vývoj.

Klienti jsou předem registrovaní administrátorem s přesnými callback URL a scopes. Automatická registrace a vzdálené načítání klientských metadat nejsou hotové. Při replay refresh tokenu se v 0.1 záměrně odvolají všechny granty daného klienta; jde o konzervativnější, širší reakci než odvolání jedné grantové rodiny.

Čtecí tool pouze čte. Zápis vyžaduje idempotency key a stejný opakovaný požadavek vrací uložený výsledek. Citlivá operace navíc vyžaduje jednorázový lidský souhlas vázaný na klienta, uživatele, tool a přesné parametry. Hostitelská aplikace ověřuje aktuální uživatelská, tenantová a objektová oprávnění i očekávanou verzi záznamu.

Atomické záruky platí pro stejnou PDO transakci. Publikování do externího systému, e-mail či fronta potřebují aplikační outbox a idempotentní worker; databázový rollback je nevrátí.

## Kontext, administrace a vývoj

Resources nesou profil projektu a tone of voice, prompts pracovní postupy. Obsah je kontext, nikoli oprávnění. Administrace má služby pro registraci klientů, jejich přehled, aktivitu, tokeny, revokaci a odpojení; produkční UI, přihlášení a CSRF ochranu dodá aplikace.

Audit neukládá bearer tokeny, surové parametry ani detaily výjimek. Uložené výsledky pro idempotenci ale mohou obsahovat osobní data, proto vyžadují retenční a přístupovou politiku.

Balíček přibaluje přenositelný vývojový Agent Skill. Instalace je ruční a nezasahuje globální konfiguraci ani nepřepisuje odlišný existující skill. Composer install nemigruje databázi, nevytváří klíče ani neinstaluje instrukce.

Demo ukazuje práci s konceptem nemovitosti, ale není napojením na reality-profesionalne.cz. Produkční integrace konkrétní aplikace, Packagist registrace a další možnosti jsou odlišené v [roadmapě](roadmap.md).
