<?php
declare(strict_types=1);

namespace LexCloud\Support;

/** Sessões gravadas no banco (tabela sessions): sobrevivem a deploys e reinícios do Render/Fly. */
final class DbSessionHandler implements \SessionHandlerInterface
{
    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }
    public function read(string $id): string|false
    {
        $s = Database::connection()->prepare('SELECT data FROM sessions WHERE id=? AND expires_at > UTC_TIMESTAMP()');
        $s->execute([$id]);
        return (string) ($s->fetchColumn() ?: '');
    }
    public function write(string $id, string $data): bool
    {
        return Database::connection()->prepare('INSERT INTO sessions (id,data,expires_at) VALUES (?,?,UTC_TIMESTAMP() + INTERVAL 8 HOUR) ON DUPLICATE KEY UPDATE data=VALUES(data), expires_at=VALUES(expires_at)')->execute([$id, $data]);
    }
    public function destroy(string $id): bool { return Database::connection()->prepare('DELETE FROM sessions WHERE id=?')->execute([$id]); }
    public function gc(int $max): int|false { return Database::connection()->exec('DELETE FROM sessions WHERE expires_at < UTC_TIMESTAMP()'); }
}
