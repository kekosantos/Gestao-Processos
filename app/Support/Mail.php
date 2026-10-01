<?php
declare(strict_types=1);

namespace LexCloud\Support;

/**
 * Envio de e-mail por SMTP, sem bibliotecas externas.
 *
 * A função mail() do PHP não funciona na imagem Docker do Fly.io (não há
 * servidor de e-mail instalado) — por isso o envio é feito direto no SMTP
 * do provedor (Gmail, Outlook, Zoho, Brevo, Locaweb...).
 *
 * Configuração (secrets do Fly.io):
 *   MAIL_HOST       ex.: smtp.gmail.com
 *   MAIL_PORT       587 (tls) ou 465 (ssl)
 *   MAIL_SECURE     tls | ssl | none            (padrão: tls)
 *   MAIL_USER       usuário do SMTP (normalmente o e-mail)
 *   MAIL_PASS       senha / senha de app
 *   MAIL_FROM       remetente (padrão: MAIL_USER)
 *   MAIL_FROM_NAME  nome do remetente (padrão: nome do sistema)
 *
 * Mesma classe dos sistemas da CHS (Gestão de Notas, Gestão Contábil, Portal de Igrejas).
 */
final class Mail
{
    private static function env(string $k, $padrao = null) { $v = getenv($k); return ($v === false || $v === '') ? $padrao : $v; }

    private $sock;
    private array $log = [];

    public static function configurado(): bool
    {
        return self::env('MAIL_HOST', '') !== '';
    }

    /**
     * @return array{ok:bool, erro?:string}
     */
    public static function enviar(string $para, string $assunto, string $html, ?string $texto = null): array
    {
        if (!self::configurado()) {
            return ['ok' => false, 'erro' => 'SMTP não configurado (MAIL_HOST vazio).'];
        }
        if (!filter_var($para, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'erro' => 'Destinatário inválido.'];
        }
        $m = new self();
        try {
            $m->mandar($para, $assunto, $html, $texto ?? self::textoDe($html));
            return ['ok' => true];
        } catch (\Throwable $e) {
            error_log('MailService: ' . $e->getMessage() . ' | ' . implode(' / ', array_slice($m->log, -4)));
            return ['ok' => false, 'erro' => $e->getMessage()];
        } finally {
            if (is_resource($m->sock)) @fclose($m->sock);
        }
    }

    private function mandar(string $para, string $assunto, string $html, string $texto): void
    {
        $host   = self::env('MAIL_HOST');
        $secure = strtolower(self::env('MAIL_SECURE', 'tls'));
        $porta  = (int) self::env('MAIL_PORT', $secure === 'ssl' ? 465 : 587);
        $user   = self::env('MAIL_USER', '');
        $pass   = self::env('MAIL_PASS', '');
        $from   = self::env('MAIL_FROM', $user);
        $nome   = self::env('MAIL_FROM_NAME', 'AdvCloud');
        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) throw new \RuntimeException('MAIL_FROM inválido.');

        $alvo = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $porta;
        $ctx  = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host]]);
        $this->sock = @stream_socket_client($alvo, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$this->sock) throw new \RuntimeException("Não conectou no SMTP {$host}:{$porta} ({$errstr}).");
        stream_set_timeout($this->sock, 20);

        $this->esperar(220);
        $ehlo = gethostname() ?: 'localhost';
        $this->cmd("EHLO {$ehlo}", 250);
        if ($secure === 'tls') {
            $this->cmd('STARTTLS', 220);
            if (!stream_socket_enable_crypto($this->sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new \RuntimeException('Falha ao iniciar TLS com o servidor SMTP.');
            }
            $this->cmd("EHLO {$ehlo}", 250);
        }
        if ($user !== '') {
            $this->cmd('AUTH LOGIN', 334);
            $this->cmd(base64_encode($user), 334);
            $this->cmd(base64_encode($pass), 235, true);
        }
        $this->cmd("MAIL FROM:<{$from}>", 250);
        $this->cmd("RCPT TO:<{$para}>", [250, 251]);
        $this->cmd('DATA', 354);

        $limite = 'b' . bin2hex(random_bytes(8));
        $cab = [
            'Date: ' . date('r'),
            'From: ' . self::cabecalho($nome) . " <{$from}>",
            "To: <{$para}>",
            'Subject: ' . self::cabecalho($assunto),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (explode('@', $from)[1] ?? 'localhost') . '>',
            'MIME-Version: 1.0',
            "Content-Type: multipart/alternative; boundary=\"{$limite}\"",
        ];
        $corpo = "--{$limite}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
               . chunk_split(base64_encode($texto))
               . "--{$limite}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
               . chunk_split(base64_encode($html))
               . "--{$limite}--\r\n";
        // base64 não tem linhas começando com ".", então não precisa de dot-stuffing
        fwrite($this->sock, implode("\r\n", $cab) . "\r\n\r\n" . $corpo . ".\r\n");
        $this->esperar(250);
        $this->cmd('QUIT', [221, 250]);
    }

    private function cmd(string $linha, $esperado, bool $sigilo = false): void
    {
        $this->log[] = '> ' . ($sigilo ? '******' : $linha);
        fwrite($this->sock, $linha . "\r\n");
        $this->esperar($esperado);
    }

    private function esperar($esperado): void
    {
        $resp = '';
        while (($l = fgets($this->sock, 1024)) !== false) {
            $resp .= $l;
            if (strlen($l) < 4 || $l[3] !== '-') break; // última linha da resposta
        }
        $this->log[] = '< ' . trim($resp);
        $codigo = (int) substr($resp, 0, 3);
        if (!in_array($codigo, (array) $esperado, true)) {
            throw new \RuntimeException('SMTP respondeu: ' . (trim($resp) ?: 'sem resposta (tempo esgotado)'));
        }
    }

    private static function cabecalho(string $s): string
    {
        return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
    }

    private static function textoDe(string $html): string
    {
        $t = preg_replace('/<a [^>]*href="([^"]+)"[^>]*>(.*?)<\/a>/is', '$2: $1', $html);
        $t = preg_replace('/<(br|\/p|\/div|\/h\d)[^>]*>/i', "\n", $t);
        return trim(html_entity_decode(strip_tags($t), ENT_QUOTES, 'UTF-8'));
    }
}
