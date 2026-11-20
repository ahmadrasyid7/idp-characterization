<?php

declare(strict_types=1);

namespace Characterization;

use mysqli;
use RuntimeException;

final class Database
{
    public mysqli $waf;
    public mysqli $ip;
    public mysqli $audit;

    public function __construct(Config $config)
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $host = Config::required('CHAR_DB_HOST');
        // HTTP allowlisting is not enough: never accept a remote DB behind a local app.
        if (!in_array($host, ['localhost', '127.0.0.1', 'db', 'MySQL_Server_8.4.9_Auto_Generated_Server_Certificate'], true)) {
            throw new RuntimeException('Fixtures require a local Docker database host.');
        }
        foreach (['waf' => 'WAF', 'ip' => 'IP', 'audit' => 'AUDIT'] as $property => $suffix) {
            $database = Config::required('CHAR_DB_'.$suffix);
            if (!preg_match('/(?:^|_)(?:local|test)(?:_|$)/i', $database)) {
                throw new RuntimeException('Refusing database without a local/test name: '.$database);
            }
            $connection = mysqli_init();
            $connection->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10);
            $ca = getenv('CHAR_DB_SSL_CA');
            if ($ca !== false && $ca !== '') {
                if (!is_readable($ca)) {
                    throw new RuntimeException('CHAR_DB_SSL_CA is not readable.');
                }
                $connection->ssl_set(null, null, $ca, null, null);
                $connection->options(MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, true);
            }
            $connection->real_connect(
                $host,
                Config::required('CHAR_DB_USER'),
                Config::required('CHAR_DB_PASSWORD'),
                $database,
                (int) (getenv('CHAR_DB_PORT') ?: 3306)
            );
            $connection->set_charset('utf8mb4');
            $this->$property = $connection;
        }
    }

    public static function execute(mysqli $connection, string $sql, array $parameters = []): \mysqli_stmt
    {
        $statement = $connection->prepare($sql);
        if ($parameters !== []) {
            $statement->bind_param(str_repeat('s', count($parameters)), ...$parameters);
        }
        $statement->execute();

        return $statement;
    }

    public static function rows(mysqli $connection, string $sql, array $parameters = []): array
    {
        return self::execute($connection, $sql, $parameters)->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public static function insert(mysqli $connection, string $table, array $values): int
    {
        self::execute($connection, 'INSERT INTO '.$table.' (`'.implode('`, `', array_keys($values)).'`) VALUES ('
            .implode(', ', array_fill(0, count($values), '?')).')', array_values($values));

        return (int) $connection->insert_id;
    }

    public function close(): void
    {
        $this->waf->close();
        $this->ip->close();
        $this->audit->close();
    }
}
