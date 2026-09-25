<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Api\Model;

/**
 * @internal encoding of the payloads of {@see ItemId} and {@see Cursor}
 */
final class Base64Url
{
    /**
     * @param array<mixed> $data
     */
    public static function encodeJson(array $data): string
    {
        return rtrim(strtr(base64_encode(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
    }

    /**
     * @return array<mixed>|null null if the string is no base64url encoded JSON object
     */
    public static function decodeJson(string $encoded): array|null
    {
        $json = base64_decode(strtr($encoded, '-_', '+/'), true);
        if ($json === false) {
            return null;
        }
        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        return is_array($data) ? $data : null;
    }
}
