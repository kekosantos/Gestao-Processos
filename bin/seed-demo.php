<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use LexCloud\Support\Database;

$environment = strtolower(trim((string) (getenv('APP_ENV') ?: '')));
if (!in_array($environment, ['dev', 'development', 'local', 'test'], true)) {
    fwrite(STDERR, "Seed permitida somente em ambiente explícito de desenvolvimento ou teste.\n");
    exit(1);
}

try {
    $db = Database::connection();
    $exists = $db->prepare('SELECT id FROM tenants WHERE slug = ? LIMIT 1');
    $exists->execute(['lexcloud-demo']);
    if ($exists->fetch()) {
        echo "Tenant de demonstração já existe; nenhum dado foi alterado.\n";
        exit(0);
    }

    $db->beginTransaction();
    $stmt = $db->prepare("INSERT INTO tenants (name, slug, plan, status) VALUES (?, ?, 'trial', 'trial')");
    $stmt->execute(['Escritório Modelo LexCloud', 'lexcloud-demo']);
    $tenantId = (int) $db->lastInsertId();
    $user = $db->prepare('INSERT INTO users (tenant_id, name, email, password_hash, role) VALUES (?, ?, ?, ?, ?)');
    $user->execute([$tenantId, 'Marina Costa', 'demo@lexcloud.app', password_hash('Demo@123!', PASSWORD_DEFAULT), 'owner']);
    $ownerId = (int) $db->lastInsertId();
    $user->execute([$tenantId, 'Rafael Nunes', 'rafael@lexcloud.app', password_hash('Demo@123!', PASSWORD_DEFAULT), 'lawyer']);
    $lawyerId = (int) $db->lastInsertId();

    $clients = $db->prepare('INSERT INTO clients (tenant_id, name, kind, email, phone, city, notes) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $clientData = [
        ['Grupo Horizonte (exemplo)', 'pessoa_juridica', 'contato@horizonte.example', '(11) 3000-0100', 'São Paulo', 'Cadastro fictício para demonstração.'],
        ['Ana Martins (exemplo)', 'pessoa_fisica', 'ana.martins@example', '(11) 90000-0101', 'Campinas', 'Dados inteiramente fictícios.'],
        ['Vértice Serviços (exemplo)', 'pessoa_juridica', 'juridico@vertice.example', '(21) 3000-0200', 'Rio de Janeiro', 'Cadastro fictício para demonstração.'],
    ];
    $clientIds = [];
    foreach ($clientData as $row) {
        $clients->execute(array_merge([$tenantId], $row));
        $clientIds[] = (int) $db->lastInsertId();
    }

    $cases = $db->prepare('INSERT INTO legal_cases (tenant_id, client_id, case_number, area, case_type, subject, court, opposing_party, status, priority, owner_id, opened_on, next_deadline, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $caseData = [
        [$clientIds[0], '0000000-00.2026.8.26.0001', 'Cível', 'Contencioso', 'Revisão contratual', 'TJSP · Foro Central', 'Fornecedor Alfa (exemplo)', 'active', 'high', $lawyerId, date('Y-m-d', strtotime('-42 days')), date('Y-m-d', strtotime('+2 days')), 'Caso fictício para demonstração do fluxo.'],
        [$clientIds[1], '0000000-00.2026.8.26.0002', 'Trabalhista', 'Consultivo', 'Acordo trabalhista', 'TRT-15 · Campinas', 'Empresa Beta (exemplo)', 'active', 'normal', $ownerId, date('Y-m-d', strtotime('-22 days')), date('Y-m-d', strtotime('+5 days')), 'Caso fictício para demonstração do fluxo.'],
        [$clientIds[2], '0000000-00.2026.8.26.0003', 'Empresarial', 'Contencioso', 'Cobrança empresarial', 'TJRJ · Capital', 'Cliente Gamma (exemplo)', 'active', 'low', $lawyerId, date('Y-m-d', strtotime('-80 days')), null, 'Caso fictício para demonstração do fluxo.'],
    ];
    $caseIds = [];
    foreach ($caseData as $row) {
        $cases->execute(array_merge([$tenantId], $row));
        $caseIds[] = (int) $db->lastInsertId();
    }

    $tasks = $db->prepare('INSERT INTO tasks (tenant_id, case_id, client_id, title, description, assignee_id, priority, status, due_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $tasks->execute([$tenantId, $caseIds[0], $clientIds[0], 'Revisar documentos para réplica', 'Conferir os anexos do caso e consolidar pontos para a revisão.', $lawyerId, 'high', 'pending', date('Y-m-d 16:00:00', strtotime('+2 days')), $ownerId]);
    $tasks->execute([$tenantId, $caseIds[1], $clientIds[1], 'Preparar pauta de audiência', 'Organizar documentos e tópicos para reunião interna.', $ownerId, 'normal', 'pending', date('Y-m-d 10:00:00', strtotime('+5 days')), $ownerId]);
    $tasks->execute([$tenantId, $caseIds[2], $clientIds[2], 'Conferir comprovantes de pagamento', 'Solicitar conferência ao cliente no próximo atendimento.', $lawyerId, 'low', 'in_progress', date('Y-m-d 14:00:00', strtotime('+8 days')), $ownerId]);

    $events = $db->prepare('INSERT INTO events (tenant_id, case_id, title, event_type, starts_at, ends_at, location, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $events->execute([$tenantId, $caseIds[0], 'Reunião de estratégia (exemplo)', 'reuniao', date('Y-m-d 10:00:00', strtotime('+1 day')), date('Y-m-d 11:00:00', strtotime('+1 day')), 'Sala virtual', 'Evento de demonstração; não é uma audiência real.', $ownerId]);
    $events->execute([$tenantId, $caseIds[1], 'Audiência simulada (exemplo)', 'audiencia', date('Y-m-d 14:30:00', strtotime('+7 days')), date('Y-m-d 15:30:00', strtotime('+7 days')), 'TRT-15 · Campinas', 'Dados fictícios de demonstração.', $lawyerId]);

    $finance = $db->prepare('INSERT INTO finance_entries (tenant_id, case_id, client_id, entry_type, description, category, amount, status, due_date, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $finance->execute([$tenantId, $caseIds[0], $clientIds[0], 'receita', 'Honorários contratuais (exemplo)', 'Honorários', '4800.00', 'pendente', date('Y-m-d', strtotime('+12 days')), $ownerId]);
    $finance->execute([$tenantId, $caseIds[2], $clientIds[2], 'despesa', 'Custas processuais (exemplo)', 'Custas', '620.00', 'pago', date('Y-m-d', strtotime('-5 days')), $ownerId]);

    $db->prepare('INSERT INTO tenant_settings (tenant_id, timezone, language, settings_text) VALUES (?, ?, ?, ?)')->execute([$tenantId, 'America/Sao_Paulo', 'pt-BR', '{"currency":"BRL"}']);
    $db->commit();
    echo "Tenant de demonstração criado com dados fictícios. Acesso local: demo@lexcloud.app / Demo@123!\n";
} catch (Throwable $exception) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, 'Falha ao criar demo: ' . $exception->getMessage() . "\n");
    exit(1);
}
