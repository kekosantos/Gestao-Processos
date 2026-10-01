<?php
declare(strict_types=1);

namespace LexCloud\Support;

use Exception;

/**
 * Biometria (digital, Face ID, Windows Hello) sem dependências externas.
 * Mesmo motor do Gestão de Notas: assinatura verificada (ES256 e RS256), desafio de uso único,
 * origem e domínio conferidos, biometria (UV) obrigatória e contador contra clonagem.
 */
final class WebAuthn
{
    private string $rpId;
    private string $rpName;
    private string $origin;

    public function __construct()
    {
        // APP_URL define o domínio da biometria. Sem ela, usa o endereço desta requisição (atrás do proxy do Render/Fly)
        $url = (string) (getenv('APP_URL') ?: '');
        if ($url === '') {
            $https = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https' || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
            $url = ($https ? 'https' : 'http') . '://' . (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        }
        $scheme = parse_url($url, PHP_URL_SCHEME) ?: 'https';
        $host   = parse_url($url, PHP_URL_HOST) ?: 'localhost';
        $port   = parse_url($url, PHP_URL_PORT);

        $this->rpId   = $host;
        $this->rpName = 'AdvCloud';
        // A origem enviada pelo navegador é só esquema://host[:porta], sem caminho nem barra final
        $this->origin = $scheme . '://' . $host . ($port ? ':' . $port : '');
    }

    // ── Desafio (uso único) ────────────────────────────────────────────────

    private function generateChallenge(): string
    {
        $challenge = random_bytes(32);
        $_SESSION['webauthn_challenge'] = base64_encode($challenge);
        return $this->base64url_encode($challenge);
    }

    /** Lê e descarta o desafio da sessão — impede reutilização (replay). */
    private function consumeChallenge(): string
    {
        $challenge = $_SESSION['webauthn_challenge'] ?? '';
        unset($_SESSION['webauthn_challenge']);
        if (!$challenge) {
            throw new Exception('A solicitação expirou. Tente novamente.');
        }
        return $this->base64url_encode(base64_decode($challenge));
    }

    // ── Cadastro ───────────────────────────────────────────────────────────

    /**
     * @param array $excluirIds credential_ids já cadastrados deste usuário (evita duplicar o mesmo aparelho)
     */
    public function getRegistrationOptions(int $userId, string $userName, string $userType, array $excluirIds = []): array
    {
        $challenge = $this->generateChallenge();
        $_SESSION['webauthn_reg_user'] = ['id' => $userId, 'type' => $userType];

        return [
            'challenge' => $challenge,
            'rp'        => ['name' => $this->rpName, 'id' => $this->rpId],
            'user'      => [
                'id'          => $this->base64url_encode(pack('N', $userId) . $userType),
                'name'        => $userName,
                'displayName' => $userName,
            ],
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => -7],    // ES256 (celulares, Mac)
                ['type' => 'public-key', 'alg' => -257],  // RS256 (Windows Hello)
            ],
            'authenticatorSelection' => [
                'authenticatorAttachment' => 'platform',
                // Credencial localizável: o login não informa o usuário antes,
                // então o aparelho precisa conseguir achar a chave sozinho
                'residentKey'             => 'required',
                'requireResidentKey'      => true,
                'userVerification'        => 'required',
            ],
            'excludeCredentials' => array_map(
                fn($id) => ['type' => 'public-key', 'id' => $id],
                $excluirIds
            ),
            'timeout'     => 60000,
            'attestation' => 'none',
        ];
    }

    public function verifyRegistration(array $credential): array
    {
        $expectedChallenge = $this->consumeChallenge();

        $clientDataJSON = base64_decode($this->base64url_decode_str($credential['response']['clientDataJSON'] ?? ''));
        $attestationObj = base64_decode($this->base64url_decode_str($credential['response']['attestationObject'] ?? ''));

        $clientData = json_decode($clientDataJSON, true);
        if (!$clientData) throw new Exception('Resposta do aparelho inválida.');

        if (($clientData['type'] ?? '') !== 'webauthn.create') throw new Exception('Tipo de operação inválido.');
        if (!hash_equals($expectedChallenge, (string)($clientData['challenge'] ?? ''))) throw new Exception('Desafio inválido. Tente novamente.');
        if (($clientData['origin'] ?? '') !== $this->origin) throw new Exception('Origem inválida: ' . ($clientData['origin'] ?? '?'));

        $offset   = 0;
        $attObj   = $this->cborDecode($attestationObj, $offset);
        $authData = $attObj['authData'] ?? '';
        if ($authData === '') throw new Exception('Dados do aparelho ausentes.');

        $parsed = $this->parseAuthData($authData);

        if (!hash_equals(hash('sha256', $this->rpId, true), $parsed['rpIdHash'])) throw new Exception('Domínio (rpId) inválido.');
        if (!($parsed['flags'] & 0x01)) throw new Exception('Presença do usuário não confirmada.');
        if (!($parsed['flags'] & 0x04)) throw new Exception('Biometria não confirmada.');
        if (!isset($parsed['credentialId'])) throw new Exception('Identificador da credencial não encontrado.');

        return [
            'credential_id' => $this->base64url_encode($parsed['credentialId']),
            'public_key'    => base64_encode($parsed['publicKeyRaw']),
            'sign_count'    => $parsed['signCount'],
            'alg'           => $parsed['alg'] ?? -7,
        ];
    }

    // ── Login ──────────────────────────────────────────────────────────────

    public function getAuthenticationOptions(): array
    {
        return [
            'challenge'        => $this->generateChallenge(),
            'rpId'             => $this->rpId,
            'userVerification' => 'required',
            'timeout'          => 60000,
        ];
    }

    public function verifyAuthentication(array $credential, string $publicKeyB64, int $storedSignCount): array
    {
        $expectedChallenge = $this->consumeChallenge();

        $clientDataJSON = base64_decode($this->base64url_decode_str($credential['response']['clientDataJSON'] ?? ''));
        $authData       = base64_decode($this->base64url_decode_str($credential['response']['authenticatorData'] ?? ''));
        $signature      = base64_decode($this->base64url_decode_str($credential['response']['signature'] ?? ''));

        $clientData = json_decode($clientDataJSON, true);
        if (!$clientData) throw new Exception('Resposta do aparelho inválida.');

        if (($clientData['type'] ?? '') !== 'webauthn.get') throw new Exception('Tipo de operação inválido.');
        if (!hash_equals($expectedChallenge, (string)($clientData['challenge'] ?? ''))) throw new Exception('Desafio inválido. Tente novamente.');
        if (($clientData['origin'] ?? '') !== $this->origin) throw new Exception('Origem inválida.');

        $parsed = $this->parseAuthData($authData);
        if (!hash_equals(hash('sha256', $this->rpId, true), $parsed['rpIdHash'])) throw new Exception('Domínio (rpId) inválido.');
        if (!($parsed['flags'] & 0x01)) throw new Exception('Presença do usuário não confirmada.');
        if (!($parsed['flags'] & 0x04)) throw new Exception('Biometria não confirmada.');

        // Contador anti-clonagem: só vale quando o aparelho usa contador
        // (chaves sincronizadas do Google/Apple enviam sempre 0)
        if ($storedSignCount > 0 && $parsed['signCount'] > 0 && $parsed['signCount'] <= $storedSignCount) {
            throw new Exception('Credencial possivelmente clonada — cadastre a biometria novamente.');
        }

        $pem = $this->derToPem(base64_decode($publicKeyB64));
        $ok  = openssl_verify($authData . hash('sha256', $clientDataJSON, true), $signature, $pem, OPENSSL_ALGO_SHA256);
        if ($ok !== 1) throw new Exception('Assinatura inválida.');

        return ['sign_count' => $parsed['signCount']];
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function parseAuthData(string $authData): array
    {
        if (strlen($authData) < 37) throw new Exception('Dados do aparelho incompletos.');

        $offset    = 0;
        $rpIdHash  = substr($authData, $offset, 32); $offset += 32;
        $flags     = ord($authData[$offset]);        $offset += 1;
        $signCount = unpack('N', substr($authData, $offset, 4))[1]; $offset += 4;

        $result = ['rpIdHash' => $rpIdHash, 'flags' => $flags, 'signCount' => $signCount];

        // Dados da credencial (flag AT)
        if (($flags & 0x40) && strlen($authData) > $offset) {
            $offset += 16; // aaguid
            $credIdLen    = unpack('n', substr($authData, $offset, 2))[1]; $offset += 2;
            $credentialId = substr($authData, $offset, $credIdLen);        $offset += $credIdLen;

            $publicKeyMap = $this->cborDecode($authData, $offset);

            $result['credentialId'] = $credentialId;
            $result['publicKeyRaw'] = $this->coseToPublicKeyDer($publicKeyMap);
            $result['alg']          = $publicKeyMap[3] ?? -7;
        }

        return $result;
    }

    public function coseToPublicKeyDer(array $cose): string
    {
        $kty = $cose[1] ?? null;

        if ($kty === 2) {
            // EC2 (ES256) — curva P-256
            $x = $cose[-2] ?? '';
            $y = $cose[-3] ?? '';
            if (strlen($x) !== 32 || strlen($y) !== 32) throw new Exception('Chave EC inválida.');

            $oid       = "\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";
            $point     = "\x04" . $x . $y;
            $bitstring = "\x03" . $this->derLength(strlen($point) + 1) . "\x00" . $point;
            $seq       = $oid . $bitstring;
            return "\x30" . $this->derLength(strlen($seq)) . $seq;
        }

        if ($kty === 3) {
            // RSA (RS256) — Windows Hello
            $rsaSeq = $this->derInteger($cose[-1] ?? '') . $this->derInteger($cose[-2] ?? '');
            $rsaKey = "\x30" . $this->derLength(strlen($rsaSeq)) . $rsaSeq;
            $oid    = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
            $bs     = "\x03" . $this->derLength(strlen($rsaKey) + 1) . "\x00" . $rsaKey;
            $seq    = $oid . $bs;
            return "\x30" . $this->derLength(strlen($seq)) . $seq;
        }

        throw new Exception('Tipo de chave não suportado: kty=' . var_export($kty, true));
    }

    /** INTEGER DER sem sinal: prefixa 0x00 quando o bit mais alto está ligado. */
    private function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '' || (ord($bytes[0]) & 0x80)) $bytes = "\x00" . $bytes;
        return "\x02" . $this->derLength(strlen($bytes)) . $bytes;
    }

    private function derLength(int $len): string
    {
        if ($len < 128) return chr($len);
        if ($len < 256) return "\x81" . chr($len);
        return "\x82" . chr($len >> 8) . chr($len & 0xff);
    }

    private function derToPem(string $der): string
    {
        return "-----BEGIN PUBLIC KEY-----\n"
             . chunk_split(base64_encode($der), 64, "\n")
             . "-----END PUBLIC KEY-----";
    }

    // CBOR mínimo para WebAuthn
    private function cborDecode(string $data, int &$offset = 0): mixed
    {
        if (!isset($data[$offset])) throw new Exception('CBOR truncado.');
        $ib = ord($data[$offset++]);
        $mt = ($ib >> 5) & 0x07;
        $ai = $ib & 0x1f;

        if ($ai < 24)        $val = $ai;
        elseif ($ai === 24)  { $val = ord($data[$offset]); $offset += 1; }
        elseif ($ai === 25)  { $val = unpack('n', substr($data, $offset, 2))[1]; $offset += 2; }
        elseif ($ai === 26)  { $val = unpack('N', substr($data, $offset, 4))[1]; $offset += 4; }
        elseif ($ai === 27)  { $val = unpack('J', substr($data, $offset, 8))[1]; $offset += 8; }
        else                 $val = 0;

        switch ($mt) {
            case 0: return $val;
            case 1: return -1 - $val;
            case 2:
            case 3: $r = substr($data, $offset, $val); $offset += $val; return $r;
            case 4: $a = []; for ($i = 0; $i < $val; $i++) $a[] = $this->cborDecode($data, $offset); return $a;
            case 5: $m = []; for ($i = 0; $i < $val; $i++) { $k = $this->cborDecode($data, $offset); $m[$k] = $this->cborDecode($data, $offset); } return $m;
            case 7: return $ai === 21 ? true : ($ai === 20 ? false : null);
            default: return null;
        }
    }

    private function base64url_encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /** Converte base64url para base64 padrão (ainda codificado). */
    private function base64url_decode_str(string $data): string
    {
        $pad = strlen($data) % 4;
        if ($pad) $data .= str_repeat('=', 4 - $pad);
        return strtr($data, '-_', '+/');
    }
}
