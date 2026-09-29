#!/usr/bin/env python3
"""Testes das correções (admin da plataforma, convites, recuperação de senha, sessão revalidada,
suporte somente leitura, IP real). Use banco de TESTE, SMTP de teste em 127.0.0.1:2525 gravando
em /tmp/emails/ e servidor com SETUP_TOKEN=codigo-de-instalacao-teste-123456 e TRUST_PROXY=true.
Uso: python3 tests/correcoes_http.py http://127.0.0.1:3000"""
import glob, email, json, re, secrets, subprocess, sys, time, urllib.error, urllib.request, http.cookiejar
B = (sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:3000').rstrip('/')
MYSQL = ['mysql', 'lexcloud', '-N', '-e']
TOKEN = 'codigo-de-instalacao-teste-123456'
ok = 0; falhas = []
def check(nome, cond, extra=''):
    global ok
    if cond: ok += 1; print('  OK   ', nome)
    else: falhas.append(nome); print('  FALHA', nome, extra)
def sql(q): return subprocess.run(MYSQL + [q], capture_output=True, text=True).stdout.strip()
class C:
    def __init__(s, ip='200.1.1.1'): s.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar())); s.csrf = ''; s.ip = ip; s.r('GET', '/api/auth/session')
    def r(s, m, p, d=None):
        h = {'Content-Type': 'application/json', 'X-Forwarded-For': s.ip}
        if s.csrf: h['X-CSRF-Token'] = s.csrf
        req = urllib.request.Request(B + p, data=json.dumps(d).encode() if d is not None else None, headers=h, method=m)
        try: resp = s.op.open(req); code = resp.status; body = resp.read()
        except urllib.error.HTTPError as e: code = e.code; body = e.read()
        try: j = json.loads(body)
        except Exception: j = {}
        if isinstance(j, dict) and j.get('csrf'): s.csrf = j['csrf']
        return code, j
    def entrar(s, em, pw): return s.r('POST', '/api/auth/login', {'email': em, 'password': pw})
def ultimo_email():
    a = sorted(glob.glob('/tmp/emails/*.eml'))
    if not a: return ''
    m = email.message_from_bytes(open(a[-1], 'rb').read())
    return next((p.get_payload(decode=True).decode() for p in m.walk() if p.get_content_type() == 'text/plain'), '')
def link_do_email(): return (re.search(r'https?://\S+/#/redefinir/([a-f0-9]{64})', ultimo_email()) or [None, ''])[1]

S = secrets.token_hex(3)
print('== Instalação do admin da plataforma (sem terminal)')
code, j = C().r('GET', '/api/auth/session')
check('sessão informa: instalação disponível e autocadastro fechado', j.get('setup') is True and j.get('signup') is False, j)
x = C(); code, _ = x.r('POST', '/api/platform/setup', {'setup_token': 'errado', 'name': 'X', 'email': 'x@x.com', 'password': 'x' * 14})
check('código errado recusado', code == 403, code)
code, _ = x.r('POST', '/api/platform/setup', {'setup_token': TOKEN, 'name': 'Cleiton', 'email': f'cleiton{S}@chs.com', 'password': 'Senha-Plataforma-26'})
check('admin da plataforma criado', code == 201, code)
code, _ = x.r('POST', '/api/platform/setup', {'setup_token': TOKEN, 'name': 'Invasor', 'email': f'inv{S}@x.com', 'password': 'Senha-Invasor-2026'})
check('instalação desativada depois (não cria um segundo)', code == 404, code)
code, j = C().r('POST', '/api/auth/register', {'name': 'A', 'office_name': 'B', 'email': f'auto{S}@x.com', 'password': 'Senha-Auto-2026!'})
check('autocadastro público de escritório desligado', code == 404, code)

