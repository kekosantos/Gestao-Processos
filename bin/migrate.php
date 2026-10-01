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

    // Bancos que já existiam: acrescenta as colunas/índices novos sem apagar nada
    $temColuna = static function (string $tabela, string $coluna) use ($db): bool {
        $q = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $q->execute([$tabela, $coluna]); return (int) $q->fetchColumn() > 0;
    };
    $temIndice = static function (string $tabela, string $indice) use ($db): bool {
        $q = $db->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
        $q->execute([$tabela, $indice]); return (int) $q->fetchColumn() > 0;
    };
    if (!$temColuna('users', 'username')) $db->exec('ALTER TABLE users ADD COLUMN username VARCHAR(80) NULL AFTER must_change_password');
    if (!$temColuna('users', 'senha_inicial_ate')) $db->exec('ALTER TABLE users ADD COLUMN senha_inicial_ate DATETIME NULL AFTER username');
    if (!$temColuna('users', 'suporte')) $db->exec('ALTER TABLE users ADD COLUMN suporte TINYINT(1) NOT NULL DEFAULT 0 AFTER senha_inicial_ate');
    if (!$temIndice('users', 'uq_users_username')) $db->exec('CREATE UNIQUE INDEX uq_users_username ON users (username)');
    $db->exec("INSERT IGNORE INTO schema_migrations (version) VALUES ('2026-09-senha-padrao-usuario')");
    // Nome do sistema: AdvCloud. A equipe fictícia da demo passa a usar @demo.advcloud (não muda nada fora da demo)
    $db->exec("UPDATE users SET email = REPLACE(email, '@demo.lexcloud', '@demo.advcloud') WHERE email LIKE '%@demo.lexcloud'");
    echo "Migração concluída. Tabelas atualizadas sem apagar dados existentes.\n";
    // Admin da plataforma e escritório demo (só criam o que ainda não existe)
    require __DIR__ . '/seed.php';
} catch (Throwable $exception) {
    fwrite(STDERR, 'Falha na migração: ' . $exception->getMessage() . "\n");
    exit(1);
}
