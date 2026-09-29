<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use LexCloud\Support\Database;

$email = strtolower(trim((string) ($argv[1] ?? '')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Uso: php bin/create-platform-admin.php email@dominio.com\n");
    exit(2);
}

fwrite(STDOUT, 'Nome da pessoa administradora: ');
$name = trim((string) fgets(STDIN));
fwrite(STDOUT, 'Senha exclusiva (mínimo 12 caracteres): ');
if (function_exists('shell_exec') && DIRECTORY_SEPARATOR === '/') { @shell_exec('stty -echo'); }
$password = trim((string) fgets(STDIN));
if (function_exists('shell_exec') && DIRECTORY_SEPARATOR === '/') { @shell_exec('stty echo'); fwrite(STDOUT, "\n"); }
if (mb_strlen($name) < 2 || strlen($password) < 12) {
    fwrite(STDERR, "Nome ou senha inválidos.\n");
    exit(2);
}

try {
    $db = Database::connection();
    $check = $db->prepare('SELECT id,tenant_id,role FROM users WHERE email=? LIMIT 1');
    $check->execute([$email]);
    $existing = $check->fetch();
    if ($existing && ($existing['tenant_id'] !== null || $existing['role'] !== 'platform_admin')) {
        fwrite(STDERR, "Este e-mail já pertence a uma conta de escritório; não será promovido. Use um e-mail administrativo separado.\n");
        exit(3);
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    if ($existing) {
        $db->prepare('UPDATE users SET name=?,password_hash=?,active=1,must_change_password=0 WHERE id=? AND tenant_id IS NULL')->execute([$name,$hash,(int)$existing['id']]);
    } else {
        $db->prepare("INSERT INTO users (tenant_id,name,email,password_hash,role,active,must_change_password) VALUES (NULL,?,?,?,'platform_admin',1,0)")->execute([$name,$email,$hash]);
    }
    echo "Conta administrativa global configurada. Nenhum conteúdo de processos foi consultado.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Falha: ' . $exception->getMessage() . "\n");
    exit(1);
}
