#!/usr/bin/env python3
"""Biometria (WebAuthn) de ponta a ponta com autenticador SIMULADO: celular/Mac (ES256) e Windows Hello (RS256).
Banco de TESTE já migrado (com o seed). Uso: python3 tests/biometria_http.py http://127.0.0.1:3000"""
import base64, hashlib, json, os, secrets, struct, subprocess, sys, urllib.error, urllib.request, http.cookiejar
B = (sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:3000').rstrip('/')
PADRAO = os.environ.get('SENHA_PADRAO') or 'password'
ok = 0; falhas = []
def check(nome, cond, extra=''):
    global ok
    if cond: ok += 1; print('  OK   ', nome)
    else: falhas.append(nome); print('  FALHA', nome, extra)
def sql(q): return subprocess.run(['mysql', 'lexcloud', '-N', '-e', q], capture_output=True, text=True).stdout.strip()
class C:
    def __init__(s): s.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar())); s.csrf = ''; s.ip = '200.8.%d.%d' % (secrets.randbelow(250), secrets.randbelow(250)); s.r('GET', '/api/auth/session')
    def r(s, m, p, d=None, csrf=True):
        h = {'Content-Type': 'application/json', 'X-Forwarded-For': s.ip}
        if s.csrf and csrf: h['X-CSRF-Token'] = s.csrf
        try: resp = s.op.open(urllib.request.Request(B + p, data=json.dumps(d).encode() if d is not None else None, headers=h, method=m)); code, body = resp.status, resp.read()
        except urllib.error.HTTPError as e: code, body = e.code, e.read()
        try: j = json.loads(body)
        except Exception: j = {}
        if isinstance(j, dict) and j.get('csrf'): s.csrf = j['csrf']
        return code, j
    def entrar(s, u, p): return s.r('POST', '/api/auth/login', {'email': u, 'password': p})

# ── Autenticador simulado (o mesmo do Gestão Financeira) ──
def b64u(b): return base64.urlsafe_b64encode(b).rstrip(b'=').decode()
def cbor(v):
    def hdr(mt, n):
        if n < 24: return bytes([mt << 5 | n])
        if n < 256: return bytes([mt << 5 | 24, n])
        if n < 65536: return bytes([mt << 5 | 25]) + struct.pack('>H', n)
        return bytes([mt << 5 | 26]) + struct.pack('>I', n)
    if isinstance(v, int): return hdr(0, v) if v >= 0 else hdr(1, -1 - v)
    if isinstance(v, bytes): return hdr(2, len(v)) + v
    if isinstance(v, str): b = v.encode(); return hdr(3, len(b)) + b
    if isinstance(v, dict): return hdr(5, len(v)) + b''.join(cbor(k) + cbor(x) for k, x in v.items())
    raise TypeError(v)
