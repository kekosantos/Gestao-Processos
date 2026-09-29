<?php
declare(strict_types=1);

namespace LexCloud\Support;

/**
 * Sessões gravadas no banco (tabela sessions): sobrevivem a deploys e reinícios do Render/Fly.
 * Se o banco falhar, o motivo vai para o log e a página continua abrindo (sem sessão persistente),
 * em vez de derrubar tudo com erro fatal.
 */
final class DbSessionHandler implements \SessionHandlerInterface
{
    private static function falha(string $onde, \Throwable $e): void
    {
        error_log('LexCloud sessão (' . $onde . ') falhou: ' . $e->getMessage());
    }

    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }

    public function read(string $id): string|false
    {
        try {
            $s = Database::connection()->prepare('SELECT data FROM sessions WHERE id=? AND expires_at > UTC_TIMESTAMP()');
            $s->execute([$id]);
            return (string) ($s->fetchColumn() ?: '');
        } catch (\Throwable $e) { self::falha('leitura', $e); return ''; }
    }

    public function write(string $id, string $data): bool
    {
        try {
            return Database::connection()->prepare('INSERT INTO sessions (id,data,expires_at) VALUES (?,?,UTC_TIMESTAMP() + INTERVAL 8 HOUR) ON DUPLICATE KEY UPDATE data=VALUES(data), expires_at=VALUES(expires_at)')->execute([$id, $data]);
        } catch (\Throwable $e) { self::falha('gravação', $e); return true; } // true: evita aviso do PHP na resposta; o motivo já está no log
    }

    public function destroy(string $id): bool
    {
        try { return Database::connection()->prepare('DELETE FROM sessions WHERE id=?')->execute([$id]); }
        catch (\Throwable $e) { self::falha('exclusão', $e); return true; }
    }

    public function gc(int $max): int|false
    {
        try { return Database::connection()->exec('DELETE FROM sessions WHERE expires_at < UTC_TIMESTAMP()'); }
        catch (\Throwable $e) { self::falha('limpeza', $e); return 0; }
    }
}
