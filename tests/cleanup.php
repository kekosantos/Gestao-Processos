<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use LexCloud\Support\Database;

$ids = array_values(array_unique(array_filter(array_map('intval', array_slice($argv, 1)), static fn(int $id): bool => $id > 0)));
if (!$ids) {
    echo "Nenhum fixture informado.\n";
    exit(0);
}
if (count($ids) > 4) {
    fwrite(STDERR, "Recusado: quantidade de tenants acima do limite de limpeza.\n");
    exit(2);
}

$db = Database::connection();
try {
    $db->beginTransaction();
    foreach ($ids as $tenantId) {
        $check = $db->prepare('SELECT name FROM tenants WHERE id=? FOR UPDATE');
        $check->execute([$tenantId]);
        $name = $check->fetchColumn();
        if ($name === false) {
            continue;
        }
        if (!str_starts_with(strtoupper((string)$name), 'LEXCLOUD TEST-')) {
            throw new RuntimeException('Tenant fora do prefixo LexCloud TEST; nenhum tenant será excluído.');
        }
        foreach (['case_activities','documents','finance_entries','events','tasks','legal_cases','clients','audit_logs','users','tenant_settings'] as $table) {
            $stmt = $db->prepare("DELETE FROM {$table} WHERE tenant_id=?");
            $stmt->execute([$tenantId]);
        }
        $db->prepare('DELETE FROM tenants WHERE id=?')->execute([$tenantId]);
    }
    $db->commit();
    echo 'Fixtures temporários excluídos com segurança: ' . count($ids) . " tenant(s).\n";
} catch (Throwable $exception) {
    if ($db->inTransaction()) { $db->rollBack(); }
    fwrite(STDERR, 'Limpeza cancelada sem commit: ' . $exception->getMessage() . "\n");
    exit(1);
}
