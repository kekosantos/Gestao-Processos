<?php
declare(strict_types=1);
/**
 * Dados iniciais (rodado pelo bin/migrate.php, a cada inicialização). Só cria o que AINDA NÃO existe:
 *
 *  1) Admin da plataforma (CHS): usuário ADMIN_USUARIO (padrão cleiton.santos), e-mail ADMIN_EMAIL,
 *     senha = SENHA_PADRAO (padrão "password"), troca obrigatória no primeiro acesso, vale 7 dias.
 *     Só é criado se ainda não houver nenhum admin da plataforma.
 *
 *  2) Escritório DEMO com dados fictícios, para demonstração (DEMO_DATA=false desliga).
 *     Acesso: usuário "demo" + SENHA_PADRAO (troca obrigatória no primeiro acesso).
 *     Todos os nomes, documentos e números de processo são FICTÍCIOS.
 */
if (!isset($db)) {
    require_once dirname(__DIR__) . '/app/bootstrap.php';
    $db = LexCloud\Support\Database::connection();
}
use LexCloud\Support\Security;

$senha = password_hash(Security::senhaPadrao(), PASSWORD_DEFAULT);
$dias = Security::DIAS_SENHA_INICIAL;

// ── 1) Admin da plataforma ──────────────────────────────────────────────
$usuario = strtolower(trim((string) (getenv('ADMIN_USUARIO') ?: 'cleiton.santos')));
$email = strtolower(trim((string) (getenv('ADMIN_EMAIL') ?: 'cleiton@chsconsultoria.com.br')));
$nome = trim((string) (getenv('ADMIN_NOME') ?: 'Cleiton Santos'));
$temAdmin = (int) $db->query("SELECT COUNT(*) FROM users WHERE role = 'platform_admin'")->fetchColumn();

// Recuperação: ADMIN_REDEFINIR=true devolve o PRIMEIRO admin da plataforma ao usuário ADMIN_USUARIO com a senha
// padrão (troca obrigatória). Use uma vez, entre no sistema e APAGUE a variável.
if ($temAdmin > 0 && strtolower((string) getenv('ADMIN_REDEFINIR')) === 'true') {
    $id = (int) $db->query("SELECT id FROM users WHERE role = 'platform_admin' ORDER BY id LIMIT 1")->fetchColumn();
    $ocupado = $db->prepare('SELECT COUNT(*) FROM users WHERE username = ? AND id <> ?'); $ocupado->execute([$usuario, $id]);
    if ((int) $ocupado->fetchColumn() === 0) {
        $db->prepare("UPDATE users SET username = ?, password_hash = ?, active = 1, must_change_password = 1, senha_inicial_ate = NOW() + INTERVAL {$dias} DAY WHERE id = ?")
           ->execute([$usuario, $senha, $id]);
        $db->prepare('DELETE FROM login_attempts')->execute();
        echo "ADMIN_REDEFINIR: admin da plataforma voltou para o usuário {$usuario} com a senha padrão. APAGUE a variável ADMIN_REDEFINIR.\n";
    } else {
        fwrite(STDERR, "ADMIN_REDEFINIR: o usuário {$usuario} já pertence a outra conta; nada foi alterado.\n");
    }
}

