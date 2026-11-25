<?php

declare(strict_types=1);

namespace App\Validacao;

use PDO;

final class Kit
{
    public const TIPOS = ['erro' => 'Erro', 'melhoria' => 'Melhoria', 'duvida' => 'Dúvida', 'texto' => 'Texto/ortografia'];
    public const STATUS = ['nova' => 'Nova', 'analise' => 'Em análise', 'corrigida' => 'Corrigida', 'nao_procede' => 'Não procede'];
    public const RESPOSTAS = ['ok' => 'OK', 'falhou' => 'Falhou', 'na' => 'Não se aplica'];

    private static array $c = [];

    public static function configurar(array $config): void
    {
        self::$c = $config + ['base' => '/ajuda', 'sistema' => 'Sistema', 'versao' => '', 'docs' => '', 'voltar' => '/'];
        self::$c['csrf_token'] ??= function () { if (session_status() !== PHP_SESSION_ACTIVE) @session_start(); return $_SESSION['_vk_csrf'] ??= bin2hex(random_bytes(24)); };
        self::$c['csrf_ok'] ??= fn (string $t) => hash_equals((string) (self::$c['csrf_token'])(), $t);
    }

    public static function base(): string
    {
        return (string) (self::$c['base'] ?? '/ajuda');
    }

    public static function ajustar(array $config): void
    {
        self::$c = $config + self::$c;
    }

    private static bool $pronto = false;

    private static function garantir(): void
    {
        if (self::$pronto) return;
        self::$pronto = true;
        try { self::pdo()->query('SELECT 1 FROM vk_ponderacoes LIMIT 0'); self::pdo()->query('SELECT 1 FROM vk_roteiro LIMIT 0'); }
        catch (\Throwable) { try { self::instalar(); } catch (\Throwable $e) { error_log('[validacao] instalar: ' . $e->getMessage()); } }
    }