print('== Escritórios cadastrados pelo admin, com convite por e-mail')
pa = C(); code, _ = pa.entrar(f'cleiton{S}@chs.com', 'Senha-Plataforma-26')
check('admin entra', code == 200, code)
antes = len(glob.glob('/tmp/emails/*.eml'))
code, j = pa.r('POST', '/api/platform/tenants', {'office_name': 'Costa & Martins', 'owner_name': 'Dra. Ana', 'owner_email': f'ana{S}@costa.adv.br', 'plan': 'profissional'})
tid = j.get('id'); time.sleep(0.4)
check('escritório criado e convite enviado por e-mail', code == 201 and len(glob.glob('/tmp/emails/*.eml')) == antes + 1, (code, j))
tok = link_do_email()
check('convite traz o link para criar a senha', len(tok) == 64 and j.get('invite_link', '').endswith(tok))
check('link aparece para o admin (enviar por outro canal)', 'invite_link' in j)
code, j = C().r('POST', '/api/auth/reset', {'token': tok, 'new_password': 'curta'})
check('senha curta recusada', code == 400, code)
code, j = C().r('POST', '/api/auth/reset', {'token': tok, 'new_password': 'Senha-Ana-Costa-26'})
check('responsável cria a própria senha pelo link', code == 200, (code, j))
code, _ = C().r('POST', '/api/auth/reset', {'token': tok, 'new_password': 'Outra-Senha-2026!'})
check('link não serve duas vezes', code == 400, code)
dono = C(); code, j = dono.entrar(f'ana{S}@costa.adv.br', 'Senha-Ana-Costa-26')
check('responsável entra direto (sem troca obrigatória)', code == 200 and j['user']['role'] == 'owner' and j['user']['must_change_password'] == 0, j)
code, j = pa.r('GET', '/api/platform/tenants')
check('painel da plataforma lista o escritório', code == 200 and any(t['id'] == tid for t in j['tenants']))

print('== Conta desativada / escritório suspenso perdem o acesso NA HORA')
code, j = dono.r('POST', '/api/team', {'name': 'Dr. Paulo', 'email': f'paulo{S}@costa.adv.br', 'role': 'lawyer'})
fid, tmp = j['id'], j['temporary_password']
f = C('200.2.2.2'); f.entrar(f'paulo{S}@costa.adv.br', tmp); f.r('POST', '/api/auth/change-password', {'current_password': tmp, 'new_password': 'Senha-Paulo-2026!'})
check('advogado acessa os clientes', f.r('GET', '/api/clients')[0] == 200)
dono.r('PUT', f'/api/team/{fid}', {'active': False})
check('advogado DESATIVADO perde o acesso imediatamente', f.r('GET', '/api/clients')[0] == 401)
dono.r('PUT', f'/api/team/{fid}', {'active': True})
f2 = C('200.2.2.3'); f2.entrar(f'paulo{S}@costa.adv.br', 'Senha-Paulo-2026!')
code, j = pa.r('POST', f'/api/platform/tenants/{tid}/status')
check('admin suspende o escritório', code == 200 and j['status'] == 'suspended', j)
check('escritório suspenso: responsável perde o acesso na hora', dono.r('GET', '/api/clients')[0] == 401)
check('escritório suspenso: ninguém consegue entrar', C().entrar(f'paulo{S}@costa.adv.br', 'Senha-Paulo-2026!')[0] == 401)
pa.r('POST', f'/api/platform/tenants/{tid}/status')
dono = C(); dono.entrar(f'ana{S}@costa.adv.br', 'Senha-Ana-Costa-26')
check('reativado: volta a entrar', dono.r('GET', '/api/clients')[0] == 200)

print('== Troca de senha encerra as outras sessões')
o1 = C('200.3.3.1'); o1.entrar(f'ana{S}@costa.adv.br', 'Senha-Ana-Costa-26')
dono.r('POST', '/api/auth/change-password', {'current_password': 'Senha-Ana-Costa-26', 'new_password': 'Nova-Senha-Ana-26'})
check('o aparelho que trocou continua logado', dono.r('GET', '/api/clients')[0] == 200)
check('a sessão aberta em outro aparelho cai', o1.r('GET', '/api/clients')[0] == 401)

