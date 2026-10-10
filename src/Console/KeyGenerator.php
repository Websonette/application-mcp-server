<?php
declare(strict_types=1);
namespace Websonette\ApplicationMcpServer\Console;

final class KeyGenerator
{
    public function generate(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Cannot create key directory.');
        }
        $directory = realpath($directory);
        if ($directory === false) { throw new \RuntimeException('Cannot resolve key directory.'); }
        foreach (['private.pem', 'public.pem', 'encryption.key'] as $name) {
            if (file_exists($directory . '/' . $name) || is_link($directory . '/' . $name)) {
                throw new \RuntimeException('Refusing to overwrite existing keys.');
            }
        }
        $key = openssl_pkey_new(['private_key_bits' => 3072, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false || !openssl_pkey_export($key, $private)) { throw new \RuntimeException('RSA key generation failed.'); }
        $details = openssl_pkey_get_details($key);
        if ($details === false) { throw new \RuntimeException('Public key generation failed.'); }
        foreach (['private.pem' => $private, 'public.pem' => $details['key'], 'encryption.key' => bin2hex(random_bytes(32))] as $name => $content) {
            $path = $directory . '/' . $name;
            $file = fopen($path, 'x');
            if ($file === false) { throw new \RuntimeException('Cannot exclusively create key file.'); }
            try {
                if (!chmod($path, 0600)) { throw new \RuntimeException('Cannot restrict key file permissions.'); }
                if (fwrite($file, $content) !== strlen($content)) { throw new \RuntimeException('Cannot write key file.'); }
            } finally { fclose($file); }
        }
    }
}