    private static function pdo(): PDO { return (self::$c['pdo'])(); }
    private static function usuario(): ?array { return (self::$c['usuario'])(); }
    private static function admin(): bool { return (bool) (self::$c['admin'])(); }
    private static function e(mixed $v): string { return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    private static function agora(): string { return gmdate('Y-m-d H:i:s'); }

    public static function instalar(): void
    {
        $pdo = self::pdo();
        $pdo->exec("CREATE TABLE IF NOT EXISTS vk_ponderacoes (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id BIGINT NULL, user_nome VARCHAR(160) NULL, perfil VARCHAR(60) NULL,
            tipo VARCHAR(20) NOT NULL, titulo VARCHAR(160) NOT NULL, texto TEXT NOT NULL, tela VARCHAR(255) NULL, passo VARCHAR(20) NULL,
            print_mime VARCHAR(30) NULL, print_dados MEDIUMBLOB NULL, status VARCHAR(20) NOT NULL DEFAULT 'nova', resposta TEXT NULL,
            respondido_em DATETIME NULL, versao VARCHAR(20) NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
            KEY vk_pond_status (status, created_at), KEY vk_pond_user (user_id)
        ) DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS vk_roteiro (
            user_id BIGINT NOT NULL, passo VARCHAR(20) NOT NULL, status VARCHAR(10) NOT NULL, obs TEXT NULL,
            user_nome VARCHAR(160) NULL, perfil VARCHAR(60) NULL, versao VARCHAR(20) NULL, updated_at DATETIME NOT NULL,
            PRIMARY KEY (user_id, passo)
        ) DEFAULT CHARSET=utf8mb4");
    }

    public static function pendentes(): int
    {
        self::garantir();
        try { return (int) self::pdo()->query("SELECT COUNT(*) FROM vk_ponderacoes WHERE status = 'nova'")->fetchColumn(); } catch (\Throwable) { return 0; }
    }

    public static function widget(): string
    {
        if (!self::usuario()) return '';
        $b = self::$c['base'];
        return '<link rel="stylesheet" href="/assets/validacao/validacao.css?v=' . self::e(self::$c['versao']) . '">'
            . '<div id="vk-raiz" data-base="' . self::e($b) . '" data-csrf="' . self::e((self::$c['csrf_token'])()) . '" data-versao="' . self::e(self::$c['versao']) . '"></div>'
            . '<script src="/assets/validacao/validacao.js?v=' . self::e(self::$c['versao']) . '" defer></script>';
    }

    public static function widgetSpa(): string
    {
        $v = self::e(self::$c['versao']);
        return '<link rel="stylesheet" href="/assets/validacao/validacao.css?v=' . $v . '">'
            . '<div id="vk-raiz" data-auto="1" data-base="' . self::e(self::$c['base']) . '" data-versao="' . $v . '"></div>'
            . '<script src="/assets/validacao/validacao.js?v=' . $v . '" defer></script>';
    }

    public static function rotear(string $method, string $path): ?string
    {
        $b = self::$c['base'];
        if ($path !== $b && !str_starts_with($path, $b . '/')) return null;
        $u = self::usuario();
        if ($method === 'GET' && $path === $b . '/estado') return self::json($u ? ['logado' => true, 'csrf' => (string) (self::$c['csrf_token'])()] : ['logado' => false]);
        if (!$u) { header('Location: ' . (self::$c['login'] ?? '/')); return ''; }
        self::garantir();
        $resto = substr($path, strlen($b)) ?: '/';
        try {
            if ($method === 'GET' && $resto === '/') return self::manual(!empty($_GET['imprimir']));
            if ($method === 'GET' && $resto === '/roteiro') return self::roteiro($u, !empty($_GET['imprimir']));
            if ($method === 'POST' && $resto === '/roteiro') return self::salvarPasso($u);
            if ($method === 'POST' && $resto === '/ponderacao') return self::salvarPonderacao($u);
            if ($method === 'GET' && $resto === '/minhas') return self::minhas($u);
            if (preg_match('#^/ponderacoes/(\d+)/print$#', $resto, $m) && $method === 'GET') return self::print((int) $m[1], $u);
            if (!self::admin()) { http_response_code(403); return self::pagina('Acesso negado', '<p>Somente o administrador do sistema vê esta área.</p>'); }
            if ($method === 'GET' && $resto === '/ponderacoes') return self::painel();
            if ($method === 'GET' && $resto === '/ponderacoes.csv') return self::csv();
            if ($method === 'GET' && $resto === '/progresso') return self::progresso();
            if ($method === 'POST' && preg_match('#^/ponderacoes/(\d+)$#', $resto, $m)) return self::responder((int) $m[1]);
        } catch (\Throwable $e) {
            error_log('[validacao] ' . $e->getMessage());
            if ($method === 'POST') return self::json(['erro' => 'Não foi possível salvar. Tente de novo.'], 500);
            http_response_code(500);
            return self::pagina('Ops', '<p>Não foi possível abrir esta página agora.</p>');
        }
        http_response_code(404);
        return self::pagina('Não encontrado', '<p>Página não encontrada.</p>');
    }

    private static function json(array $d, int $s = 200): string
    {
        http_response_code($s);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        return (string) json_encode($d, JSON_UNESCAPED_UNICODE);
    }

    private static function csrfOk(): bool
    {
        $t = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? null);
        return is_string($t) && (self::$c['csrf_ok'])($t);
    }

    private static function arquivo(string $nome): string
    {
        $p = rtrim((string) self::$c['docs'], '/') . '/' . $nome;
        return is_file($p) ? (string) file_get_contents($p) : '';
    }

    private static function abas(string $ativa): string
    {
        $b = self::$c['base'];
        $itens = ['manual' => ['Manual', $b], 'roteiro' => ['Roteiro de testes', $b . '/roteiro'], 'minhas' => ['Minhas ponderações', $b . '/minhas']];
        if (self::admin()) { $n = self::pendentes(); $itens['painel'] = ['Ponderações recebidas' . ($n ? " ({$n})" : ''), $b . '/ponderacoes']; $itens['progresso'] = ['Progresso dos testes', $b . '/progresso']; }
        $h = '<nav class="vk-abas">';
        foreach ($itens as $k => [$rot, $url]) $h .= '<a href="' . self::e($url) . '" class="' . ($k === $ativa ? 'ativa' : '') . '">' . self::e($rot) . '</a>';
        return $h . '</nav>';
    }

