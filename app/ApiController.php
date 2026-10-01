<?php
declare(strict_types=1);

namespace LexCloud;

use LexCloud\Support\Database;
use LexCloud\Support\Http;
use LexCloud\Support\Security;
use LexCloud\Support\WebAuthn;
use PDO;
use Throwable;

final class ApiController
{
    private static function db(): PDO { return Database::connection(); }

    public static function dispatch(): never
    {
        $path = Http::path();
        $method = Http::method();

        if ($path === '/api/health' && $method === 'GET') {
            try {
                $maxUpload = self::maxUploadBytes();
                $ready = extension_loaded('pdo_mysql')
                    && extension_loaded('mbstring')
                    && extension_loaded('fileinfo')
                    && self::iniBytes((string) ini_get('upload_max_filesize')) >= $maxUpload
                    && self::iniBytes((string) ini_get('post_max_size')) >= $maxUpload + 1048576;
                if (!$ready) { Http::respond(['status' => 'unavailable', 'service' => 'lexcloud'], 503); }
                self::db()->query('SELECT 1');
                Http::respond(['status' => 'ok', 'service' => 'lexcloud', 'upload_limit_bytes' => $maxUpload]);
            } catch (Throwable) {
                Http::respond(['status' => 'unavailable', 'service' => 'lexcloud'], 503);
            }
        }

        if ($path === '/api/auth/session' && $method === 'GET') {
            Http::respond(['csrf' => Security::csrfToken(), 'user' => Security::user(), 'signup' => self::signupAberto(), 'setup' => self::setupDisponivel()]);
        }

        if ($path === '/api/platform/setup') {
            if ($method !== 'POST') { Http::fail('Método não permitido.', 405); }
            Security::verifyCsrf();
            self::platformSetup();
        }

        // Biometria: o próprio bloco confere método, CSRF (nos POST/DELETE) e login (no cadastro e na lista)
        if (str_starts_with($path, '/api/auth/webauthn/')) {
            self::auth($path, $method);
        }

        if (in_array($path, ['/api/auth/login', '/api/auth/register', '/api/auth/logout', '/api/auth/change-password', '/api/auth/forgot', '/api/auth/reset'], true)) {
            if ($method !== 'POST') { Http::fail('Método não permitido.', 405); }
            Security::verifyCsrf();
            self::auth($path, $method);
        }

        $user = Security::requireUser();
        if (!empty($user['must_change_password']) && $path !== '/api/auth/change-password') { Http::fail('Troque sua senha temporária antes de continuar.', 403); }
        // Acesso da CHS a um escritório: completo, como o "Dashboard" do Gestão de Notas (a troca de senha é bloqueada na própria rota)
        if ($path === '/api/platform/support/exit' && $method === 'POST') {
            Security::verifyCsrf();
            unset($_SESSION['support_tenant']); Security::forget();
            Http::respond(['ok' => true, 'user' => Security::user()]);
        }

        if (preg_match('#^/api/documents/(\d+)/download$#', $path, $matches) && $method === 'GET') {
            self::downloadDocument((int) $matches[1]);
        }

        if ($method !== 'GET') { Security::verifyCsrf(); }
        $db = self::db();

        if ($path === '/api/dashboard' && $method === 'GET') { self::dashboard($db, $user); }
        if ($path === '/api/reports' && $method === 'GET') { self::reports($db, $user); }
        if ($path === '/api/platform/tenants' && $method === 'GET') { self::platformTenants($db, $user); }
        if ($path === '/api/platform/tenants' && $method === 'POST') { self::platformCreateTenant($db, $user); }
        if (preg_match('#^/api/platform/tenants/(\d+)/(status|support|invite)$#', $path, $pm) && $method === 'POST') { self::platformTenantAction($db, $user, (int) $pm[1], $pm[2]); }

        if (preg_match('#^/api/(clients|cases|tasks|events|documents|finance|team|settings)(?:/(\d+))?$#', $path, $matches)) {
            $resource = $matches[1];
            $id = isset($matches[2]) ? (int) $matches[2] : null;
            self::resource($db, $user, $resource, $id, $method);
        }

        Http::fail('Rota não encontrada.', 404);
    }

    private static function auth(string $path, string $method): never
    {
        $db = self::db();
        if ($path === '/api/auth/logout') {
            if (!empty(Security::user()['support'])) { unset($_SESSION['support_tenant']); Security::forget(); Http::respond(['ok' => true, 'user' => Security::user()]); }
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', ['expires' => time() - 42000, 'path' => $params['path'] ?: '/', 'secure' => $params['secure'], 'httponly' => true, 'samesite' => 'Lax']);
            }
            session_destroy();
            Http::respond(['ok' => true]);
        }

