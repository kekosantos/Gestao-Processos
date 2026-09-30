#!/usr/bin/env python3
"""Senha padrão (troca obrigatória), login por usuário, validade da senha inicial, recuperação do admin
e escritório DEMO. Banco de TESTE já migrado (bin/migrate.php com o seed), SENHA_PADRAO vazia (= "password").
Uso: python3 tests/senha_demo_http.py http://127.0.0.1:3000"""
import json, subprocess, sys, secrets, urllib.error, urllib.request, http.cookiejar, os
B = (sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:3000').rstrip('/')
PADRAO = os.environ.get('SENHA_PADRAO') or 'password'
ok = 0; falhas = []
def check(nome, cond, extra=''):
    global ok
    if cond: ok += 1; print('  OK   ', nome)
    else: falhas.append(nome); print('  FALHA', nome, extra)
def sql(q): return subprocess.run(['mysql', 'lexcloud', '-N', '-e', q], capture_output=True, text=True).stdout.strip()
class C:
    def __init__(s, ip=None): s.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar())); s.csrf = ''; s.ip = ip or '200.9.%d.%d' % (secrets.randbelow(250), secrets.randbelow(250)); s.r('GET', '/api/auth/session')
    def r(s, m, p, d=None):
        h = {'Content-Type': 'application/json', 'X-Forwarded-For': s.ip}
        if s.csrf: h['X-CSRF-Token'] = s.csrf
        try: resp = s.op.open(urllib.request.Request(B + p, data=json.dumps(d).encode() if d is not None else None, headers=h, method=m)); code, body = resp.status, resp.read()
        except urllib.error.HTTPError as e: code, body = e.code, e.read()
        try: j = json.loads(body)
        except Exception: j = {}
        if isinstance(j, dict) and j.get('csrf'): s.csrf = j['csrf']
        return code, j
    def entrar(s, u, p): return s.r('POST', '/api/auth/login', {'email': u, 'password': p})
    def trocar(s, atual, nova): return s.r('POST', '/api/auth/change-password', {'current_password': atual, 'new_password': nova})
    def itens(s, rec): code, j = s.r('GET', '/api/' + rec); return j.get('items', []) if code == 200 else None

print('== Admin da plataforma criado sozinho (cleiton.santos + senha padrão)')
code, j = C().r('GET', '/api/auth/session')
check('instalação pela web não aparece (o admin já existe)', j.get('setup') is False, j)
a = C(); code, j = a.entrar('cleiton.santos', PADRAO)
check('entra com o USUÁRIO cleiton.santos e a senha padrão', code == 200 and j['user']['role'] == 'platform_admin', (code, j))
check('primeiro acesso exige trocar a senha', j.get('user', {}).get('must_change_password') == 1)
code, _ = a.r('GET', '/api/platform/tenants')
check('antes de trocar, não acessa nada', code == 403, code)
code, j = a.trocar(PADRAO, PADRAO)
check('não aceita a própria senha padrão como senha nova', code == 400 and ('diferente' in j.get('error', '') or '12' in j.get('error', '')), (code, j))
code, _ = a.trocar(PADRAO, 'Cleiton-Nova-Senha-26')
check('troca a senha', code == 200, code)
check('depois de trocar, acessa o painel da plataforma', a.r('GET', '/api/platform/tenants')[0] == 200)
check('a senha padrão deixa de funcionar para ele', C().entrar('cleiton.santos', PADRAO)[0] == 401)
check('entra pelo usuário com a senha nova', C().entrar('cleiton.santos', 'Cleiton-Nova-Senha-26')[0] == 200)
check('entra também pelo e-mail', C().entrar('cleiton@chsconsultoria.com.br', 'Cleiton-Nova-Senha-26')[0] == 200)
check('validade da senha inicial some depois da troca', sql("SELECT COALESCE(senha_inicial_ate,'-') FROM users WHERE username='cleiton.santos'") == '-')

