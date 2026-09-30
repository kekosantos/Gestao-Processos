<?php
declare(strict_types=1);

namespace LexCloud\Support;

use PDO;

final class Security
{
    private static bool $carregado = false;
    private static ?array $atual = null;

    /** IP real do visitante. Atrás do proxy do Render/Fly (TRUST_PROXY=true) o REMOTE_ADDR é o do proxy. */
    public static function clientIp(): string
    {
        if (getenv('TRUST_PROXY') === 'true') {
            foreach (['HTTP_FLY_CLIENT_IP', 'HTTP_TRUE_CLIENT_IP', 'HTTP_X_FORWARDED_FOR'] as $h) {
                $v = trim(explode(',', (string) ($_SERVER[$h] ?? ''))[0]);
                if ($v !== '' && filter_var($v, FILTER_VALIDATE_IP)) return $v;
            }
        }
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        session_name('LEXCLOUDSESSID');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', '28800');
        // Sessão no banco (o disco do Render/Fly é apagado a cada reinício). SESSION_DRIVER=files para desligar.
        if (PHP_SAPI !== 'cli' && getenv('SESSION_DRIVER') !== 'files') {
            try { session_set_save_handler(new DbSessionHandler(), true); }
            catch (\Throwable $e) { error_log('LexCloud: sessão em arquivo (banco indisponível): ' . $e->getMessage()); }
        }
        session_start();
        if (!isset($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
    }

    public static function csrfToken(): string
    {
        self::startSession();
        return (string) $_SESSION['_csrf'];
    }

    public static function verifyCsrf(): void
    {
        self::startSession();
        $given = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if ($given === '' || !hash_equals((string) ($_SESSION['_csrf'] ?? ''), $given)) {
            Http::fail('A sessão expirou ou a validação de segurança falhou. Atualize a página e tente novamente.', 419);
        }
    }

    /**
     * Usuário da requisição, RECONFERIDO NO BANCO a cada requisição: conta desativada,
     * escritório suspenso ou senha trocada encerram o acesso na hora (antes valia até
     * o próximo login). Perfil e nome também vêm sempre do banco.
     * Modo suporte: o admin da plataforma vê um escritório SOMENTE LEITURA.
     */
    public static function user(): ?array
    {
        self::startSession();
        if (self::$carregado) return self::$atual;
        self::$carregado = true;
        $id = (int) ($_SESSION['user']['id'] ?? 0);
        if ($id < 1) return self::$atual = null;
        try {
            $db = Database::connection();
            $st = $db->prepare('SELECT u.id,u.tenant_id,u.name,u.email,u.password_hash,u.role,u.active,u.must_change_password,t.name AS tenant_name,t.slug AS tenant_slug,t.status AS tenant_status FROM users u LEFT JOIN tenants t ON t.id=u.tenant_id WHERE u.id=? LIMIT 1');
            $st->execute([$id]);
            $u = $st->fetch();
        } catch (\Throwable $e) {
            error_log('LexCloud: não foi possível validar a sessão: ' . $e->getMessage());
            return self::$atual = null;
        }
        $versao = (string) ($_SESSION['user']['pw'] ?? '');
        $assinatura = $u ? self::assinaturaSenha((string) $u['password_hash']) : '';
        // Senha trocada em outro aparelho encerra esta sessão
        if (!$u || (int) $u['active'] !== 1 || ($u['tenant_id'] !== null && $u['tenant_status'] === 'suspended')
            || ($versao !== '' && !hash_equals($versao, $assinatura))) {
            unset($_SESSION['user'], $_SESSION['support_tenant']);
            return self::$atual = null;
        }
        $user = [
            'id' => (int) $u['id'],
            'tenant_id' => $u['tenant_id'] !== null ? (int) $u['tenant_id'] : null,
            'name' => (string) $u['name'],
            'email' => (string) $u['email'],
            'role' => (string) $u['role'],
            'must_change_password' => (int) $u['must_change_password'],
            'tenant_name' => $u['tenant_name'],
            'tenant_slug' => $u['tenant_slug'],
        ];
        $_SESSION['user'] = $user + ['pw' => $assinatura];
        // Acesso da CHS a um escritório (como o "Dashboard" do Gestão de Notas): acesso COMPLETO, agindo como
        // o usuário oculto "Suporte CHS" daquele escritório (assim tudo fica registrado como feito pela CHS)
        $suporte = (int) ($_SESSION['support_tenant'] ?? 0);
        if ($suporte > 0 && $user['role'] === 'platform_admin' && $user['tenant_id'] === null) {
            $t = Database::connection()->prepare('SELECT id,name,slug FROM tenants WHERE id=?');
            $t->execute([$suporte]);
            if ($tt = $t->fetch()) {
                return self::$atual = ['id' => self::usuarioSuporte(Database::connection(), (int) $tt['id']), 'tenant_id' => (int) $tt['id'],
                    'name' => $user['name'] . ' (CHS)', 'email' => $user['email'], 'role' => 'owner', 'must_change_password' => 0,
                    'tenant_name' => $tt['name'], 'tenant_slug' => $tt['slug'], 'support' => true, 'platform_user_id' => (int) $user['id']];
            }
            unset($_SESSION['support_tenant']);
        }
        return self::$atual = $user;
    }

    public static function assinaturaSenha(string $hash): string { return substr(hash('sha256', 'pw|' . $hash), 0, 20); }

    public static function forget(): void { self::$carregado = false; self::$atual = null; }

    public static function requireUser(): array
    {
        $user = self::user();
        if (!$user) {
            Http::fail('Autenticação necessária.', 401);
        }
        return $user;
    }

    public static function requireTenant(): array
    {
        $user = self::requireUser();
        if (empty($user['tenant_id']) || ($user['role'] ?? '') === 'platform_admin') {
            Http::fail('Acesso restrito a usuários de um escritório.', 403);
        }
        return $user;
    }

    public static function requireRole(array $roles): array
    {
        $user = self::requireTenant();
        if (!in_array((string) ($user['role'] ?? ''), $roles, true)) {
            Http::fail('Você não tem permissão para esta ação.', 403);
        }
        return $user;
    }

    public static function tenantId(array $user): int
    {
        return (int) ($user['tenant_id'] ?? 0);
    }

    public static function can(string $permission, array $user): bool
    {
        $matrix = [
            'owner' => ['manage_team', 'manage_settings', 'manage_finance', 'edit_cases', 'edit_tasks', 'manage_documents', 'view_reports'],
            'admin' => ['manage_team', 'manage_settings', 'manage_finance', 'edit_cases', 'edit_tasks', 'manage_documents', 'view_reports'],
            'lawyer' => ['edit_cases', 'edit_tasks', 'manage_documents', 'view_reports'],
            'staff' => ['edit_tasks', 'manage_documents'],
            'finance' => ['manage_finance', 'view_reports'],
            'viewer' => ['view_reports'],
        ];
        return in_array($permission, $matrix[(string) ($user['role'] ?? '')] ?? [], true);
    }

    public static function audit(PDO $db, array $user, string $action, string $entity, ?int $entityId = null): void
    {
        $tenantId = $user['tenant_id'] ?? null;
        $stmt = $db->prepare('INSERT INTO audit_logs (tenant_id, user_id, action, entity_type, entity_id, ip_address, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
        $stmt->execute([
            $tenantId ? (int) $tenantId : null,
            (int) ($user['id'] ?? 0),
            mb_substr((!empty($user['support']) ? 'suporte:' : '') . $action, 0, 80),
            mb_substr($entity, 0, 80),
            $entityId,
            mb_substr(self::clientIp(), 0, 45),
        ]);
    }

    /**
     * Senha inicial das contas criadas pela plataforma (troca obrigatória no primeiro acesso, vale 7 dias).
     * Defina SENHA_PADRAO no Render/Fly com um valor só seu; sem ela, vale "password".
     */
    public static function senhaPadrao(): string
    {
        $v = (string) getenv('SENHA_PADRAO');
        return $v !== '' ? $v : 'password';
    }

    public const DIAS_SENHA_INICIAL = 7;

    /**
     * Usuário oculto "Suporte CHS" do escritório (criado na primeira vez). Não aparece na equipe e não entra
     * pelo login (active=0): existe só para o que a CHS faz dentro do escritório ficar registrado em nome dela.
     */
    public static function usuarioSuporte(PDO $db, int $tenantId): int
    {
        $s = $db->prepare('SELECT id FROM users WHERE tenant_id=? AND suporte=1 ORDER BY id LIMIT 1');
        $s->execute([$tenantId]);
        if ($id = (int) $s->fetchColumn()) return $id;
        $db->prepare("INSERT INTO users (tenant_id,name,email,password_hash,role,active,must_change_password,suporte) VALUES (?,?,?,?,'owner',0,0,1)")
           ->execute([$tenantId, 'Suporte CHS', 'suporte-chs-' . $tenantId . '@lexcloud.invalid', password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT)]);
        return (int) $db->lastInsertId();
    }
}