class Aparelho:
    def __init__(s, tipo='ES256'):
        from cryptography.hazmat.primitives.asymmetric import ec, rsa
        s.tipo = tipo; s.cred_id = os.urandom(16); s.count = 0
        s.key = ec.generate_private_key(ec.SECP256R1()) if tipo == 'ES256' else rsa.generate_private_key(65537, 2048)
    def cose(s):
        p = s.key.public_key().public_numbers()
        if s.tipo == 'ES256': return cbor({1: 2, 3: -7, -1: 1, -2: p.x.to_bytes(32, 'big'), -3: p.y.to_bytes(32, 'big')})
        return cbor({1: 3, 3: -257, -1: p.n.to_bytes((p.n.bit_length() + 7) // 8, 'big'), -2: p.e.to_bytes(3, 'big')})
    def criar(s, o, origem=None):
        cd = json.dumps({'type': 'webauthn.create', 'challenge': o['challenge'], 'origin': origem or B}).encode()
        auth = hashlib.sha256(o['rp']['id'].encode()).digest() + bytes([0x45]) + struct.pack('>I', 0) + b'\0' * 16 + struct.pack('>H', len(s.cred_id)) + s.cred_id + s.cose()
        return {'id': b64u(s.cred_id), 'rawId': b64u(s.cred_id), 'type': 'public-key', 'response': {'clientDataJSON': b64u(cd), 'attestationObject': b64u(cbor({'fmt': 'none', 'attStmt': {}, 'authData': auth}))}}
    def assinar(s, o, origem=None, count=None):
        from cryptography.hazmat.primitives.asymmetric import ec, padding
        from cryptography.hazmat.primitives import hashes
        s.count = s.count + 1 if count is None else count
        cd = json.dumps({'type': 'webauthn.get', 'challenge': o['challenge'], 'origin': origem or B}).encode()
        auth = hashlib.sha256(o['rpId'].encode()).digest() + bytes([0x05]) + struct.pack('>I', s.count)
        dados = auth + hashlib.sha256(cd).digest()
        sig = s.key.sign(dados, ec.ECDSA(hashes.SHA256())) if s.tipo == 'ES256' else s.key.sign(dados, padding.PKCS1v15(), hashes.SHA256())
        return {'id': b64u(s.cred_id), 'rawId': b64u(s.cred_id), 'type': 'public-key', 'response': {'clientDataJSON': b64u(cd), 'authenticatorData': b64u(auth), 'signature': b64u(sig)}}

def preparar(usuario, nova):
    c = C(); c.entrar(usuario, PADRAO); c.r('POST', '/api/auth/change-password', {'current_password': PADRAO, 'new_password': nova}); return c
admin = preparar('cleiton.santos', 'Cleiton-Nova-Senha-26')
demo = preparar('demo', 'Demo-Apresentacao-26')

for tipo in ['ES256', 'RS256']:
    print(f'== {tipo} ({"celular/Mac" if tipo == "ES256" else "Windows Hello"})')
    ap = Aparelho(tipo)
    _, o = demo.r('GET', '/api/auth/webauthn/register-options')
    check('opções de cadastro exigem biometria do próprio aparelho', o.get('authenticatorSelection', {}).get('userVerification') == 'required', o.get('authenticatorSelection'))
    code, r = demo.r('POST', '/api/auth/webauthn/register-verify', {'credential': ap.criar(o), 'device_name': 'Celular da Helena'}, csrf=False)
    check('cadastro sem CSRF é recusado', code in (403, 419), code)
    _, o = demo.r('GET', '/api/auth/webauthn/register-options')
    code, r = demo.r('POST', '/api/auth/webauthn/register-verify', {'credential': ap.criar(o), 'device_name': 'Celular da Helena'})
    check('cadastra a biometria', code == 201 and r.get('ok'), (code, r))
    n = C(); _, lo = n.r('GET', '/api/auth/webauthn/login-options')
    check('opções de login NÃO listam credenciais de ninguém', 'allowCredentials' not in lo and lo.get('userVerification') == 'required', lo)
    code, r = n.r('POST', '/api/auth/webauthn/login-verify', {'credential': ap.assinar(lo)})
    check('entra por biometria', code == 200 and r.get('user', {}).get('tenant_slug') == 'demo', (code, r))
    check('sessão aberta (acessa os clientes)', n.r('GET', '/api/clients')[0] == 200)
    print('   ataques:')
    x = C(); _, lo = x.r('GET', '/api/auth/webauthn/login-options')
    falso = ap.assinar(lo); falso['response']['signature'] = b64u(b'assinatura-falsa' * 4)
    code, r = x.r('POST', '/api/auth/webauthn/login-verify', {'credential': falso})
    check('ID certo + assinatura falsa → recusado', code == 401 and x.r('GET', '/api/clients')[0] == 401, (code, r))
    x = C(); _, lo = x.r('GET', '/api/auth/webauthn/login-options')
    outro = Aparelho(tipo); outro.cred_id = ap.cred_id
    check('aparelho clonado com outra chave → recusado', x.r('POST', '/api/auth/webauthn/login-verify', {'credential': outro.assinar(lo)})[0] == 401)
    x = C(); _, lo = x.r('GET', '/api/auth/webauthn/login-options'); resp = ap.assinar(lo); x.r('POST', '/api/auth/webauthn/login-verify', {'credential': resp})
    y = C(); check('mesma resposta usada de novo (replay) → recusada', y.r('POST', '/api/auth/webauthn/login-verify', {'credential': resp})[0] == 401)
    x = C(); _, lo = x.r('GET', '/api/auth/webauthn/login-options')
    check('resposta de outro site (origem falsa) → recusada', x.r('POST', '/api/auth/webauthn/login-verify', {'credential': ap.assinar(lo, origem='https://site-falso.com')})[0] == 401)
    x = C(); _, lo = x.r('GET', '/api/auth/webauthn/login-options')
    check('contador que volta atrás (clonagem) → recusado', x.r('POST', '/api/auth/webauthn/login-verify', {'credential': ap.assinar(lo, count=1)})[0] == 401)

print('== Regras de conta')
ap = Aparelho('ES256')
_, o = admin.r('GET', '/api/auth/webauthn/register-options')
check('admin da plataforma também cadastra', admin.r('POST', '/api/auth/webauthn/register-verify', {'credential': ap.criar(o), 'device_name': 'Notebook'})[0] == 201)
n = C(); _, lo = n.r('GET', '/api/auth/webauthn/login-options'); code, r = n.r('POST', '/api/auth/webauthn/login-verify', {'credential': ap.assinar(lo)})
check('admin entra por biometria direto no painel da plataforma', code == 200 and r['user']['role'] == 'platform_admin', (code, r))
tid = sql("SELECT id FROM tenants WHERE slug='demo'")
admin.r('POST', f'/api/platform/tenants/{tid}/support')
code, j = admin.r('GET', '/api/auth/webauthn/register-options')
check('dentro de um escritório (acesso CHS) não cadastra biometria', code == 403, code)
admin.r('POST', '/api/platform/support/exit')
_, lst = demo.r('GET', '/api/auth/webauthn/credentials')
check('lista os aparelhos da própria conta (2, com nome)', len(lst.get('items', [])) == 2 and lst['items'][0]['device_name'] == 'Celular da Helena', lst)
wid = lst['items'][0]['id']
check('não remove aparelho de outra pessoa', admin.r('DELETE', f'/api/auth/webauthn/credentials/{wid}')[0] == 404)
ap2 = Aparelho('ES256'); _, o = demo.r('GET', '/api/auth/webauthn/register-options'); demo.r('POST', '/api/auth/webauthn/register-verify', {'credential': ap2.criar(o)})
sql("UPDATE users SET active=0 WHERE username='demo'")
n = C(); _, lo = n.r('GET', '/api/auth/webauthn/login-options')
check('conta DESATIVADA não entra por biometria', n.r('POST', '/api/auth/webauthn/login-verify', {'credential': ap2.assinar(lo)})[0] == 401)
sql("UPDATE users SET active=1 WHERE username='demo'"); sql(f"UPDATE tenants SET status='suspended' WHERE id={tid}")
n = C(); _, lo = n.r('GET', '/api/auth/webauthn/login-options')
check('escritório SUSPENSO não entra por biometria', n.r('POST', '/api/auth/webauthn/login-verify', {'credential': ap2.assinar(lo)})[0] == 401)
sql(f"UPDATE tenants SET status='active' WHERE id={tid}")
demo2 = C(); demo2.entrar('demo', 'Demo-Apresentacao-26')
n = C(); _, lo = n.r('GET', '/api/auth/webauthn/login-options'); n.r('POST', '/api/auth/webauthn/login-verify', {'credential': ap2.assinar(lo)})
demo2.r('POST', '/api/auth/change-password', {'current_password': 'Demo-Apresentacao-26', 'new_password': 'Demo-Outra-Senha-26'})
check('trocar a senha derruba também a sessão aberta por biometria', n.r('GET', '/api/clients')[0] == 401)
code, _ = demo2.r('DELETE', f'/api/auth/webauthn/credentials/{wid}')
check('remove o próprio aparelho', code == 200)
n = C(); _, lo = n.r('GET', '/api/auth/webauthn/login-options')
check('aparelho removido não entra mais', n.r('POST', '/api/auth/webauthn/login-verify', {'credential': Aparelho('ES256').assinar(lo)})[0] == 401)
check('banco guarda só a chave PÚBLICA', 'PRIVATE' not in sql('SELECT GROUP_CONCAT(public_key) FROM webauthn_credentials'))
print(f'\n{ok} OK, {len(falhas)} falhas'); [print(' -', f) for f in falhas]