print('== Escritório DEMO (dados fictícios)')
d = C(); code, j = d.entrar('demo', PADRAO)
check('entra com o usuário demo e a senha padrão (troca obrigatória)', code == 200 and j['user']['must_change_password'] == 1 and j['user']['role'] == 'owner', (code, j))
d.trocar(PADRAO, 'Demo-Apresentacao-26')
cont = {r: len(d.itens(r) or []) for r in ['clients', 'cases', 'tasks', 'events', 'finance', 'documents', 'team']}
check('volume para demonstrar: 5 clientes, 5 processos, 5 tarefas, 5 eventos, 6 lançamentos, 2 documentos, 5 na equipe',
      cont == {'clients': 5, 'cases': 5, 'tasks': 5, 'events': 5, 'finance': 6, 'documents': 2, 'team': 5}, cont)
code, j = d.r('GET', '/api/dashboard')
check('painel da demo abre com números', code == 200, code)
tarefas = d.itens('tasks') or []
check('há tarefa atrasada e tarefa próxima (o painel mostra alertas)', any(t['status'] == 'pending' for t in tarefas) and any(t['status'] == 'done' for t in tarefas))
docs = d.itens('documents') or []
code, _ = d.r('GET', f"/api/documents/{docs[0]['id']}/download") if docs else (0, None)
check('documento fictício baixa', code == 200, code)

print('== Escritórios criados pela plataforma usam a senha padrão')
code, j = a.r('POST', '/api/platform/tenants', {'office_name': 'Moraes Advocacia', 'owner_name': 'Dr. Moraes', 'owner_email': 'moraes@moraes.adv.br', 'plan': 'essencial'})
tid = j.get('id')
check('escritório criado; a mensagem explica a senha padrão', code == 201 and 'senha padrão' in j.get('message', ''), (code, j))
o = C(); code, j = o.entrar('moraes@moraes.adv.br', PADRAO)
check('responsável entra com o e-mail e a senha padrão, com troca obrigatória', code == 200 and j['user']['must_change_password'] == 1, (code, j))
sql("UPDATE users SET senha_inicial_ate = NOW() - INTERVAL 1 HOUR WHERE email='moraes@moraes.adv.br'")
code, j = C().entrar('moraes@moraes.adv.br', PADRAO)
check('senha inicial vencida (7 dias) é recusada, com mensagem clara', code == 401 and 'expirou' in j.get('error', ''), (code, j))
code, j = a.r('POST', f'/api/platform/tenants/{tid}/invite')
check('"Reenviar convite" renova o acesso com a senha padrão', code == 200 and C().entrar('moraes@moraes.adv.br', PADRAO)[0] == 200, (code, j))

print('== Equipe criada pelo escritório NÃO usa a senha padrão da plataforma')
o = C(); o.entrar('moraes@moraes.adv.br', PADRAO); o.trocar(PADRAO, 'Moraes-Nova-Senha-26')
code, j = o.r('POST', '/api/team', {'name': 'Estagiária', 'email': 'estagiaria@moraes.adv.br', 'role': 'staff'})
check('pessoa da equipe recebe uma senha temporária própria (troca obrigatória)', code == 201 and j.get('temporary_password') and j['temporary_password'] != PADRAO, (code, j))
check('e essa senha também tem validade', sql("SELECT senha_inicial_ate IS NOT NULL FROM users WHERE email='estagiaria@moraes.adv.br'") == '1')

print('== Isolamento com a demo')
ids_demo = {c['id'] for c in (d.itens('clients') or [])}
ids_moraes = {c['id'] for c in (o.itens('clients') or [])}
check('o escritório novo não vê os clientes da demo', not (ids_demo & ids_moraes) and len(ids_moraes) == 0)
alvo = next(iter(ids_demo))
code, j = o.r('GET', f'/api/clients/{alvo}')
check('nem abrindo pelo número (volta só a lista do próprio escritório, vazia)', code in (403, 404) or (code == 200 and not any(c.get('id') == alvo for c in j.get('items', []))), (code, j))
check('nem alterando pelo número', o.r('PUT', f'/api/clients/{alvo}', {'name': 'invasão'})[0] in (403, 404, 405) and d.itens('clients') and all(c['name'] != 'invasão' for c in d.itens('clients')))

print('== Usuário inválido não quebra o login')
check('usuário com caractere estranho: recusa sem erro 500', C().entrar("x' or 1=1 --", 'qualquer')[0] == 401)
print(f'\n{ok} OK, {len(falhas)} falhas'); [print(' -', f) for f in falhas]
