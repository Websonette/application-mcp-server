<?php
declare(strict_types=1);

namespace Websonette\ApplicationMcpServer\Tool;

final class CanonicalJson
{
    public static function hash(mixed $value): string
    {
        return hash('sha256', json_encode(self::normalize($value), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    private static function normalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as &$item) {
            $item = self::normalize($item);
        }
        return $value;
    }
}