        $input = Http::json();
        if ($path === '/api/auth/change-password') {
            $user = Security::requireUser();
            if (!empty($user['support'])) { Http::fail('No acesso da CHS não há senha do escritório para trocar. Volte ao painel para trocar a sua.', 403); }
            $current = (string) ($input['current_password'] ?? '');
            $next = (string) ($input['new_password'] ?? '');
            if (strlen($next) < 12 || strlen($next) > 200) { Http::fail('A nova senha precisa ter entre 12 e 200 caracteres.'); }
            if (hash_equals(Security::senhaPadrao(), $next)) { Http::fail('Escolha uma senha diferente da senha inicial.'); }
            if (!empty($user['tenant_id'])) { $stmt = $db->prepare('SELECT password_hash FROM users WHERE id=? AND tenant_id=? LIMIT 1'); $stmt->execute([(int)$user['id'], (int)$user['tenant_id']]); }
            else { $stmt = $db->prepare('SELECT password_hash FROM users WHERE id=? AND tenant_id IS NULL LIMIT 1'); $stmt->execute([(int)$user['id']]); }
            $hash = $stmt->fetchColumn();
            if (!$hash || !password_verify($current, (string)$hash)) { Http::fail('A senha atual não confere.', 401); }
            $novoHash = password_hash($next, PASSWORD_DEFAULT);
            $db->prepare('UPDATE users SET password_hash=?,must_change_password=0,senha_inicial_ate=NULL WHERE id=?')->execute([$novoHash, (int)$user['id']]);
            // Esta sessão continua; as abertas em outros aparelhos são encerradas
            session_regenerate_id(true); $_SESSION['user']['pw'] = Security::assinaturaSenha($novoHash); Security::forget();
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
            Http::respond(['ok'=>true,'user'=>Security::user(),'csrf'=>Security::csrfToken()]);
        }
        if ($path === '/api/auth/forgot') { self::forgot($db, $input); }
        if ($path === '/api/auth/reset') { self::resetPassword($db, $input); }
        if ($path === '/api/auth/register') {
            if ($method !== 'POST') { Http::fail('Método não permitido.', 405); }
            // Autocadastro público desligado: os escritórios são cadastrados pelo admin da plataforma.
            if (!self::signupAberto()) { Http::fail('O cadastro de escritórios é feito pela administração da plataforma.', 404); }
            $name = Http::requiredString($input, 'name', 180);
            $office = Http::requiredString($input, 'office_name', 180);
            $email = strtolower(Http::requiredString($input, 'email', 190));
            $password = (string) ($input['password'] ?? '');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { Http::fail('Informe um e-mail válido.'); }
            if (strlen($password) < 12 || strlen($password) > 200) { Http::fail('A senha precisa ter entre 12 e 200 caracteres.'); }
            $base = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $office) ?: 'escritorio';
            $base = strtolower($base);
            $base = (string) preg_replace('/[^a-z0-9]+/', '-', $base);
            $slug = trim(substr($base, 0, 80), '-') . '-' . bin2hex(random_bytes(3));
            try {
                $db->beginTransaction();
                $stmt = $db->prepare("INSERT INTO tenants (name, slug, plan, status) VALUES (?, ?, 'trial', 'trial')");
                $stmt->execute([$office, $slug]);
                $tenantId = (int) $db->lastInsertId();
                $stmt = $db->prepare('INSERT INTO users (tenant_id, name, email, password_hash, role, active) VALUES (?, ?, ?, ?, \'owner\', 1)');
                $stmt->execute([$tenantId, $name, $email, password_hash($password, PASSWORD_DEFAULT)]);
                $userId = (int) $db->lastInsertId();
                $db->prepare('INSERT INTO tenant_settings (tenant_id, timezone, language, settings_text) VALUES (?, ?, ?, ?)')->execute([$tenantId, 'America/Sao_Paulo', 'pt-BR', '{"currency":"BRL"}']);
                $db->commit();
            } catch (Throwable $exception) {
                if ($db->inTransaction()) { $db->rollBack(); }
                if ((string) $exception->getCode() === '23000') { Http::fail('Este e-mail já está cadastrado. Entre ou use outro e-mail.', 409); }
                throw $exception;
            }
            self::loginSession(['id' => $userId, 'tenant_id' => $tenantId, 'name' => $name, 'email' => $email, 'role' => 'owner', 'tenant_name' => $office, 'tenant_slug' => $slug]);
            Security::forget();
            Security::audit($db, Security::user() ?? [], 'tenant_created', 'tenant', $tenantId);
            Http::respond(['ok' => true, 'user' => Security::user(), 'csrf' => Security::csrfToken()], 201);
        }

        // ── Biometria (mesmo motor do Gestão de Notas) ─────────────────────────
        if ($path === '/api/auth/webauthn/login-options' && $method === 'GET') {
            // Não lista credenciais de ninguém: o aparelho oferece as contas que ele mesmo guarda
            // Vai junto um token CSRF novo: depois de "Sair", a tela ainda tinha o token da sessão encerrada
            Http::respond((new WebAuthn())->getAuthenticationOptions() + ['csrf' => Security::csrfToken()]);
        }
        if ($path === '/api/auth/webauthn/login-verify' && $method === 'POST') {
            Security::verifyCsrf();
            $cred = is_array($input['credential'] ?? null) ? $input['credential'] : $input;
            $credId = (string) ($cred['rawId'] ?? $cred['id'] ?? '');
            $key = hash('sha256', 'biometria|' . Security::clientIp());
            $limit = $db->prepare('SELECT attempts, window_started_at, locked_until FROM login_attempts WHERE identifier_hash = ?');
            $limit->execute([$key]);
            $attempt = $limit->fetch() ?: null;
            if ($attempt && !empty($attempt['locked_until']) && strtotime((string) $attempt['locked_until']) > time()) {
                Http::fail('Muitas tentativas. Aguarde alguns minutos e tente novamente.', 429);
            }
            $st = $db->prepare("SELECT w.id AS wid, w.public_key, w.sign_count, u.id, u.tenant_id, u.name, u.email, u.password_hash, u.role, u.must_change_password, u.active, u.suporte, t.name AS tenant_name, t.slug AS tenant_slug, t.status AS tenant_status
                                FROM webauthn_credentials w JOIN users u ON u.id = w.user_id LEFT JOIN tenants t ON t.id = u.tenant_id WHERE w.credential_id = ? LIMIT 1");
            $st->execute([$credId]);
            $row = $st->fetch() ?: null;
            try {
                if (!$row) throw new \Exception('Biometria não cadastrada neste sistema. Entre com a senha e cadastre o aparelho.');
                $r = (new WebAuthn())->verifyAuthentication($cred, (string) $row['public_key'], (int) $row['sign_count']);
            } catch (\Throwable $e) {
                self::recordLoginFailure($db, $key, $attempt);
                Http::fail($e->getMessage(), 401);
            }
            // Mesmas regras do login por senha: conta ativa, escritório não suspenso, nunca a conta oculta de suporte
            if ((int) $row['active'] !== 1 || (int) $row['suporte'] === 1 || ($row['tenant_id'] !== null && $row['tenant_status'] === 'suspended')) {
                Http::fail('Acesso não permitido para esta conta.', 401);
            }
            $db->prepare('DELETE FROM login_attempts WHERE identifier_hash = ?')->execute([$key]);
            $db->prepare('UPDATE webauthn_credentials SET sign_count = ?, last_used_at = NOW() WHERE id = ?')->execute([(int) $r['sign_count'], (int) $row['wid']]);
            $db->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([(int) $row['id']]);
            $pw = Security::assinaturaSenha((string) $row['password_hash']);
            unset($row['wid'], $row['public_key'], $row['sign_count'], $row['password_hash'], $row['active'], $row['suporte'], $row['tenant_status']);
            self::loginSession($row);
            $_SESSION['user']['pw'] = $pw; Security::forget();
            Security::audit($db, Security::user() ?? [], 'login_biometria', 'user', (int) $row['id']);
            Http::respond(['ok' => true, 'user' => Security::user(), 'csrf' => Security::csrfToken()]);
        }
        if (str_starts_with($path, '/api/auth/webauthn/')) {
            $user = Security::requireUser();
            if (!empty($user['support'])) { Http::fail('No acesso da CHS não se cadastra biometria. Volte ao painel para usar a sua.', 403); }
            if (!empty($user['must_change_password'])) { Http::fail('Troque sua senha temporária antes de cadastrar a biometria.', 403); }
            $uid = (int) $user['id'];
            if ($path === '/api/auth/webauthn/register-options' && $method === 'GET') {
                $ids = $db->prepare('SELECT credential_id FROM webauthn_credentials WHERE user_id = ?'); $ids->execute([$uid]);
                Http::respond((new WebAuthn())->getRegistrationOptions($uid, (string) $user['email'], 'lexcloud', array_column($ids->fetchAll(), 'credential_id')));
            }
            if ($path === '/api/auth/webauthn/register-verify' && $method === 'POST') {
                Security::verifyCsrf();
                $cred = is_array($input['credential'] ?? null) ? $input['credential'] : $input;
                try { $r = (new WebAuthn())->verifyRegistration($cred); }
                catch (\Throwable $e) { Http::fail($e->getMessage(), 400); }
                if ((int) ($_SESSION['webauthn_reg_user']['id'] ?? 0) !== $uid) { Http::fail('Cadastro iniciado por outra conta. Tente novamente.', 400); }
                unset($_SESSION['webauthn_reg_user']);
                $nome = mb_substr(trim((string) ($input['device_name'] ?? '')), 0, 120) ?: 'Aparelho';
                try {
                    $db->prepare('INSERT INTO webauthn_credentials (user_id, credential_id, public_key, sign_count, device_name) VALUES (?,?,?,?,?)')
                       ->execute([$uid, $r['credential_id'], $r['public_key'], (int) $r['sign_count'], $nome]);
                } catch (\PDOException $e) { Http::fail('Este aparelho já está cadastrado.', 409); }
                Security::audit($db, $user, 'biometria_cadastrada', 'user', $uid);
                Http::respond(['ok' => true, 'message' => 'Biometria cadastrada. Na próxima vez, entre com a digital ou o rosto.'], 201);
            }
            if ($path === '/api/auth/webauthn/credentials' && $method === 'GET') {
                $l = $db->prepare('SELECT id, device_name, created_at, last_used_at FROM webauthn_credentials WHERE user_id = ? ORDER BY created_at DESC');
                $l->execute([$uid]);
                Http::respond(['items' => $l->fetchAll()]);
            }
            if (preg_match('#^/api/auth/webauthn/credentials/(\d+)$#', $path, $wm) && $method === 'DELETE') {
                Security::verifyCsrf();
                $d = $db->prepare('DELETE FROM webauthn_credentials WHERE id = ? AND user_id = ?'); $d->execute([(int) $wm[1], $uid]);
                if (!$d->rowCount()) { Http::fail('Aparelho não encontrado.', 404); }
                Security::audit($db, $user, 'biometria_removida', 'user', $uid);
                Http::respond(['ok' => true]);
            }
            Http::fail('Rota não encontrada.', 404);
        }

        if ($path === '/api/auth/login') {
            if ($method !== 'POST') { Http::fail('Método não permitido.', 405); }
            $email = strtolower(trim((string) ($input['email'] ?? '')));   // e-mail OU usuário (ex.: cleiton.santos)
            $password = (string) ($input['password'] ?? '');
            $porEmail = (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
            if ((!$porEmail && !preg_match('/^[a-z0-9._-]{3,80}$/', $email)) || $password === '') { Http::fail('Usuário ou senha inválidos.', 401); }
            $key = hash('sha256', $email . '|' . Security::clientIp()); // IP real (atrás do proxy do Render/Fly)
            $limit = $db->prepare('SELECT attempts, window_started_at, locked_until FROM login_attempts WHERE identifier_hash = ?');
            $limit->execute([$key]);
            $attempt = $limit->fetch() ?: null; // fetch() devolve false sem registro (antes: erro 500 e bloqueio que nunca ativava)
            if ($attempt && !empty($attempt['locked_until']) && strtotime((string) $attempt['locked_until']) > time()) {
                Http::fail('Muitas tentativas. Aguarde alguns minutos e tente novamente.', 429);
            }
            $stmt = $db->prepare("SELECT u.id, u.tenant_id, u.name, u.email, u.password_hash, u.role, u.active, u.must_change_password, (u.senha_inicial_ate IS NOT NULL AND u.senha_inicial_ate < NOW()) AS senha_vencida, t.name AS tenant_name, t.slug AS tenant_slug, t.status AS tenant_status FROM users u LEFT JOIN tenants t ON t.id = u.tenant_id WHERE " . ($porEmail ? 'u.email = ?' : 'u.username = ?') . " LIMIT 1");
            $stmt->execute([$email]);
            $row = $stmt->fetch();
            $valid = $row && (int) $row['active'] === 1 && password_verify($password, (string) $row['password_hash']);
            if ($valid && $row['tenant_id'] !== null && $row['tenant_status'] === 'suspended') { $valid = false; }
            if (!$valid) {
                self::recordLoginFailure($db, $key, $attempt);
                Http::fail('Usuário ou senha inválidos.', 401);
            }
            // Senha inicial vale por tempo limitado: depois disso, só com um novo acesso enviado pelo administrador
            // Comparação feita no próprio banco (NOW() do banco): evita diferença de fuso entre o PHP e o TiDB
            if ((int) $row['must_change_password'] === 1 && (int) $row['senha_vencida'] === 1) {
                Http::fail('A senha inicial expirou. Peça ao administrador para reenviar o acesso (ou use "Esqueci minha senha").', 401);
            }
            $db->prepare('DELETE FROM login_attempts WHERE identifier_hash = ?')->execute([$key]);
            $db->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([(int) $row['id']]);
            $pw = Security::assinaturaSenha((string) $row['password_hash']);
            unset($row['password_hash'], $row['active'], $row['tenant_status'], $row['senha_vencida']);
            self::loginSession($row);
            $_SESSION['user']['pw'] = $pw; Security::forget();
            Http::respond(['ok' => true, 'user' => Security::user(), 'csrf' => Security::csrfToken()]);
        }

        Http::fail('Rota não encontrada.', 404);
    }

    private static function recordLoginFailure(PDO $db, string $key, ?array $attempt): void
    {
        $now = time();
        if (!$attempt || $now - strtotime((string) $attempt['window_started_at']) > 900) {
            $stmt = $db->prepare('INSERT INTO login_attempts (identifier_hash, attempts, window_started_at, locked_until) VALUES (?, 1, NOW(), NULL) ON DUPLICATE KEY UPDATE attempts = 1, window_started_at = NOW(), locked_until = NULL');
            $stmt->execute([$key]);
            return;
        }
        $count = (int) $attempt['attempts'] + 1;
        $locked = $count >= 6 ? date('Y-m-d H:i:s', $now + 900) : null;
        $stmt = $db->prepare('UPDATE login_attempts SET attempts = ?, locked_until = ? WHERE identifier_hash = ?');
        $stmt->execute([$count, $locked, $key]);
    }

    private static function loginSession(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        $_SESSION['user'] = [
            'id' => (int) $user['id'],
            'tenant_id' => isset($user['tenant_id']) ? (int) $user['tenant_id'] : null,
            'name' => (string) $user['name'],
            'email' => (string) $user['email'],
            'role' => (string) $user['role'],
            'must_change_password' => (int) ($user['must_change_password'] ?? 0),
            'tenant_name' => $user['tenant_name'] ?? null,
            'tenant_slug' => $user['tenant_slug'] ?? null,
        ];
    }

    private static function dashboard(PDO $db, array $user): never
    {
        $user = Security::requireTenant();
        $tenant = Security::tenantId($user);
        $stmt = $db->prepare("SELECT COUNT(*) AS total, SUM(status = 'active') AS active FROM legal_cases WHERE tenant_id = ?");
        $stmt->execute([$tenant]); $cases = $stmt->fetch() ?: ['total' => 0, 'active' => 0];
        $stmt = $db->prepare("SELECT COUNT(*) FROM tasks WHERE tenant_id = ? AND status IN ('pending','in_progress') AND due_at < NOW()");
        $stmt->execute([$tenant]); $overdue = (int) $stmt->fetchColumn();
        $stmt = $db->prepare("SELECT COUNT(*) FROM tasks WHERE tenant_id = ? AND status IN ('pending','in_progress') AND due_at >= NOW() AND due_at < DATE_ADD(NOW(), INTERVAL 7 DAY)");
        $stmt->execute([$tenant]); $dueSoon = (int) $stmt->fetchColumn();
        $receivable = null;
        if (Security::can('manage_finance', $user)) {
            $stmt = $db->prepare("SELECT COALESCE(SUM(CASE WHEN entry_type='receita' THEN amount ELSE -amount END),0) FROM finance_entries WHERE tenant_id = ? AND status = 'pendente' AND (due_date IS NULL OR due_date <= LAST_DAY(CURRENT_DATE()))");
            $stmt->execute([$tenant]); $receivable = (string) $stmt->fetchColumn();
        }
        $stmt = $db->prepare("SELECT t.id, t.title, t.priority, t.status, t.due_at, c.subject AS case_subject, u.name AS assignee_name FROM tasks t LEFT JOIN legal_cases c ON c.tenant_id=t.tenant_id AND c.id=t.case_id LEFT JOIN users u ON u.tenant_id=t.tenant_id AND u.id=t.assignee_id WHERE t.tenant_id=? AND t.status IN ('pending','in_progress') ORDER BY t.due_at IS NULL, t.due_at ASC LIMIT 6");
        $stmt->execute([$tenant]); $tasks = $stmt->fetchAll();
        $stmt = $db->prepare('SELECT e.id, e.title, e.event_type, e.starts_at, e.location, c.subject AS case_subject FROM events e LEFT JOIN legal_cases c ON c.tenant_id=e.tenant_id AND c.id=e.case_id WHERE e.tenant_id=? AND e.starts_at >= NOW() ORDER BY e.starts_at LIMIT 5');
        $stmt->execute([$tenant]); $events = $stmt->fetchAll();
        $stmt = $db->prepare('SELECT id, subject, area, status, next_deadline FROM legal_cases WHERE tenant_id=? ORDER BY next_deadline IS NULL, next_deadline LIMIT 5');
        $stmt->execute([$tenant]); $caseList = $stmt->fetchAll();
        Http::respond(['stats' => ['cases_total' => (int) $cases['total'], 'cases_active' => (int) $cases['active'], 'tasks_overdue' => $overdue, 'tasks_due_soon' => $dueSoon, 'receivable' => $receivable], 'tasks' => $tasks, 'events' => $events, 'cases' => $caseList]);
    }

    private static function reports(PDO $db, array $user): never
    {
        if (!Security::can('view_reports', $user)) { Http::fail('Você não tem permissão para consultar relatórios.', 403); }
        $user = Security::requireTenant();
        $tenant = Security::tenantId($user);
        $stmt = $db->prepare('SELECT status, COUNT(*) AS total FROM legal_cases WHERE tenant_id=? GROUP BY status');
        $stmt->execute([$tenant]); $caseStatuses = $stmt->fetchAll();
        $stmt = $db->prepare('SELECT status, COUNT(*) AS total FROM tasks WHERE tenant_id=? GROUP BY status');
        $stmt->execute([$tenant]); $taskStatuses = $stmt->fetchAll();
        $finance = [];
        if (Security::can('manage_finance', $user)) {
            $stmt = $db->prepare("SELECT entry_type, status, COALESCE(SUM(amount),0) AS total FROM finance_entries WHERE tenant_id=? AND created_at >= DATE_SUB(CURRENT_DATE(), INTERVAL 12 MONTH) GROUP BY entry_type, status");
            $stmt->execute([$tenant]); $finance = $stmt->fetchAll();
        }
        $stmt = $db->prepare('SELECT u.name, COUNT(t.id) AS tasks_total, SUM(t.status = \'done\') AS tasks_done FROM users u LEFT JOIN tasks t ON t.tenant_id=u.tenant_id AND t.assignee_id=u.id WHERE u.tenant_id=? AND u.active=1 GROUP BY u.id, u.name ORDER BY tasks_total DESC');
        $stmt->execute([$tenant]); $team = $stmt->fetchAll();
        Http::respond(['case_statuses' => $caseStatuses, 'task_statuses' => $taskStatuses, 'finance' => $finance, 'team' => $team]);
    }

    private static function platformTenants(PDO $db, array $user): never
    {
        if (($user['role'] ?? '') !== 'platform_admin' || !empty($user['tenant_id'])) { Http::fail('Acesso restrito à administração da plataforma.', 403); }
        $stmt = $db->query('SELECT t.id,t.name,t.slug,t.plan,t.status,t.created_at,COUNT(u.id) AS users_count,(SELECT COUNT(*) FROM legal_cases c WHERE c.tenant_id=t.id) AS cases_count FROM tenants t LEFT JOIN users u ON u.tenant_id=t.id AND u.suporte=0 GROUP BY t.id ORDER BY t.created_at DESC LIMIT 200');
        Http::respond(['tenants' => $stmt->fetchAll()]);
    }

    private static function resource(PDO $db, array $user, string $resource, ?int $id, string $method): never
    {
        if (in_array($resource, ['clients','cases','tasks','events','documents','finance','team','settings'], true) && (($user['role'] ?? '') === 'platform_admin' || empty($user['tenant_id']))) {
            Http::fail('Selecione um escritório com uma conta vinculada para acessar os dados.', 403);
        }
        $tenant = Security::tenantId($user);
        switch ($resource) {
            case 'clients': self::clients($db, $user, $tenant, $id, $method);
            case 'cases': self::cases($db, $user, $tenant, $id, $method);
            case 'tasks': self::tasks($db, $user, $tenant, $id, $method);
            case 'events': self::events($db, $user, $tenant, $id, $method);
            case 'documents': self::documents($db, $user, $tenant, $id, $method);
            case 'finance': self::finance($db, $user, $tenant, $id, $method);
            case 'team': self::team($db, $user, $tenant, $id, $method);
            case 'settings': self::settings($db, $user, $tenant, $method);
        }
        Http::fail('Rota não encontrada.', 404);
    }

    private static function clients(PDO $db, array $user, int $tenant, ?int $id, string $method): never
    {
        if ($method === 'GET') {
            $q = trim((string) ($_GET['q'] ?? ''));
            $like = '%' . $q . '%';
            $stmt = $db->prepare("SELECT cl.id,cl.name,cl.kind,cl.email,cl.phone,cl.city,cl.status,cl.created_at,COUNT(c.id) AS cases_count FROM clients cl LEFT JOIN legal_cases c ON c.tenant_id=cl.tenant_id AND c.client_id=cl.id WHERE cl.tenant_id=? AND (?='' OR cl.name LIKE ? OR cl.email LIKE ? OR cl.city LIKE ?) GROUP BY cl.id ORDER BY cl.name LIMIT 300");
            $stmt->execute([$tenant, $q, $like, $like, $like]);
            Http::respond(['items' => $stmt->fetchAll()]);
        }
        if ($method === 'POST') {
            Security::requireRole(['owner','admin','lawyer','staff']);
            $in = Http::json();
            $name = Http::requiredString($in, 'name', 180);
            $kind = self::enumValue($in, 'kind', ['pessoa_fisica','pessoa_juridica'], 'pessoa_fisica');
            $email = trim((string) ($in['email'] ?? ''));
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { Http::fail('E-mail do cliente inválido.'); }
            $stmt = $db->prepare('INSERT INTO clients (tenant_id,name,kind,email,phone,city,notes) VALUES (?,?,?,?,?,?,?)');
            $stmt->execute([$tenant,$name,$kind,$email ?: null,Http::nullableString($in,'phone',40),Http::nullableString($in,'city',120),Http::nullableString($in,'notes',3000)]);
            $newId = (int) $db->lastInsertId(); Security::audit($db,$user,'created','client',$newId);
            Http::respond(['id'=>$newId,'ok'=>true],201);
        }
        Http::fail('Método não permitido.',405);
    }

    private static function cases(PDO $db, array $user, int $tenant, ?int $id, string $method): never
    {
        if ($method === 'GET') {
            $base = 'SELECT c.id,c.client_id,c.case_number,c.area,c.case_type,c.subject,c.court,c.opposing_party,c.status,c.priority,c.owner_id,c.opened_on,c.next_deadline,c.description,c.created_at,c.updated_at,cl.name AS client_name,u.name AS owner_name FROM legal_cases c JOIN clients cl ON cl.tenant_id=c.tenant_id AND cl.id=c.client_id LEFT JOIN users u ON u.tenant_id=c.tenant_id AND u.id=c.owner_id WHERE c.tenant_id=?';
            if ($id) {
                $stmt=$db->prepare($base.' AND c.id=? LIMIT 1'); $stmt->execute([$tenant,$id]); $case=$stmt->fetch();
                if (!$case) { Http::fail('Processo não encontrado.',404); }
                $stmt=$db->prepare('SELECT a.id,a.activity_type,a.title,a.body,a.created_at,u.name AS user_name FROM case_activities a LEFT JOIN users u ON u.tenant_id=a.tenant_id AND u.id=a.user_id WHERE a.tenant_id=? AND a.case_id=? ORDER BY a.created_at DESC LIMIT 40');
                $stmt->execute([$tenant,$id]); $case['activities']=$stmt->fetchAll();
                Http::respond(['item'=>$case]);
            }
            $q=trim((string)($_GET['q']??'')); $like='%'.$q.'%';
            $stmt=$db->prepare($base." AND (?='' OR c.subject LIKE ? OR c.case_number LIKE ? OR cl.name LIKE ?) ORDER BY c.next_deadline IS NULL,c.next_deadline,c.updated_at DESC LIMIT 300");
            $stmt->execute([$tenant,$q,$like,$like,$like]); Http::respond(['items'=>$stmt->fetchAll()]);
        }
        if ($method === 'POST' && !$id) {
            Security::requireRole(['owner','admin','lawyer']); $in=Http::json();
            $client=(int)($in['client_id']??0); self::assertOwned($db,'clients',$tenant,$client);
            $subject=Http::requiredString($in,'subject',240); $area=Http::requiredString($in,'area',100);
            $owner=isset($in['owner_id'])&&$in['owner_id']!==''?(int)$in['owner_id']:(int)$user['id'];
            self::assertOwned($db,'users',$tenant,$owner);
            $status=self::enumValue($in,'status',['active','paused','closed'],'active');
            $priority=self::enumValue($in,'priority',['low','normal','high'],'normal');
            $stmt=$db->prepare('INSERT INTO legal_cases (tenant_id,client_id,case_number,area,case_type,subject,court,opposing_party,status,priority,owner_id,opened_on,next_deadline,description) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$tenant,$client,Http::nullableString($in,'case_number',40),$area,Http::nullableString($in,'case_type',80),$subject,Http::nullableString($in,'court',180),Http::nullableString($in,'opposing_party',180),$status,$priority,$owner,Http::dateOrNull($in['opened_on']??null),Http::dateOrNull($in['next_deadline']??null),Http::nullableString($in,'description',4000)]);
            $newId=(int)$db->lastInsertId(); $db->prepare("INSERT INTO case_activities (tenant_id,case_id,user_id,activity_type,title,body) VALUES (?,?,?,'sistema','Processo cadastrado','Registro inicial criado no LexCloud.')")->execute([$tenant,$newId,(int)$user['id']]);
            Security::audit($db,$user,'created','case',$newId); Http::respond(['id'=>$newId,'ok'=>true],201);
        }
        if ($method === 'PUT' && $id) {
            Security::requireRole(['owner','admin','lawyer']); $in=Http::json();
            $status=in_array(($in['status']??''),['active','paused','closed'],true)?$in['status']:null;
            if (!$status) { Http::fail('Situação inválida.'); }
            $stmt=$db->prepare('UPDATE legal_cases SET status=?, updated_at=NOW() WHERE tenant_id=? AND id=?'); $stmt->execute([$status,$tenant,$id]);
            if (!$stmt->rowCount()) { self::assertOwned($db,'legal_cases',$tenant,$id); }
            $db->prepare('INSERT INTO case_activities (tenant_id,case_id,user_id,activity_type,title,body) VALUES (?,?,?,\'status\',?,?)')->execute([$tenant,$id,(int)$user['id'],'Situação alterada','Nova situação: '.$status]);
            Security::audit($db,$user,'status_changed','case',$id); Http::respond(['ok'=>true]);
        }
        Http::fail('Método não permitido.',405);
    }

    private static function tasks(PDO $db, array $user, int $tenant, ?int $id, string $method): never
    {
        if ($method === 'GET') {
            $q=trim((string)($_GET['q']??'')); $status=trim((string)($_GET['status']??'')); $params=[$tenant];
            $sql='SELECT t.id,t.case_id,t.client_id,t.title,t.description,t.assignee_id,t.priority,t.status,t.due_at,t.created_at,c.subject AS case_subject,cl.name AS client_name,u.name AS assignee_name FROM tasks t LEFT JOIN legal_cases c ON c.tenant_id=t.tenant_id AND c.id=t.case_id LEFT JOIN clients cl ON cl.tenant_id=t.tenant_id AND cl.id=t.client_id LEFT JOIN users u ON u.tenant_id=t.tenant_id AND u.id=t.assignee_id WHERE t.tenant_id=?';
            if ($status!==''&&in_array($status,['pending','in_progress','done','cancelled'],true)) { $sql.=' AND t.status=?'; $params[]=$status; }
            if ($q!=='') { $sql.=' AND (t.title LIKE ? OR c.subject LIKE ? OR cl.name LIKE ?)'; $like='%'.$q.'%'; array_push($params,$like,$like,$like); }
            $sql.=' ORDER BY t.due_at IS NULL,t.due_at LIMIT 500'; $stmt=$db->prepare($sql);$stmt->execute($params);Http::respond(['items'=>$stmt->fetchAll()]);
        }
        if ($method==='POST'&&!$id) {
            Security::requireRole(['owner','admin','lawyer','staff']);$in=Http::json();$title=Http::requiredString($in,'title',200);
            $case=isset($in['case_id'])&&$in['case_id']!==''?(int)$in['case_id']:null;if($case)self::assertOwned($db,'legal_cases',$tenant,$case);
            $client=isset($in['client_id'])&&$in['client_id']!==''?(int)$in['client_id']:null;if($client)self::assertOwned($db,'clients',$tenant,$client);
            $assignee=isset($in['assignee_id'])&&$in['assignee_id']!==''?(int)$in['assignee_id']:(int)$user['id'];self::assertOwned($db,'users',$tenant,$assignee);
            $due=isset($in['due_at'])&&$in['due_at']!==''?self::validDateTime((string)$in['due_at']):null;
            $priority=self::enumValue($in,'priority',['low','normal','high'],'normal');
            $stmt=$db->prepare('INSERT INTO tasks (tenant_id,case_id,client_id,title,description,assignee_id,priority,due_at,created_by) VALUES (?,?,?,?,?,?,?,?,?)');$stmt->execute([$tenant,$case,$client,$title,Http::nullableString($in,'description',3000),$assignee,$priority,$due,(int)$user['id']]);
            $newId=(int)$db->lastInsertId();Security::audit($db,$user,'created','task',$newId);Http::respond(['id'=>$newId,'ok'=>true],201);
        }
        if ($method==='PUT'&&$id) {
            Security::requireRole(['owner','admin','lawyer','staff']);$in=Http::json();$status=in_array(($in['status']??''),['pending','in_progress','done','cancelled'],true)?$in['status']:null;if(!$status)Http::fail('Situação inválida.');
            $stmt=$db->prepare('UPDATE tasks SET status=?,completed_at=IF(?=\'done\',NOW(),NULL) WHERE tenant_id=? AND id=?');$stmt->execute([$status,$status,$tenant,$id]);if(!$stmt->rowCount())self::assertOwned($db,'tasks',$tenant,$id);Security::audit($db,$user,'status_changed','task',$id);Http::respond(['ok'=>true]);
        }
        Http::fail('Método não permitido.',405);
    }

    private static function events(PDO $db, array $user, int $tenant, ?int $id, string $method): never
    {
        if ($method==='GET') {
            $from=trim((string)($_GET['from']??''));$to=trim((string)($_GET['to']??''));$sql='SELECT e.id,e.case_id,e.title,e.event_type,e.starts_at,e.ends_at,e.location,e.notes,c.subject AS case_subject FROM events e LEFT JOIN legal_cases c ON c.tenant_id=e.tenant_id AND c.id=e.case_id WHERE e.tenant_id=?';$params=[$tenant];
            if($from!==''){$sql.=' AND e.starts_at>=?';$params[]=self::validDateTime($from.' 00:00:00');}if($to!==''){$sql.=' AND e.starts_at<?';$params[]=self::validDateTime(date('Y-m-d H:i:s',strtotime($to.' +1 day')));}
            $stmt=$db->prepare($sql.' ORDER BY e.starts_at LIMIT 500');$stmt->execute($params);Http::respond(['items'=>$stmt->fetchAll()]);
        }
        if ($method==='POST'&&!$id) {
            Security::requireRole(['owner','admin','lawyer','staff']);$in=Http::json();$title=Http::requiredString($in,'title',200);$starts=self::validDateTime((string)($in['starts_at']??''));$ends=isset($in['ends_at'])&&$in['ends_at']!==''?self::validDateTime((string)$in['ends_at']):null;$case=isset($in['case_id'])&&$in['case_id']!==''?(int)$in['case_id']:null;if($case)self::assertOwned($db,'legal_cases',$tenant,$case);
            $type=self::enumValue($in,'event_type',['audiencia','reuniao','prazo','outro'],'reuniao');$stmt=$db->prepare('INSERT INTO events (tenant_id,case_id,title,event_type,starts_at,ends_at,location,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?)');$stmt->execute([$tenant,$case,$title,$type,$starts,$ends,Http::nullableString($in,'location',240),Http::nullableString($in,'notes',3000),(int)$user['id']]);$newId=(int)$db->lastInsertId();Security::audit($db,$user,'created','event',$newId);Http::respond(['id'=>$newId,'ok'=>true],201);
        }
        Http::fail('Método não permitido.',405);
    }

    private static function documents(PDO $db, array $user, int $tenant, ?int $id, string $method): never
    {
        if ($method==='GET') {
            $stmt=$db->prepare('SELECT d.id,d.case_id,d.client_id,d.file_name,d.content_type,d.file_size,d.sha256,d.uploader_id,d.uploaded_at,c.subject AS case_subject,cl.name AS client_name,u.name AS uploader_name FROM documents d LEFT JOIN legal_cases c ON c.tenant_id=d.tenant_id AND c.id=d.case_id LEFT JOIN clients cl ON cl.tenant_id=d.tenant_id AND cl.id=d.client_id LEFT JOIN users u ON u.tenant_id=d.tenant_id AND u.id=d.uploader_id WHERE d.tenant_id=? ORDER BY d.uploaded_at DESC LIMIT 300');$stmt->execute([$tenant]);Http::respond(['items'=>$stmt->fetchAll()]);
        }
        if ($method==='POST'&&!$id) {
            Security::requireRole(['owner','admin','lawyer','staff']);$file=$_FILES['file']??null;if(!is_array($file)||($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)Http::fail('Selecione um arquivo válido para enviar.');
            $max=self::maxUploadBytes();if((int)$file['size']<1||(int)$file['size']>$max)Http::fail('O arquivo excede o limite de 12 MB.');
            $finfo=new \finfo(FILEINFO_MIME_TYPE);$mime=$finfo->file((string)$file['tmp_name'])?:'application/octet-stream';$allowed=['application/pdf','image/jpeg','image/png','text/plain','application/vnd.openxmlformats-officedocument.wordprocessingml.document'];if(!in_array($mime,$allowed,true))Http::fail('Tipo de arquivo não permitido. Use PDF, DOCX, PNG, JPG ou TXT.');
            $name=basename((string)$file['name']);$name=preg_replace('/[\x00-\x1F\\\\\/]+/u','-',$name)?:'documento';$name=mb_substr($name,0,240);$content=file_get_contents((string)$file['tmp_name']);if($content===false)Http::fail('Não foi possível ler o arquivo.',400);
            $case=isset($_POST['case_id'])&&$_POST['case_id']!==''?(int)$_POST['case_id']:null;if($case)self::assertOwned($db,'legal_cases',$tenant,$case);$client=isset($_POST['client_id'])&&$_POST['client_id']!==''?(int)$_POST['client_id']:null;if($client)self::assertOwned($db,'clients',$tenant,$client);
            $stmt=$db->prepare('INSERT INTO documents (tenant_id,case_id,client_id,file_name,content_type,file_size,sha256,content,uploader_id) VALUES (?,?,?,?,?,?,?,?,?)');$stmt->bindValue(1,$tenant,PDO::PARAM_INT);$stmt->bindValue(2,$case,$case===null?PDO::PARAM_NULL:PDO::PARAM_INT);$stmt->bindValue(3,$client,$client===null?PDO::PARAM_NULL:PDO::PARAM_INT);$stmt->bindValue(4,$name);$stmt->bindValue(5,$mime);$stmt->bindValue(6,(int)$file['size'],PDO::PARAM_INT);$stmt->bindValue(7,hash('sha256',$content));$stmt->bindValue(8,$content,PDO::PARAM_LOB);$stmt->bindValue(9,(int)$user['id'],PDO::PARAM_INT);$stmt->execute();$newId=(int)$db->lastInsertId();Security::audit($db,$user,'uploaded','document',$newId);Http::respond(['id'=>$newId,'ok'=>true],201);
        }
        Http::fail('Método não permitido.',405);
    }

    private static function downloadDocument(int $id): never
    {
        $user=Security::requireTenant();$db=self::db();$stmt=$db->prepare('SELECT file_name,content_type,file_size,content FROM documents WHERE tenant_id=? AND id=? LIMIT 1');$stmt->execute([Security::tenantId($user),$id]);$doc=$stmt->fetch();if(!$doc)Http::fail('Documento não encontrado.',404);
        header('Content-Type: '.$doc['content_type']);header('Content-Length: '.(int)$doc['file_size']);header('Content-Disposition: attachment; filename="documento"; filename*=UTF-8\'\''.rawurlencode((string)$doc['file_name']));header('Cache-Control: private, no-store, max-age=0');header('X-Content-Type-Options: nosniff');echo $doc['content'];exit;
    }

    private static function finance(PDO $db, array $user, int $tenant, ?int $id, string $method): never
    {
        if ($method==='GET') {
            Security::requireRole(['owner','admin','finance']);
            $stmt=$db->prepare('SELECT f.id,f.case_id,f.client_id,f.entry_type,f.description,f.category,f.amount,f.status,f.due_date,f.paid_at,f.created_at,c.subject AS case_subject,cl.name AS client_name FROM finance_entries f LEFT JOIN legal_cases c ON c.tenant_id=f.tenant_id AND c.id=f.case_id LEFT JOIN clients cl ON cl.tenant_id=f.tenant_id AND cl.id=f.client_id WHERE f.tenant_id=? ORDER BY f.due_date IS NULL,f.due_date DESC,f.created_at DESC LIMIT 500');$stmt->execute([$tenant]);$items=$stmt->fetchAll();$summary=['income'=>0,'expense'=>0,'pending'=>0];foreach($items as $item){if($item['entry_type']==='receita')$summary['income']+=(float)$item['amount'];else $summary['expense']+=(float)$item['amount'];if($item['status']==='pendente')$summary['pending']+=(float)$item['amount'];}Http::respond(['items'=>$items,'summary'=>$summary]);
        }
        if ($method==='POST'&&!$id) {
            Security::requireRole(['owner','admin','finance']);$in=Http::json();$desc=Http::requiredString($in,'description',240);$type=self::enumValue($in,'entry_type',['receita','despesa'],'receita');$amount=$in['amount']??null;if(!is_numeric($amount)||(float)$amount<=0||(float)$amount>9999999999)Http::fail('Informe um valor financeiro positivo e válido.');$case=isset($in['case_id'])&&$in['case_id']!==''?(int)$in['case_id']:null;if($case)self::assertOwned($db,'legal_cases',$tenant,$case);$client=isset($in['client_id'])&&$in['client_id']!==''?(int)$in['client_id']:null;if($client)self::assertOwned($db,'clients',$tenant,$client);$status=in_array(($in['status']??''),['pendente','pago','cancelado'],true)?$in['status']:'pendente';$stmt=$db->prepare('INSERT INTO finance_entries (tenant_id,case_id,client_id,entry_type,description,category,amount,status,due_date,paid_at,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)');$stmt->execute([$tenant,$case,$client,$type,$desc,Http::nullableString($in,'category',80),number_format((float)$amount,2,'.',''),$status,Http::dateOrNull($in['due_date']??null),$status==='pago'?date('Y-m-d H:i:s'):null,(int)$user['id']]);$newId=(int)$db->lastInsertId();Security::audit($db,$user,'created','finance_entry',$newId);Http::respond(['id'=>$newId,'ok'=>true],201);
        }
        if ($method==='PUT'&&$id) {
            Security::requireRole(['owner','admin','finance']);$in=Http::json();$status=in_array(($in['status']??''),['pendente','pago','cancelado'],true)?$in['status']:null;if(!$status)Http::fail('Situação inválida.');$stmt=$db->prepare('UPDATE finance_entries SET status=?,paid_at=IF(?=\'pago\',COALESCE(paid_at,NOW()),NULL) WHERE tenant_id=? AND id=?');$stmt->execute([$status,$status,$tenant,$id]);if(!$stmt->rowCount())self::assertOwned($db,'finance_entries',$tenant,$id);Security::audit($db,$user,'status_changed','finance_entry',$id);Http::respond(['ok'=>true]);
        }
        Http::fail('Método não permitido.',405);
    }

    private static function team(PDO $db, array $user, int $tenant, ?int $id, string $method): never
    {
        if ($method==='GET') { Security::requireRole(['owner','admin','lawyer']);$stmt=$db->prepare('SELECT id,name,email,role,active,last_login_at,created_at FROM users WHERE tenant_id=? AND suporte=0 ORDER BY active DESC,name');$stmt->execute([$tenant]);Http::respond(['items'=>$stmt->fetchAll()]); }
        if ($method==='POST'&&!$id) { Security::requireRole(['owner','admin']);$in=Http::json();$name=Http::requiredString($in,'name',180);$email=strtolower(Http::requiredString($in,'email',190));if(!filter_var($email,FILTER_VALIDATE_EMAIL))Http::fail('E-mail inválido.');$role=self::enumValue($in,'role',['admin','lawyer','staff','finance','viewer'],'staff');$temporary=bin2hex(random_bytes(7));try{$stmt=$db->prepare('INSERT INTO users (tenant_id,name,email,password_hash,role,active,must_change_password,senha_inicial_ate) VALUES (?,?,?,?,?,1,1,NOW() + INTERVAL '.Security::DIAS_SENHA_INICIAL.' DAY)');$stmt->execute([$tenant,$name,$email,password_hash($temporary,PASSWORD_DEFAULT),$role]);}catch(Throwable $e){if((string)$e->getCode()==='23000')Http::fail('Este e-mail já está cadastrado.',409);throw $e;}$newId=(int)$db->lastInsertId();Security::audit($db,$user,'invited','user',$newId);Http::respond(['id'=>$newId,'temporary_password'=>$temporary,'message'=>'Senha inicial desta pessoa (vale '.Security::DIAS_SENHA_INICIAL.' dias; a troca é pedida no primeiro acesso). Envie por um canal seguro.'],201); }
        if ($method==='PUT'&&$id) { Security::requireRole(['owner','admin']);if($id===(int)$user['id'])Http::fail('Não é possível desativar a própria conta.');$sp=$db->prepare('SELECT suporte FROM users WHERE tenant_id=? AND id=?');$sp->execute([$tenant,$id]);if((int)$sp->fetchColumn()===1)Http::fail('Registro não encontrado.',404);$in=Http::json();$active=!empty($in['active'])?1:0;$stmt=$db->prepare('UPDATE users SET active=? WHERE tenant_id=? AND id=?');$stmt->execute([$active,$tenant,$id]);if(!$stmt->rowCount())self::assertOwned($db,'users',$tenant,$id);Security::audit($db,$user,$active?'activated':'deactivated','user',$id);Http::respond(['ok'=>true]); }
        Http::fail('Método não permitido.',405);
    }

    private static function settings(PDO $db, array $user, int $tenant, string $method): never
    {
        if ($method==='GET') { $stmt=$db->prepare('SELECT t.name,t.slug,t.plan,t.status,t.created_at,s.timezone,s.language FROM tenants t LEFT JOIN tenant_settings s ON s.tenant_id=t.id WHERE t.id=?');$stmt->execute([$tenant]);$row=$stmt->fetch();if(!$row)Http::fail('Escritório não encontrado.',404);Http::respond(['settings'=>$row,'user'=>$user]); }
        if ($method==='PUT') { Security::requireRole(['owner','admin']);$in=Http::json();$name=Http::requiredString($in,'name',180);$timezone=in_array(($in['timezone']??''),['America/Sao_Paulo','America/Manaus','America/Belem','America/Fortaleza','America/Cuiaba','America/Recife','America/Rio_Branco','America/Noronha'],true)?$in['timezone']:'America/Sao_Paulo';$db->prepare('UPDATE tenants SET name=? WHERE id=?')->execute([$name,$tenant]);$db->prepare('INSERT INTO tenant_settings (tenant_id,timezone,language,settings_text) VALUES (?,?,\'pt-BR\',\'{"currency":"BRL"}\') ON DUPLICATE KEY UPDATE timezone=VALUES(timezone)')->execute([$tenant,$timezone]);Security::audit($db,$user,'updated','settings',$tenant);Http::respond(['ok'=>true]); }
        Http::fail('Método não permitido.',405);
    }

    private static function assertOwned(PDO $db, string $table, int $tenant, int $id): void
    {
        $allowed=['clients','legal_cases','users','tasks','finance_entries','events','documents'];if(!in_array($table,$allowed,true))Http::fail('Recurso inválido.',400);$stmt=$db->prepare("SELECT id FROM {$table} WHERE tenant_id=? AND id=? LIMIT 1");$stmt->execute([$tenant,$id]);if(!$stmt->fetch())Http::fail('Registro não encontrado neste escritório.',404);
    }

    private static function maxUploadBytes(): int
    {
        $raw = getenv('MAX_UPLOAD_BYTES');
        if ($raw === false || trim($raw) === '') {
            return 12582912;
        }
        $value = filter_var(trim($raw), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 12582912],
        ]);
        if ($value === false) {
            throw new \RuntimeException('MAX_UPLOAD_BYTES must be an integer from 1 to 12582912.');
        }
        return $value;
    }

    private static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') { return PHP_INT_MAX; }
        $unit = strtolower(substr($value, -1));
        if (!ctype_alpha($unit)) { return max(0, (int) $value); }
        $number = (float) substr($value, 0, -1);
        $factor = match ($unit) { 'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1 };
        return (int) ($number * $factor);
    }

    private static function enumValue(array $input, string $key, array $allowed, string $default): string
    {
        $value = $input[$key] ?? $default;
        return is_string($value) && in_array($value, $allowed, true) ? $value : $default;
    }

    private static function validDateTime(string $value): string
    {
        $value=trim($value);$formats=['!Y-m-d\TH:i','!Y-m-d H:i:s','!Y-m-d H:i'];$date=null;foreach($formats as $format){$date=\DateTimeImmutable::createFromFormat($format,$value);if($date&&$date->format(str_contains($format,'\\T')?'Y-m-d\TH:i':(str_contains($format,'s')?'Y-m-d H:i:s':'Y-m-d H:i'))===$value)break;$date=null;}if(!$date)Http::fail('Data e hora inválidas.');return $date->format('Y-m-d H:i:s');
    }

    // ─────────────────────────────────────────────────────────────────
    // Plataforma (admin da CHS), convites e recuperação de senha
    // ─────────────────────────────────────────────────────────────────

    /** Autocadastro público de escritórios: desligado por padrão (ALLOW_SIGNUP=true para ligar). */
    private static function signupAberto(): bool { return getenv('ALLOW_SIGNUP') === 'true'; }

    /** Criação do admin da plataforma pela web: só com SETUP_TOKEN (24+) e enquanto não houver nenhum. */
    private static function setupDisponivel(): bool
    {
        if (strlen((string) getenv('SETUP_TOKEN')) < 24) return false;
        try { return (int) self::db()->query("SELECT COUNT(*) FROM users WHERE role='platform_admin' AND tenant_id IS NULL")->fetchColumn() === 0; }
        catch (Throwable) { return false; }
    }

    private static function baseUrl(): string
    {
        $url = rtrim((string) getenv('APP_URL'), '/');
        if ($url !== '') return $url;
        $https = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https' || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        return ($https ? 'https' : 'http') . '://' . preg_replace('/[^a-zA-Z0-9.:-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    }

    /** Link de uso único para criar/redefinir a senha. @return string o link */
    private static function criarLinkSenha(PDO $db, int $userId, int $horas): string
    {
        $tok = bin2hex(random_bytes(32));
        $db->prepare('UPDATE password_resets SET used_at=UTC_TIMESTAMP() WHERE user_id=? AND used_at IS NULL')->execute([$userId]);
        $db->prepare('INSERT INTO password_resets (user_id,token_hash,expires_at) VALUES (?,?,UTC_TIMESTAMP() + INTERVAL ' . (int) $horas . ' HOUR)')->execute([$userId, hash('sha256', $tok)]);
        return self::baseUrl() . '/#/redefinir/' . $tok;
    }

    private static function emailSenha(string $para, string $nome, string $titulo, string $texto, string $link, string $validade): void
    {
        $html = '<div style="font-family:Arial,sans-serif;max-width:520px;margin:auto;color:#1f2937"><h2 style="font-size:18px">AdvCloud</h2>'
            . '<p>Olá, ' . htmlspecialchars($nome, ENT_QUOTES) . '.</p><p>' . htmlspecialchars($texto, ENT_QUOTES) . '</p>'
            . '<p style="margin:24px 0"><a href="' . htmlspecialchars($link, ENT_QUOTES) . '" style="display:inline-block;background:#1e3a5f;color:#fff;padding:12px 22px;border-radius:8px;text-decoration:none;font-weight:bold">Criar minha senha</a></p>'
            . '<p style="color:#6b7280;font-size:13px">O link vale por ' . htmlspecialchars($validade, ENT_QUOTES) . ' e só pode ser usado uma vez. Se você não pediu, ignore este e-mail.</p></div>';
        $r = \LexCloud\Support\Mail::enviar($para, $titulo, $html);
        if (!$r['ok']) error_log('LexCloud: e-mail não enviado para ' . $para . ': ' . ($r['erro'] ?? ''));
    }

    private static function forgot(PDO $db, array $input): never
    {
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $st = $db->prepare("SELECT u.id,u.name FROM users u LEFT JOIN tenants t ON t.id=u.tenant_id WHERE u.email=? AND u.active=1 AND (u.tenant_id IS NULL OR t.status<>'suspended') LIMIT 1");
            $st->execute([$email]); $u = $st->fetch();
            if ($u) {
                $n = $db->prepare('SELECT COUNT(*) FROM password_resets WHERE user_id=? AND created_at >= UTC_TIMESTAMP() - INTERVAL 15 MINUTE');
                $n->execute([(int) $u['id']]);
                if ((int) $n->fetchColumn() < 3) {
                    $link = self::criarLinkSenha($db, (int) $u['id'], 1);
                    self::emailSenha($email, (string) $u['name'], 'Redefinição de senha — AdvCloud', 'Recebemos um pedido para redefinir a sua senha.', $link, '1 hora');
                }
            }
        }
        // Mesma resposta exista ou não a conta
        Http::respond(['ok' => true, 'message' => 'Se o e-mail estiver cadastrado, você receberá um link para criar uma nova senha. Confira também o spam.']);
    }

    private static function resetPassword(PDO $db, array $input): never
    {
        $tok = (string) ($input['token'] ?? ''); $nova = (string) ($input['new_password'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/', $tok)) Http::fail('Link inválido ou expirado. Peça um novo.', 400);
        $st = $db->prepare('SELECT r.user_id FROM password_resets r JOIN users u ON u.id=r.user_id WHERE r.token_hash=? AND r.used_at IS NULL AND r.expires_at > UTC_TIMESTAMP() AND u.active=1 LIMIT 1');
        $st->execute([hash('sha256', $tok)]); $uid = $st->fetchColumn();
        if (!$uid) Http::fail('Link inválido ou expirado. Peça um novo.', 400);
        if (strlen($nova) < 12 || strlen($nova) > 200) Http::fail('A nova senha precisa ter entre 12 e 200 caracteres.');
        // must_change_password=0: a pessoa acabou de escolher a própria senha. Sessões antigas caem (assinatura da senha muda).
        $db->prepare('UPDATE users SET password_hash=?, must_change_password=0, senha_inicial_ate=NULL WHERE id=?')->execute([password_hash($nova, PASSWORD_DEFAULT), (int) $uid]);
        $db->prepare('UPDATE password_resets SET used_at=UTC_TIMESTAMP() WHERE user_id=? AND used_at IS NULL')->execute([(int) $uid]);
        Http::respond(['ok' => true, 'message' => 'Senha criada. Entre com o seu e-mail e a nova senha.']);
    }

    private static function platformSetup(): never
    {
        if (!self::setupDisponivel()) Http::fail('A instalação já foi concluída.', 404);
        $in = Http::json();
        if (!hash_equals((string) getenv('SETUP_TOKEN'), (string) ($in['setup_token'] ?? ''))) Http::fail('Código de instalação incorreto.', 403);
        $name = Http::requiredString($in, 'name', 180); $email = strtolower(Http::requiredString($in, 'email', 190)); $senha = (string) ($in['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) Http::fail('Informe um e-mail válido.');
        if (strlen($senha) < 12 || strlen($senha) > 200) Http::fail('A senha precisa ter entre 12 e 200 caracteres.');
        try { self::db()->prepare("INSERT INTO users (tenant_id,name,email,password_hash,role,active,must_change_password) VALUES (NULL,?,?,?,'platform_admin',1,0)")->execute([$name, $email, password_hash($senha, PASSWORD_DEFAULT)]); }
        catch (Throwable $e) { if ((string) $e->getCode() === '23000') Http::fail('Este e-mail já está cadastrado.', 409); throw $e; }
        Http::respond(['ok' => true, 'message' => 'Administrador da plataforma criado. Entre com o e-mail e a senha, e apague o SETUP_TOKEN no servidor.'], 201);
    }

    private static function exigirPlataforma(array $user): void
    {
        if (($user['role'] ?? '') !== 'platform_admin' || !empty($user['tenant_id'])) Http::fail('Acesso restrito à administração da plataforma.', 403);
    }

    /** Novo escritório + responsável (owner) com convite por e-mail (link de 48h para criar a senha). */
    private static function platformCreateTenant(PDO $db, array $user): never
    {
        self::exigirPlataforma($user); Security::verifyCsrf();
        $in = Http::json();
        $office = Http::requiredString($in, 'office_name', 180); $name = Http::requiredString($in, 'owner_name', 180);
        $email = strtolower(Http::requiredString($in, 'owner_email', 190));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) Http::fail('Informe um e-mail válido para o responsável.');
        $plan = in_array(($in['plan'] ?? ''), ['trial', 'essencial', 'profissional', 'escritorio'], true) ? $in['plan'] : 'essencial';
        $base = strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $office) ?: 'escritorio');
        $slug = trim(substr((string) preg_replace('/[^a-z0-9]+/', '-', $base), 0, 80), '-') . '-' . bin2hex(random_bytes(3));
        try {
            $db->beginTransaction();
            $db->prepare("INSERT INTO tenants (name,slug,plan,status) VALUES (?,?,?,'active')")->execute([$office, $slug, $plan]);
            $tid = (int) $db->lastInsertId();
            $db->prepare("INSERT INTO users (tenant_id,name,email,password_hash,role,active,must_change_password,senha_inicial_ate) VALUES (?,?,?,?,'owner',1,1,NOW() + INTERVAL " . Security::DIAS_SENHA_INICIAL . " DAY)")->execute([$tid, $name, $email, password_hash(Security::senhaPadrao(), PASSWORD_DEFAULT)]);
            $uid = (int) $db->lastInsertId();
            $db->prepare('INSERT INTO tenant_settings (tenant_id,timezone,language,settings_text) VALUES (?,?,?,?)')->execute([$tid, 'America/Sao_Paulo', 'pt-BR', '{"currency":"BRL"}']);
            $link = self::criarLinkSenha($db, $uid, 48);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            if ((string) $e->getCode() === '23000') Http::fail('Este e-mail já está cadastrado em outro escritório.', 409);
            throw $e;
        }
        self::emailSenha($email, $name, 'Acesso ao AdvCloud — ' . $office, 'A conta do escritório ' . $office . ' foi criada e você é o responsável. Entre com o seu e-mail e a senha inicial informada pela CHS (a troca é pedida no primeiro acesso), ou crie a sua senha agora pelo botão abaixo.', $link, '48 horas');
        Security::audit($db, ['id' => $user['id'], 'tenant_id' => $tid], 'platform:tenant_created', 'tenant', $tid);
        Http::respond(['ok' => true, 'id' => $tid, 'invite_link' => $link, 'message' => 'Escritório criado. O responsável entra com ' . $email . ' e a senha padrão (troca obrigatória no primeiro acesso, vale ' . Security::DIAS_SENHA_INICIAL . ' dias). Convite também enviado por e-mail.'], 201);
    }

    private static function platformTenantAction(PDO $db, array $user, int $tid, string $acao): never
    {
        self::exigirPlataforma($user); Security::verifyCsrf();
        $st = $db->prepare('SELECT id,name,status FROM tenants WHERE id=?'); $st->execute([$tid]); $t = $st->fetch();
        if (!$t) Http::fail('Escritório não encontrado.', 404);
        if ($acao === 'status') {
            $novo = $t['status'] === 'suspended' ? 'active' : 'suspended';
            $db->prepare('UPDATE tenants SET status=? WHERE id=?')->execute([$novo, $tid]);
            Security::audit($db, ['id' => $user['id'], 'tenant_id' => $tid], 'platform:' . $novo, 'tenant', $tid);
            Http::respond(['ok' => true, 'status' => $novo, 'message' => $novo === 'suspended' ? 'Escritório suspenso: ninguém dele consegue acessar.' : 'Escritório reativado.']);
        }
        if ($acao === 'invite') {
            $o = $db->prepare("SELECT id,name,email FROM users WHERE tenant_id=? AND role='owner' AND active=1 ORDER BY id LIMIT 1"); $o->execute([$tid]); $owner = $o->fetch();
            if (!$owner) Http::fail('Este escritório não tem responsável ativo.', 404);
            $db->prepare("UPDATE users SET password_hash=?, must_change_password=1, senha_inicial_ate=NOW() + INTERVAL " . Security::DIAS_SENHA_INICIAL . " DAY WHERE id=?")->execute([password_hash(Security::senhaPadrao(), PASSWORD_DEFAULT), (int) $owner['id']]);
            $link = self::criarLinkSenha($db, (int) $owner['id'], 48);
            self::emailSenha((string) $owner['email'], (string) $owner['name'], 'Acesso ao AdvCloud — ' . $t['name'], 'Seu acesso foi renovado: entre com o seu e-mail e a senha inicial informada pela CHS (a troca é pedida no primeiro acesso), ou crie a sua senha pelo botão abaixo.', $link, '48 horas');
            Security::audit($db, ['id' => $user['id'], 'tenant_id' => $tid], 'platform:invite_resent', 'user', (int) $owner['id']);
            Http::respond(['ok' => true, 'invite_link' => $link, 'message' => 'Acesso renovado: ' . $owner['email'] . ' volta para a senha padrão (troca obrigatória, vale ' . Security::DIAS_SENHA_INICIAL . ' dias). Convite também enviado por e-mail.']);
        }
        // support
        $_SESSION['support_tenant'] = $tid; Security::forget();
        Security::usuarioSuporte($db, $tid);
        Security::audit($db, ['id' => Security::usuarioSuporte($db, $tid), 'tenant_id' => $tid], 'platform:acesso_chs_' . preg_replace('/[^a-z0-9._-]/', '', strtolower((string) ($user['email'] ?? ''))), 'tenant', $tid);
        Http::respond(['ok' => true, 'user' => Security::user()]);
    }

}