    private static function pagina(string $titulo, string $html, bool $solta = false): string
    {
        $html = '<div class="vk">' . $html . '</div>';
        if (!$solta && !isset(self::$c['pagina'])) {
            return '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . self::e($titulo . ' · ' . self::$c['sistema']) . '</title><link rel="stylesheet" href="/assets/validacao/validacao.css?v=' . self::e(self::$c['versao']) . '"></head><body class="vk-solo">'
                . '<header class="vk-topo"><a href="' . self::e(self::$c['voltar']) . '">← Voltar ao sistema</a><strong>' . self::e(self::$c['sistema']) . '</strong><span>Ajuda · v' . self::e(self::$c['versao']) . '</span></header><main class="vk-solo-main">' . $html . '</main>' . self::widget() . '</body></html>';
        }
        if ($solta) {
            return '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . self::e($titulo . ' · ' . self::$c['sistema']) . '</title><link rel="stylesheet" href="/assets/validacao/validacao.css?v=' . self::e(self::$c['versao']) . '"></head><body class="vk-impressao">'
                . '<header class="vk-capa"><strong>' . self::e(self::$c['sistema']) . '</strong><span>' . self::e($titulo) . ' · versão ' . self::e(self::$c['versao']) . ' · ' . date('d/m/Y') . '</span><button type="button" data-vk-imprimir class="vk-botao vk-nao-imprime">Imprimir / salvar PDF</button></header>' . $html . '<script src="/assets/validacao/validacao.js?v=' . self::e(self::$c['versao']) . '" defer></script></body></html>';
        }
        return (self::$c['pagina'])($titulo, $html);
    }

    private static function manual(bool $solta): string
    {
        $md = self::arquivo('MANUAL.md');
        if ($md === '') $md = "# Manual\n\nO manual deste sistema ainda não foi publicado.";
        $html = Markdown::html($md);
        $sumario = '';
        if (preg_match_all('/<h2 id="([^"]+)">(.*?)<\/h2>/', $html, $mm, PREG_SET_ORDER)) {
            $sumario = '<details class="vk-sumario" open><summary>Sumário</summary><ol>';
            foreach ($mm as $m) $sumario .= '<li><a href="#' . $m[1] . '">' . strip_tags($m[2]) . '</a></li>';
            $sumario .= '</ol></details>';
        }
        if ($solta) return self::pagina('Manual de processos', $sumario . '<article class="vk-doc">' . $html . '</article>', true);
        $acoes = '<div class="vk-acoes"><a class="vk-botao" href="' . self::e(self::$c['base']) . '?imprimir=1" target="_blank">Imprimir / salvar PDF</a><a class="vk-botao" href="' . self::e(self::$c['base']) . '/roteiro">Ir para o roteiro de testes</a></div>';
        return self::pagina('Ajuda', self::abas('manual') . $acoes . $sumario . '<article class="vk-doc">' . $html . '</article>');
    }

    public static function passos(): array
    {
        $md = self::arquivo('ROTEIRO.md');
        $grupos = [];
        $intro = [];
        $grupo = 'Geral';
        $atual = null;
        foreach (preg_split('/\R/', $md) as $l) {
            if (preg_match('/^##\s+(?!#)(.+)$/', $l, $m)) { if ($atual) { $grupos[$grupo][] = $atual; $atual = null; } $grupo = trim($m[1]); continue; }
            if (preg_match('/^###\s+([A-Z]{1,3}\d{1,3})\s*[·\-–—:]\s*(.+)$/u', $l, $m)) { if ($atual) $grupos[$grupo][] = $atual; $atual = ['id' => $m[1], 'titulo' => trim($m[2]), 'corpo' => '', 'grupo' => $grupo]; continue; }
            if (str_starts_with($l, '# ')) continue;
            if ($atual) $atual['corpo'] .= $l . "\n"; elseif (!$grupos) $intro[] = $l;
        }
        if ($atual) $grupos[$grupo][] = $atual;
        return ['intro' => trim(implode("\n", $intro)), 'grupos' => $grupos];
    }