print('== Esqueci a senha')
antes = len(glob.glob('/tmp/emails/*.eml'))
code, j = C().r('POST', '/api/auth/forgot', {'email': f'ana{S}@costa.adv.br'}); time.sleep(0.4)
check('e-mail enviado com o link', code == 200 and len(glob.glob('/tmp/emails/*.eml')) == antes + 1 and len(link_do_email()) == 64)
code, j2 = C().r('POST', '/api/auth/forgot', {'email': f'ninguem{S}@x.com'}); time.sleep(0.3)
check('e-mail inexistente: mesma resposta, nenhum e-mail', j2.get('message') == j.get('message') and len(glob.glob('/tmp/emails/*.eml')) == antes + 1)
for i in range(4): C().r('POST', '/api/auth/forgot', {'email': f'ana{S}@costa.adv.br'})
uid = sql(f"SELECT id FROM users WHERE email='ana{S}@costa.adv.br'")
check('no máximo 3 links a cada 15 minutos', int(sql(f'SELECT COUNT(*) FROM password_resets WHERE user_id={uid} AND created_at >= UTC_TIMESTAMP() - INTERVAL 15 MINUTE')) <= 3)

print('== Modo suporte (somente leitura)')
dono.r('POST', '/api/clients', {'name': 'Cliente Sigiloso', 'email': 'c@x.com'})
code, j = pa.r('POST', f'/api/platform/tenants/{tid}/support')
check('admin entra em suporte no escritório', code == 200 and j['user'].get('support') is True, j)
code, j = pa.r('GET', '/api/clients')
check('em suporte, VÊ os clientes do escritório', code == 200 and any(c['name'] == 'Cliente Sigiloso' for c in j.get('items', [])), code)
code, j = pa.r('POST', '/api/clients', {'name': 'Criado no suporte'})
check('em suporte, NÃO altera nada', code == 403 and 'somente leitura' in j.get('error', ''), (code, j))
check('acesso de suporte registrado na auditoria', sql(f"SELECT COUNT(*) FROM audit_logs WHERE tenant_id={tid} AND action='platform:support_started'") == '1')
code, j = pa.r('POST', '/api/platform/support/exit')
check('sair do suporte volta ao painel da plataforma', code == 200 and not j['user'].get('support') and pa.r('GET', '/api/clients')[0] == 403)
code, _ = dono.r('POST', f'/api/platform/tenants/{tid}/support')
check('responsável do escritório NÃO usa as rotas da plataforma', code == 403, code)

print('== IP real atrás do proxy')
v = C('177.10.20.30'); v.entrar(f'ana{S}@costa.adv.br', 'Nova-Senha-Ana-26'); v.r('POST', '/api/clients', {'name': 'Cliente IP'})
check('auditoria grava o IP real do visitante', sql(f"SELECT ip_address FROM audit_logs WHERE tenant_id={tid} ORDER BY id DESC LIMIT 1") == '177.10.20.30')
for i in range(6): C('10.9.9.9').entrar(f'ana{S}@costa.adv.br', 'senha-errada-123')
check('quem erra 6 vezes fica bloqueado', C('10.9.9.9').entrar(f'ana{S}@costa.adv.br', 'Nova-Senha-Ana-26')[0] == 429)
check('a dona da conta, em outro IP, continua entrando', C('177.10.20.31').entrar(f'ana{S}@costa.adv.br', 'Nova-Senha-Ana-26')[0] == 200)
check('sessões gravadas no banco', int(sql('SELECT COUNT(*) FROM sessions') or 0) > 0)
print(f'\n{ok} OK, {len(falhas)} falhas'); [print(' -', f) for f in falhas]
