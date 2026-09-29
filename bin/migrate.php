<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use LexCloud\Support\Database;

try {
    $db = Database::connection();
    $sql = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
    if ($sql === false) {
        throw new RuntimeException('Arquivo de esquema não encontrado.');
    }
    $statements = array_filter(array_map('trim', explode(';', $sql)), static fn(string $item): bool => $item !== '');
    foreach ($statements as $statement) {
        $db->exec($statement);
    }
    $db->exec("INSERT IGNORE INTO schema_migrations (version) VALUES ('2026-09-lexcloud-mvp')");
    echo "Migração concluída. Tabelas atualizadas sem apagar dados existentes.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Falha na migração: ' . $exception->getMessage() . "\n");
    exit(1);
}
