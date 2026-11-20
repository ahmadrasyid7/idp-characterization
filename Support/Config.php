<?php

declare(strict_types=1);

namespace Characterization;

use RuntimeException;

final class Config
{
    public string $baseUrl;
    public string $password;

    public function __construct()
    {
        $this->baseUrl = self::validateTarget(self::required('CHAR_BASE_URL'));
        if (self::required('CHAR_ALLOW_FIXTURES') !== 'yes') {
            throw new RuntimeException('CHAR_ALLOW_FIXTURES=yes is required: this suite writes and deletes test fixtures.');
        }
        $this->password = self::required('CHAR_TEST_PASSWORD');
        if (strlen($this->password) < 16) {
            throw new RuntimeException('CHAR_TEST_PASSWORD must contain at least 16 characters.');
        }
    }

    public static function required(string $name): string
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            throw new RuntimeException('Missing environment variable: '.$name);
        }

        return $value;
    }

    public static function validateTarget(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || !in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new RuntimeException('CHAR_BASE_URL must be an origin without credentials, path, query or fragment.');
        }
        $host = strtolower($parts['host']);
        if (in_array($host, ['localhost', '127.0.0.1', 'idp-local.rschooltoday.test'], true)
            && in_array($parts['scheme'], ['http', 'https'], true)) {
            return rtrim($url, '/');
        }

        throw new RuntimeException('Target refused: only the local IdP is allowed.');
    }
}
