<?php
declare(strict_types=1);

namespace LexCloud\Support;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $dsn = getenv('DATABASE_URL') ?: '';
        $user = getenv('MYSQL_USER') ?: '';
        $password = getenv('MYSQL_PASSWORD') ?: '';
        $tlsRequired = false;
        $verifyCertificate = true;

        if ($dsn !== '') {
            if (preg_match('/^mysql:(?:host|dbname|unix_socket)=/i', $dsn) === 1) {
                $pdoDsn = $dsn;
            } else {
                $parts = parse_url($dsn);
                if (!is_array($parts) || !isset($parts['host'])) {
                    throw new RuntimeException('DATABASE_URL inválida. Use mysql://usuario:senha@host:3306/banco.');
                }
                $scheme = strtolower((string) ($parts['scheme'] ?? 'mysql'));
                if (!in_array($scheme, ['mysql', 'mysqls'], true)) {
                    throw new RuntimeException('DATABASE_URL deve usar MySQL.');
                }
                $tlsRequired = $scheme === 'mysqls';
                $query = [];
                parse_str((string) ($parts['query'] ?? ''), $query);
                if (isset($query['ssl'])) {
                    $ssl = is_string($query['ssl']) ? json_decode($query['ssl'], true) : $query['ssl'];
                    $tlsRequired = $tlsRequired || !in_array($ssl, [false, 0, '0', 'false', ''], true);
                    if (is_array($ssl) && array_key_exists('rejectUnauthorized', $ssl)) {
                        $verifyCertificate = (bool) $ssl['rejectUnauthorized'];
                    }
                }
                $database = ltrim((string) ($parts['path'] ?? ''), '/');
                if ($database === '') {
                    throw new RuntimeException('DATABASE_URL precisa informar o banco.');
                }
                $pdoDsn = sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                    $parts['host'],
                    (int) ($parts['port'] ?? 3306),
                    rawurldecode($database)
                );
                $user = rawurldecode((string) ($parts['user'] ?? ''));
                $password = rawurldecode((string) ($parts['pass'] ?? ''));
            }
        } else {
            $host = getenv('MYSQL_HOST') ?: '';
            $database = getenv('MYSQL_DATABASE') ?: '';
            if ($host === '' || $database === '' || $user === '') {
                throw new RuntimeException('Banco não configurado. Defina DATABASE_URL ou MYSQL_HOST, MYSQL_DATABASE, MYSQL_USER e MYSQL_PASSWORD.');
            }
            $port = (int) (getenv('MYSQL_PORT') ?: 3306);
            $pdoDsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database);
        }

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_TIMEOUT => 10,
        ];
        if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
            $options[\PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci";
        }
        $sslCa = getenv('MYSQL_SSL_CA') ?: '';
        if ($tlsRequired || $sslCa !== '') {
            $sslCa = $sslCa !== '' ? $sslCa : '/etc/ssl/certs/ca-certificates.crt';
            if (!is_file($sslCa)) {
                throw new RuntimeException('A conexão MySQL exige TLS, mas o arquivo de certificados CA não está disponível. Configure MYSQL_SSL_CA.');
            }
            if (!defined('PDO::MYSQL_ATTR_SSL_CA')) {
                throw new RuntimeException('O PHP precisa da extensão PDO MySQL com suporte a TLS.');
            }
            $options[\PDO::MYSQL_ATTR_SSL_CA] = $sslCa;
            if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                $options[\PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = $verifyCertificate;
            }
        }

        try {
            self::$connection = new PDO($pdoDsn, $user, $password, $options);
        } catch (PDOException $exception) {
            error_log('LexCloud database connection failed: ' . $exception->getCode());
            throw new RuntimeException('Não foi possível conectar ao banco. Confira as variáveis de ambiente e a disponibilidade do MySQL.');
        }

        return self::$connection;
    }
}
