<?php
declare(strict_types=1);

namespace LexCloud\Support;

final class Http
{
    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public static function path(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        return is_string($path) && $path !== '' ? rtrim($path, '/') ?: '/' : '/';
    }

    public static function json(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }
        if (strlen($raw) > 1048576) {
            self::respond(['error' => 'Corpo da requisição excede o limite.'], 413);
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            self::respond(['error' => 'JSON inválido.'], 400);
        }
        return $data;
    }

    public static function respond(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public static function fail(string $message, int $status = 400, array $extra = []): never
    {
        self::respond(array_merge(['error' => $message], $extra), $status);
    }

    public static function requiredString(array $input, string $key, int $max = 255): string
    {
        $value = trim((string) ($input[$key] ?? ''));
        if ($value === '' || mb_strlen($value) > $max) {
            self::fail("O campo {$key} é obrigatório e deve ter até {$max} caracteres.");
        }
        return $value;
    }

    public static function nullableString(array $input, string $key, int $max = 2000): ?string
    {
        $value = trim((string) ($input[$key] ?? ''));
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $max) {
            self::fail("O campo {$key} excede o tamanho permitido.");
        }
        return $value;
    }

    public static function dateOrNull(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $date = (string) $value;
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            self::fail('Data inválida. Use o formato AAAA-MM-DD.');
        }
        return $date;
    }

    public static function positiveId(string $value): int
    {
        if (!ctype_digit($value) || (int) $value < 1) {
            self::fail('Identificador inválido.', 404);
        }
        return (int) $value;
    }
}