    private static function roteiro(array $u, bool $solta): string
    {
        $r = self::passos();
        $st = self::pdo()->prepare('SELECT passo, status, obs FROM vk_roteiro WHERE user_id = ?');
        $st->execute([(int) $u['id']]);
        $resp = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $x) $resp[$x['passo']] = $x;
        $total = 0; $feitos = 0;
        $h = '';
        foreach ($r['grupos'] as $grupo => $passos) {
            $h .= '<section class="vk-grupo"><h2>' . self::e($grupo) . '</h2>';
            foreach ($passos as $p) {
                $total++;
                $x = $resp[$p['id']] ?? null;
                if ($x) $feitos++;
                $h .= '<article class="vk-passo ' . ($x ? 'st-' . self::e($x['status']) : '') . '" data-passo="' . self::e($p['id']) . '">'
                    . '<header><b>' . self::e($p['id']) . '</b><h3>' . self::e($p['titulo']) . '</h3>' . ($x ? '<span class="vk-selo">' . self::e(self::RESPOSTAS[$x['status']] ?? $x['status']) . '</span>' : '') . '</header>'
                    . '<div class="vk-doc">' . Markdown::html($p['corpo']) . '</div>';
                if ($solta) {
                    $h .= '<p class="vk-linha-papel">☐ OK &nbsp; ☐ Falhou &nbsp; ☐ Não se aplica &nbsp; Observação: ________________________________</p>';
                } else {
                    $h .= '<form class="vk-responder"><div class="vk-opcoes">';
                    foreach (self::RESPOSTAS as $k => $rot) $h .= '<label><input type="radio" name="status" value="' . $k . '"' . (($x['status'] ?? '') === $k ? ' checked' : '') . '> ' . $rot . '</label>';
                    $h .= '</div><textarea name="obs" rows="2" maxlength="2000" placeholder="Observação (obrigatória se falhou: o que aconteceu?)">' . self::e($x['obs'] ?? '') . '</textarea><button class="vk-botao primario">Salvar</button><span class="vk-msg"></span></form>';
                }
                $h .= '</article>';
            }
            $h .= '</section>';
        }
        if (!$total) $h = '<p>O roteiro de testes deste sistema ainda não foi publicado.</p>';
        $topo = '<div class="vk-intro vk-doc">' . Markdown::html($r['intro']) . '</div>';
        if ($solta) return self::pagina('Roteiro de testes', $topo . $h, true);
        $barra = $total ? '<div class="vk-progresso"><div style="width:' . (int) round($feitos / $total * 100) . '%"></div><span>' . $feitos . ' de ' . $total . ' passos respondidos</span></div>' : '';
        $acoes = '<div class="vk-acoes"><a class="vk-botao" href="' . self::e(self::$c['base']) . '/roteiro?imprimir=1" target="_blank">Imprimir roteiro em papel</a></div>';
        return self::pagina('Roteiro de testes', self::abas('roteiro') . $acoes . $topo . $barra . $h);
    }

    private static function salvarPasso(array $u): string
    {
        if (!self::csrfOk()) return self::json(['erro' => 'Sessão expirada. Recarregue a página.'], 419);
        $passo = (string) ($_POST['passo'] ?? '');
        $status = (string) ($_POST['status'] ?? '');
        $obs = trim((string) ($_POST['obs'] ?? ''));
        $validos = [];
        foreach (self::passos()['grupos'] as $ps) foreach ($ps as $p) $validos[$p['id']] = $p;
        if (!isset($validos[$passo]) || !isset(self::RESPOSTAS[$status])) return self::json(['erro' => 'Passo ou resposta inválida.'], 422);
        if ($status === 'falhou' && mb_strlen($obs) < 5) return self::json(['erro' => 'Conte o que aconteceu na observação.'], 422);
        $obs = mb_substr($obs, 0, 2000);
        self::pdo()->prepare('REPLACE INTO vk_roteiro (user_id, passo, status, obs, user_nome, perfil, versao, updated_at) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([(int) $u['id'], $passo, $status, $obs ?: null, $u['nome'] ?? null, $u['perfil'] ?? null, self::$c['versao'], self::agora()]);
        if ($status === 'falhou' || ($obs !== '' && $status === 'ok')) {
            $tipo = $status === 'falhou' ? 'erro' : 'melhoria';
            $ja = self::pdo()->prepare("SELECT id FROM vk_ponderacoes WHERE user_id = ? AND passo = ? AND status IN ('nova','analise')");
            $ja->execute([(int) $u['id'], $passo]);
            $titulo = mb_substr($passo . ' · ' . $validos[$passo]['titulo'], 0, 160);
            if ($id = $ja->fetchColumn()) self::pdo()->prepare('UPDATE vk_ponderacoes SET tipo = ?, texto = ?, updated_at = ? WHERE id = ?')->execute([$tipo, $obs, self::agora(), (int) $id]);
            else self::inserir($u, $tipo, $titulo, $obs, self::$c['base'] . '/roteiro#' . $passo, $passo, null, null);
        }
        return self::json(['ok' => true]);
    }

    private static function inserir(array $u, string $tipo, string $titulo, string $texto, ?string $tela, ?string $passo, ?string $mime, ?string $dados): void
    {
        $st = self::pdo()->prepare('INSERT INTO vk_ponderacoes (user_id, user_nome, perfil, tipo, titulo, texto, tela, passo, print_mime, print_dados, status, versao, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $st->bindValue(1, (int) $u['id'], PDO::PARAM_INT);
        $st->bindValue(2, mb_substr((string) ($u['nome'] ?? ''), 0, 160));
        $st->bindValue(3, mb_substr((string) ($u['perfil'] ?? ''), 0, 60));
        $st->bindValue(4, $tipo);
        $st->bindValue(5, $titulo);
        $st->bindValue(6, $texto);
        $st->bindValue(7, $tela ? mb_substr($tela, 0, 255) : null);
        $st->bindValue(8, $passo);
        $st->bindValue(9, $mime);
        $st->bindValue(10, $dados, $dados === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
        $st->bindValue(11, 'nova');
        $st->bindValue(12, self::$c['versao']);
        $st->bindValue(13, self::agora());
        $st->bindValue(14, self::agora());
        $st->execute();
    }

    private static function salvarPonderacao(array $u): string
    {
        if (!self::csrfOk()) return self::json(['erro' => 'Sessão expirada. Recarregue a página.'], 419);
        $tipo = (string) ($_POST['tipo'] ?? '');
        $titulo = trim((string) ($_POST['titulo'] ?? ''));
        $texto = trim((string) ($_POST['texto'] ?? ''));
        $tela = (string) ($_POST['tela'] ?? '');
        if (!isset(self::TIPOS[$tipo])) return self::json(['erro' => 'Escolha o tipo.'], 422);
        if (mb_strlen($titulo) < 3 || mb_strlen($titulo) > 160) return self::json(['erro' => 'Dê um título curto (3 a 160 caracteres).'], 422);
        if (mb_strlen($texto) < 5 || mb_strlen($texto) > 5000) return self::json(['erro' => 'Descreva com pelo menos 5 caracteres (máximo 5.000).'], 422);
        if (!str_starts_with($tela, '/')) $tela = '';
        $mime = null; $dados = null;
        $print = (string) ($_POST['print'] ?? '');
        if ($print !== '') {
            if (!preg_match('#^data:(image/(png|jpeg|webp));base64,([A-Za-z0-9+/=]+)$#', $print, $m)) return self::json(['erro' => 'O print precisa ser uma imagem PNG, JPG ou WebP.'], 422);
            $bin = base64_decode($m[3], true);
            if ($bin === false || strlen($bin) > 3 * 1024 * 1024) return self::json(['erro' => 'Imagem grande demais (máximo 3 MB).'], 422);
            $sig = ['image/png' => "\x89PNG", 'image/jpeg' => "\xFF\xD8\xFF", 'image/webp' => 'RIFF'][$m[1]];
            if (!str_starts_with($bin, $sig)) return self::json(['erro' => 'Arquivo de imagem inválido.'], 422);
            $mime = $m[1]; $dados = $bin;
        }
        $lim = self::pdo()->prepare('SELECT COUNT(*) FROM vk_ponderacoes WHERE user_id = ? AND created_at > ?');
        $lim->execute([(int) $u['id'], gmdate('Y-m-d H:i:s', time() - 3600)]);
        if ((int) $lim->fetchColumn() >= 40) return self::json(['erro' => 'Muitas ponderações seguidas. Aguarde um pouco.'], 429);
        self::inserir($u, $tipo, $titulo, $texto, $tela ?: null, null, $mime, $dados);
        return self::json(['ok' => true]);
    }

    private static function cartao(array $p, bool $admin): string
    {
        $h = '<article class="vk-pond st-' . self::e($p['status']) . '"><header><span class="vk-tipo t-' . self::e($p['tipo']) . '">' . self::e(self::TIPOS[$p['tipo']] ?? $p['tipo']) . '</span><strong>#' . (int) $p['id'] . ' ' . self::e($p['titulo']) . '</strong><span class="vk-selo">' . self::e(self::STATUS[$p['status']] ?? $p['status']) . '</span></header>'
            . '<p class="vk-meta">' . self::e(self::data($p['created_at'])) . ($admin ? ' · ' . self::e($p['user_nome']) . ($p['perfil'] ? ' (' . self::e($p['perfil']) . ')' : '') : '') . ($p['tela'] ? ' · tela <code>' . self::e($p['tela']) . '</code>' : '') . ' · v' . self::e($p['versao']) . '</p>'
            . '<p class="vk-texto">' . nl2br(self::e($p['texto'])) . '</p>';
        if (!empty($p['tem_print'])) $h .= '<a class="vk-print" href="' . self::e(self::$c['base']) . '/ponderacoes/' . (int) $p['id'] . '/print" target="_blank"><img loading="lazy" src="' . self::e(self::$c['base']) . '/ponderacoes/' . (int) $p['id'] . '/print" alt="Print enviado"></a>';
        if ($p['resposta']) $h .= '<div class="vk-resposta"><b>Resposta' . ($p['respondido_em'] ? ' · ' . self::e(self::data($p['respondido_em'])) : '') . ':</b> ' . nl2br(self::e($p['resposta'])) . '</div>';
        if ($admin) {
            $h .= '<form method="post" action="' . self::e(self::$c['base']) . '/ponderacoes/' . (int) $p['id'] . '" class="vk-form-resp"><input type="hidden" name="_csrf" value="' . self::e((self::$c['csrf_token'])()) . '"><select name="status">';
            foreach (self::STATUS as $k => $rot) $h .= '<option value="' . $k . '"' . ($k === $p['status'] ? ' selected' : '') . '>' . $rot . '</option>';
            $h .= '</select><textarea name="resposta" rows="2" maxlength="3000" placeholder="Resposta para quem enviou (opcional)">' . self::e($p['resposta'] ?? '') . '</textarea><button class="vk-botao primario">Salvar</button></form>';
        }
        return $h . '</article>';
    }

    private static function data(?string $utc): string
    {
        if (!$utc) return '';
        try { return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('America/Sao_Paulo'))->format('d/m/Y H:i'); } catch (\Throwable) { return $utc; }
    }

    private const CAMPOS = 'id, user_id, user_nome, perfil, tipo, titulo, texto, tela, passo, status, resposta, respondido_em, versao, created_at, (print_dados IS NOT NULL) AS tem_print';

    private static function minhas(array $u): string
    {
        $st = self::pdo()->prepare('SELECT ' . self::CAMPOS . ' FROM vk_ponderacoes WHERE user_id = ? ORDER BY id DESC LIMIT 200');
        $st->execute([(int) $u['id']]);
        $lista = $st->fetchAll(PDO::FETCH_ASSOC);
        $h = $lista ? implode('', array_map(fn ($p) => self::cartao($p, false), $lista)) : '<div class="vk-vazio">Você ainda não enviou ponderações. Use o botão <b>Ponderação</b> no canto da tela, em qualquer página.</div>';
        return self::pagina('Minhas ponderações', self::abas('minhas') . $h);
    }

    private static function painel(): string
    {
        $status = (string) ($_GET['status'] ?? 'abertas');
        $tipo = (string) ($_GET['tipo'] ?? '');
        $sql = 'SELECT ' . self::CAMPOS . ' FROM vk_ponderacoes WHERE 1=1';
        $p = [];
        if ($status === 'abertas') $sql .= " AND status IN ('nova','analise')";
        elseif (isset(self::STATUS[$status])) { $sql .= ' AND status = ?'; $p[] = $status; }
        if (isset(self::TIPOS[$tipo])) { $sql .= ' AND tipo = ?'; $p[] = $tipo; }
        $st = self::pdo()->prepare($sql . ' ORDER BY FIELD(status, \'nova\', \'analise\', \'corrigida\', \'nao_procede\'), id DESC LIMIT 300');
        $st->execute($p);
        $lista = $st->fetchAll(PDO::FETCH_ASSOC);
        $cont = [];
        foreach (self::pdo()->query('SELECT status, COUNT(*) n FROM vk_ponderacoes GROUP BY status')->fetchAll(PDO::FETCH_ASSOC) as $r) $cont[$r['status']] = (int) $r['n'];
        $b = self::e(self::$c['base']);
        $f = '<form class="vk-filtros" method="get" action="' . $b . '/ponderacoes"><select name="status"><option value="abertas">Abertas (nova + em análise)</option><option value="todas"' . ($status === 'todas' ? ' selected' : '') . '>Todas</option>';
        foreach (self::STATUS as $k => $rot) $f .= '<option value="' . $k . '"' . ($status === $k ? ' selected' : '') . '>' . $rot . ' (' . ($cont[$k] ?? 0) . ')</option>';
        $f .= '</select><select name="tipo"><option value="">Todos os tipos</option>';
        foreach (self::TIPOS as $k => $rot) $f .= '<option value="' . $k . '"' . ($tipo === $k ? ' selected' : '') . '>' . $rot . '</option>';
        $f .= '</select><button class="vk-botao">Filtrar</button><a class="vk-botao" href="' . $b . '/ponderacoes.csv">Exportar CSV</a></form>';
        $resumo = '<div class="vk-resumo">';
        foreach (self::STATUS as $k => $rot) $resumo .= '<div class="st-' . $k . '"><small>' . $rot . '</small><strong>' . ($cont[$k] ?? 0) . '</strong></div>';
        $resumo .= '</div>';
        $h = $lista ? implode('', array_map(fn ($x) => self::cartao($x, true), $lista)) : '<div class="vk-vazio">Nenhuma ponderação com estes filtros.</div>';
        return self::pagina('Ponderações recebidas', self::abas('painel') . $resumo . $f . $h);
    }

    private static function responder(int $id): string
    {
        if (!self::csrfOk()) { http_response_code(419); return self::pagina('Sessão expirada', '<p>Recarregue a página e tente de novo.</p>'); }
        $status = (string) ($_POST['status'] ?? '');
        $resposta = trim((string) ($_POST['resposta'] ?? ''));
        if (!isset(self::STATUS[$status])) $status = 'analise';
        self::pdo()->prepare('UPDATE vk_ponderacoes SET status = ?, resposta = ?, respondido_em = ?, updated_at = ? WHERE id = ?')
            ->execute([$status, $resposta !== '' ? mb_substr($resposta, 0, 3000) : null, $resposta !== '' ? self::agora() : null, self::agora(), $id]);
        header('Location: ' . self::$c['base'] . '/ponderacoes');
        return '';
    }

    private static function print(int $id, array $u): string
    {
        $st = self::pdo()->prepare('SELECT user_id, print_mime, print_dados FROM vk_ponderacoes WHERE id = ?');
        $st->execute([$id]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p || !$p['print_dados'] || (!self::admin() && (int) $p['user_id'] !== (int) $u['id'])) { http_response_code(404); return ''; }
        header('Content-Type: ' . $p['print_mime']);
        header('Cache-Control: private, max-age=3600');
        header('X-Content-Type-Options: nosniff');
        return is_resource($p['print_dados']) ? (string) stream_get_contents($p['print_dados']) : (string) $p['print_dados'];
    }

    private static function csv(): string
    {
        $rows = self::pdo()->query('SELECT id, created_at, user_nome, perfil, tipo, status, titulo, texto, tela, passo, versao, resposta FROM vk_ponderacoes ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="ponderacoes-' . date('Ymd') . '.csv"');
        $out = fopen('php://temp', 'w+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['ID', 'Data', 'Quem', 'Perfil', 'Tipo', 'Status', 'Título', 'Descrição', 'Tela', 'Passo', 'Versão', 'Resposta'], ';', '"', '\\');
        foreach ($rows as $r) { $r['created_at'] = self::data($r['created_at']); $r['tipo'] = self::TIPOS[$r['tipo']] ?? $r['tipo']; $r['status'] = self::STATUS[$r['status']] ?? $r['status']; fputcsv($out, array_values($r), ';', '"', '\\'); }
        rewind($out);
        return (string) stream_get_contents($out);
    }

    private static function progresso(): string
    {
        $r = self::passos();
        $passos = [];
        foreach ($r['grupos'] as $ps) foreach ($ps as $p) $passos[$p['id']] = $p;
        $rows = self::pdo()->query('SELECT user_id, user_nome, perfil, passo, status, obs, updated_at FROM vk_roteiro ORDER BY user_nome, passo')->fetchAll(PDO::FETCH_ASSOC);
        $pessoas = [];
        foreach ($rows as $x) { $pessoas[$x['user_id']]['nome'] = $x['user_nome'] . ($x['perfil'] ? ' (' . $x['perfil'] . ')' : ''); $pessoas[$x['user_id']]['r'][$x['passo']] = $x; }
        if (!$pessoas) return self::pagina('Progresso dos testes', self::abas('progresso') . '<div class="vk-vazio">Ninguém respondeu o roteiro ainda.</div>');
        $h = '<div class="vk-tabela"><table><tr><th>Passo</th>';
        foreach ($pessoas as $p) $h .= '<th>' . self::e($p['nome']) . '</th>';
        $h .= '</tr>';
        $ic = ['ok' => '✔', 'falhou' => '✖', 'na' => '—'];
        foreach ($passos as $id => $p) {
            $h .= '<tr><td><b>' . self::e($id) . '</b> ' . self::e($p['titulo']) . '</td>';
            foreach ($pessoas as $pe) { $x = $pe['r'][$id] ?? null; $h .= '<td class="' . ($x ? 'st-' . self::e($x['status']) : '') . '" title="' . self::e($x['obs'] ?? '') . '">' . ($x ? $ic[$x['status']] ?? '?' : '') . '</td>'; }
            $h .= '</tr>';
        }
        $h .= '</table></div><p class="vk-meta">Passe o mouse (ou toque) na célula para ver a observação. Passos que falharam viram ponderação automaticamente.</p>';
        return self::pagina('Progresso dos testes', self::abas('progresso') . $h);
    }
}
