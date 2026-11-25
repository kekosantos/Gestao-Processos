<?php
declare(strict_types=1);

namespace LexCloud\Support;

use Exception;

final class WebAuthn
{
    private string $rpId;
    private string $rpName;
    private string $origin;

    public function __construct()
    {
        $url = (string) (getenv('APP_URL') ?: '');
        if ($url === '') {
            $https = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https' || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
            $url = ($https ? 'https' : 'http') . '://' . (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        }
        $scheme = parse_url($url, PHP_URL_SCHEME) ?: 'https';
        $host   = parse_url($url, PHP_URL_HOST) ?: 'localhost';
        $port   = parse_url($url, PHP_URL_PORT);
        $pedido = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')));
        if ($pedido !== '' && preg_match('/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*(:\d{1,5})?$/', $pedido)) {
            $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
            $semPorta = (string) preg_replace('/:\d+$/', '', $pedido);
            if ($https || $semPorta === 'localhost') {
                $scheme = $https ? 'https' : 'http';
                $host = $semPorta;
                $port = preg_match('/:(\d+)$/', $pedido, $m) ? (int) $m[1] : null;
            }
        }

        $this->rpId   = $host;
        $this->rpName = 'AdvCloud';
        $this->origin = $scheme . '://' . $host . ($port ? ':' . $port : '');
    }


    private function generateChallenge(): string
    {
        $challenge = random_bytes(32);
        $_SESSION['webauthn_challenge'] = base64_encode($challenge);
        return $this->base64url_encode($challenge);
    }

    private function consumeChallenge(): string
    {
        $challenge = $_SESSION['webauthn_challenge'] ?? '';
        unset($_SESSION['webauthn_challenge']);
        if (!$challenge) {
            throw new Exception('A solicitação expirou. Tente novamente.');
        }
        return $this->base64url_encode(base64_decode($challenge));
    }


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
                ['type' => 'public-key', 'alg' => -7],
                ['type' => 'public-key', 'alg' => -257],
            ],
            'authenticatorSelection' => [
                'authenticatorAttachment' => 'platform',
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


    public function getAuthenticationOptions(array $permitidas = []): array
    {
        $opts = [
            'challenge'        => $this->generateChallenge(),
            'rpId'             => $this->rpId,
            'userVerification' => 'required',
            'timeout'          => 60000,
        ];
        if ($permitidas) $opts['allowCredentials'] = array_map(fn($id) => ['type' => 'public-key', 'id' => $id, 'transports' => ['internal']], array_values($permitidas));
        return $opts;
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

        if ($storedSignCount > 0 && $parsed['signCount'] > 0 && $parsed['signCount'] <= $storedSignCount) {
            throw new Exception('Credencial possivelmente clonada — cadastre a biometria novamente.');
        }

        $pem = $this->derToPem(base64_decode($publicKeyB64));
        $ok  = openssl_verify($authData . hash('sha256', $clientDataJSON, true), $signature, $pem, OPENSSL_ALGO_SHA256);
        if ($ok !== 1) throw new Exception('Assinatura inválida.');

        return ['sign_count' => $parsed['signCount']];
    }


    private function parseAuthData(string $authData): array
    {
        if (strlen($authData) < 37) throw new Exception('Dados do aparelho incompletos.');

        $offset    = 0;
        $rpIdHash  = substr($authData, $offset, 32); $offset += 32;
        $flags     = ord($authData[$offset]);        $offset += 1;
        $signCount = unpack('N', substr($authData, $offset, 4))[1]; $offset += 4;

        $result = ['rpIdHash' => $rpIdHash, 'flags' => $flags, 'signCount' => $signCount];

        if (($flags & 0x40) && strlen($authData) > $offset) {
            $offset += 16;
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
            $rsaSeq = $this->derInteger($cose[-1] ?? '') . $this->derInteger($cose[-2] ?? '');
            $rsaKey = "\x30" . $this->derLength(strlen($rsaSeq)) . $rsaSeq;
            $oid    = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
            $bs     = "\x03" . $this->derLength(strlen($rsaKey) + 1) . "\x00" . $rsaKey;
            $seq    = $oid . $bs;
            return "\x30" . $this->derLength(strlen($seq)) . $seq;
        }

        throw new Exception('Tipo de chave não suportado: kty=' . var_export($kty, true));
    }

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

    private function base64url_decode_str(string $data): string
    {
        $pad = strlen($data) % 4;
        if ($pad) $data .= str_repeat('=', 4 - $pad);
        return strtr($data, '-_', '+/');
    }
}