if ($temAdmin === 0 && strtolower((string) getenv('ADMIN_AUTOMATICO')) !== 'false') {
    $db->prepare("INSERT INTO users (tenant_id, name, email, username, password_hash, role, active, must_change_password, senha_inicial_ate)
                  VALUES (NULL, ?, ?, ?, ?, 'platform_admin', 1, 1, NOW() + INTERVAL {$dias} DAY)")
       ->execute([$nome, $email, $usuario, $senha]);
    echo "Admin da plataforma criado: usuário {$usuario} (senha padrão, troca obrigatória no primeiro acesso).\n";
}

// ── 2) Escritório demo ──────────────────────────────────────────────────
if (strtolower((string) getenv('DEMO_DATA')) === 'false') return;
$st = $db->prepare("SELECT id FROM tenants WHERE slug = 'demo'"); $st->execute();
if ($st->fetchColumn()) return;

$db->beginTransaction();
try {
    $db->exec("INSERT INTO tenants (name, slug, plan, status) VALUES ('Silva & Associados Advocacia (DEMO)', 'demo', 'profissional', 'active')");
    $t = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO tenant_settings (tenant_id, timezone, language, settings_text) VALUES (?,?,?,?)')
       ->execute([$t, 'America/Sao_Paulo', 'pt-BR', '{"currency":"BRL","demo":true}']);

    // Equipe: a responsável entra com "demo"; os demais são só ilustrativos (senha aleatória, não entram)
    $u = $db->prepare('INSERT INTO users (tenant_id, name, email, username, password_hash, role, active, must_change_password) VALUES (?,?,?,?,?,?,1,?)');
    $u->execute([$t, 'Dra. Helena Silva', 'helena.silva@demo.lexcloud', 'demo', $senha, 'owner', 1]);           $dona = (int) $db->lastInsertId();
    $aleatoria = fn() => password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT);
    $u->execute([$t, 'Dr. Rafael Costa', 'rafael.costa@demo.lexcloud', null, $aleatoria(), 'lawyer', 0]);        $rafael = (int) $db->lastInsertId();
    $u->execute([$t, 'Dra. Beatriz Moura', 'beatriz.moura@demo.lexcloud', null, $aleatoria(), 'lawyer', 0]);     $beatriz = (int) $db->lastInsertId();
    $u->execute([$t, 'Marina Alves', 'marina.alves@demo.lexcloud', null, $aleatoria(), 'staff', 0]);             $marina = (int) $db->lastInsertId();
    $u->execute([$t, 'Paulo Nunes', 'paulo.nunes@demo.lexcloud', null, $aleatoria(), 'finance', 0]);             $paulo = (int) $db->lastInsertId();

    // Clientes (fictícios)
    $c = $db->prepare('INSERT INTO clients (tenant_id, name, kind, email, phone, city, notes, status) VALUES (?,?,?,?,?,?,?,?)');
    $clientes = [
        ['Mariana Oliveira Santos', 'pessoa_fisica', 'mariana.oliveira@exemplo.com.br', '(27) 99812-4410', 'Vitória', 'Indicada por cliente antigo. Prefere contato por WhatsApp.'],
        ['Transportadora Rota Sul Ltda.', 'pessoa_juridica', 'juridico@rotasul.exemplo.com.br', '(27) 3322-8100', 'Serra', 'Contrato de assessoria mensal.'],
        ['Carlos Eduardo Pereira', 'pessoa_fisica', 'carlos.pereira@exemplo.com.br', '(27) 98877-1203', 'Vila Velha', 'Reclamação trabalhista contra ex-empregador.'],
        ['Padaria Pão Dourado ME', 'pessoa_juridica', 'contato@paodourado.exemplo.com.br', '(27) 3244-5566', 'Cariacica', 'Discussão tributária de ICMS.'],
        ['Fernanda Lima Rocha', 'pessoa_fisica', 'fernanda.rocha@exemplo.com.br', '(27) 99640-7788', 'Guarapari', 'Ação de alimentos e guarda.'],
    ];
    $cli = [];
    foreach ($clientes as $x) { $c->execute([$t, $x[0], $x[1], $x[2], $x[3], $x[4], $x[5], 'active']); $cli[] = (int) $db->lastInsertId(); }

    // Processos (números no formato CNJ, fictícios)
    $p = $db->prepare('INSERT INTO legal_cases (tenant_id, client_id, case_number, area, case_type, subject, court, opposing_party, status, priority, owner_id, opened_on, next_deadline, description)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $d = fn(int $dias) => date('Y-m-d', strtotime(($dias >= 0 ? '+' : '') . $dias . ' days'));
    $processos = [
        [0, '5001234-56.2026.8.08.0024', 'Cível', 'Indenização', 'Danos morais por negativação indevida', '2ª Vara Cível de Vitória', 'Banco Exemplo S.A.', 'active', 'high', $rafael, -60, 3, 'Cliente teve o nome negativado por dívida já quitada.'],
        [1, '5007788-12.2026.8.08.0048', 'Empresarial', 'Cobrança', 'Cobrança de fretes não pagos', '1ª Vara Cível da Serra', 'Comércio Atlântico Ltda.', 'active', 'normal', $dona, -45, 12, 'Notas fiscais e comprovantes de entrega anexados.'],
        [2, '0000456-78.2026.5.17.0001', 'Trabalhista', 'Reclamação trabalhista', 'Horas extras e adicional noturno', '1ª Vara do Trabalho de Vitória', 'Metalúrgica Horizonte Ltda.', 'active', 'high', $beatriz, -30, 1, 'Audiência de conciliação marcada.'],
        [3, '5012345-90.2026.4.02.5001', 'Tributário', 'Mandado de segurança', 'Exclusão do ICMS da base de cálculo', '3ª Vara Federal de Vitória', 'União (Fazenda Nacional)', 'paused', 'normal', $dona, -120, 20, 'Aguardando julgamento de tema repetitivo.'],
        [4, '5003321-44.2026.8.08.0021', 'Família', 'Alimentos', 'Fixação de alimentos e regulamentação de visitas', 'Vara de Família de Guarapari', 'Ricardo Rocha', 'active', 'normal', $rafael, -15, 7, 'Pedido de alimentos provisórios deferido.'],
    ];
    $proc = [];
    foreach ($processos as $x) {
        $p->execute([$t, $cli[$x[0]], $x[1], $x[2], $x[3], $x[4], $x[5], $x[6], $x[7], $x[8], $x[9], $d($x[10]), $d($x[11]), $x[12]]);
        $proc[] = (int) $db->lastInsertId();
    }
    $a = $db->prepare('INSERT INTO case_activities (tenant_id, case_id, user_id, activity_type, title, body) VALUES (?,?,?,?,?,?)');
    $a->execute([$t, $proc[0], $rafael, 'nota', 'Petição inicial protocolada', 'Pedido de tutela para retirada do nome dos cadastros.']);
    $a->execute([$t, $proc[0], $rafael, 'andamento', 'Tutela deferida', 'O juiz determinou a retirada da negativação em 5 dias.']);
    $a->execute([$t, $proc[2], $beatriz, 'andamento', 'Audiência designada', 'Conciliação em data próxima; cliente avisado.']);
    $a->execute([$t, $proc[4], $rafael, 'andamento', 'Alimentos provisórios', 'Fixados em 30% do salário mínimo.']);

    // Tarefas (algumas atrasadas e algumas para os próximos dias, para o painel mostrar alertas)
    $k = $db->prepare('INSERT INTO tasks (tenant_id, case_id, client_id, title, description, assignee_id, priority, status, due_at, completed_at, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $h = fn(int $dias, string $hora = '18:00:00') => date('Y-m-d', strtotime(($dias >= 0 ? '+' : '') . $dias . ' days')) . ' ' . $hora;
    $k->execute([$t, $proc[2], $cli[2], 'Preparar cálculo de horas extras', 'Planilha com base nos cartões de ponto.', $beatriz, 'high', 'in_progress', $h(1), null, $dona]);
    $k->execute([$t, $proc[0], $cli[0], 'Manifestar sobre a contestação', 'Prazo de 15 dias úteis.', $rafael, 'high', 'pending', $h(3), null, $dona]);
    $k->execute([$t, $proc[1], $cli[1], 'Juntar comprovantes de entrega', null, $marina, 'normal', 'pending', $h(-2), null, $dona]);
    $k->execute([$t, $proc[4], $cli[4], 'Enviar acordo para a cliente revisar', null, $rafael, 'normal', 'pending', $h(5), null, $dona]);
    $k->execute([$t, $proc[3], $cli[3], 'Acompanhar publicação do tema repetitivo', null, $dona, 'low', 'done', $h(-5), $h(-6, '10:00:00'), $dona]);

    // Agenda
    $e = $db->prepare('INSERT INTO events (tenant_id, case_id, title, event_type, starts_at, ends_at, location, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?)');
    $e->execute([$t, $proc[2], 'Audiência de conciliação — Carlos Eduardo', 'audiencia', $h(1, '14:00:00'), $h(1, '15:00:00'), '1ª Vara do Trabalho de Vitória', 'Levar documentos originais.', $dona]);
    $e->execute([$t, $proc[0], 'Prazo: réplica à contestação', 'prazo', $h(3, '23:59:00'), null, null, null, $dona]);
    $e->execute([$t, null, 'Reunião com Transportadora Rota Sul', 'reuniao', $h(2, '10:00:00'), $h(2, '11:00:00'), 'Escritório — sala 2', 'Revisão do contrato de assessoria.', $dona]);
    $e->execute([$t, $proc[4], 'Audiência de mediação — Fernanda Rocha', 'audiencia', $h(7, '09:30:00'), $h(7, '10:30:00'), 'CEJUSC Guarapari', null, $dona]);
    $e->execute([$t, null, 'Reunião de equipe', 'reuniao', $h(4, '08:30:00'), $h(4, '09:00:00'), 'Online', 'Pauta: prazos da semana.', $dona]);

    // Financeiro
    $f = $db->prepare('INSERT INTO finance_entries (tenant_id, case_id, client_id, entry_type, description, category, amount, status, due_date, paid_at, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $f->execute([$t, null, $cli[1], 'receita', 'Honorários mensais — assessoria', 'Honorários', '3500.00', 'pago', $d(-10), $h(-10, '11:00:00'), $paulo]);
    $f->execute([$t, $proc[0], $cli[0], 'receita', 'Honorários iniciais — ação de indenização', 'Honorários', '2500.00', 'pago', $d(-25), $h(-25, '15:00:00'), $paulo]);
    $f->execute([$t, $proc[2], $cli[2], 'receita', 'Entrada — reclamação trabalhista', 'Honorários', '1800.00', 'pendente', $d(0), null, $paulo]);   // vence hoje: o cartão "Recebíveis do mês" sempre tem valor
    $f->execute([$t, $proc[4], $cli[4], 'receita', 'Honorários — ação de alimentos', 'Honorários', '2200.00', 'pendente', $d(12), null, $paulo]);
    $f->execute([$t, null, null, 'despesa', 'Aluguel do escritório', 'Estrutura', '4200.00', 'pago', $d(-5), $h(-5, '09:00:00'), $paulo]);
    $f->execute([$t, $proc[1], $cli[1], 'despesa', 'Custas iniciais — cobrança', 'Custas processuais', '380.50', 'pendente', $d(2), null, $paulo]);

    // Documentos (texto simples, fictícios)
    $doc = $db->prepare('INSERT INTO documents (tenant_id, case_id, client_id, file_name, content_type, file_size, sha256, content, uploader_id, uploaded_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())');
    foreach ([
        [$proc[0], $cli[0], 'procuracao-mariana-oliveira.txt', "PROCURAÇÃO (DOCUMENTO FICTÍCIO — DEMONSTRAÇÃO)\n\nOutorgante: Mariana Oliveira Santos\nOutorgado: Silva & Associados Advocacia\n", $rafael],
        [$proc[2], $cli[2], 'calculo-horas-extras.txt', "CÁLCULO DE HORAS EXTRAS (FICTÍCIO — DEMONSTRAÇÃO)\n\nPeríodo: 12 meses\nTotal estimado: R$ 18.450,00\n", $beatriz],
    ] as $x) {
        $doc->execute([$t, $x[0], $x[1], $x[2], 'text/plain', strlen($x[3]), hash('sha256', $x[3]), $x[3], $x[4]]);
    }
    $db->commit();
    echo "Escritório DEMO criado: usuário demo (senha padrão, troca obrigatória no primeiro acesso).\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    fwrite(STDERR, 'Aviso: não foi possível criar o escritório demo: ' . $e->getMessage() . "\n");
}
