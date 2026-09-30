# Trocar para os nomes padrão (quem já está no ar com MYSQL_*)
No Render, em Environment, crie as variáveis novas com os **mesmos valores** das antigas, salve, confira que o sistema entrou, e só então apague as antigas:

| Antiga | Nova |
|---|---|
| `MYSQL_HOST` | `DB_HOST` |
| `MYSQL_PORT` | `DB_PORT` (4000) |
| `MYSQL_DATABASE` | `DB_NAME` |
| `MYSQL_USER` | `DB_USER` |
| `MYSQL_PASSWORD` | `DB_PASS` |
| `MYSQL_SSL_CA` | `DB_SSL=true` |

# Primeiro acesso (v2.1)

- **Seu acesso é criado sozinho** quando o sistema inicia: usuário **`cleiton.santos`**, senha **`password`** (ou o valor da variável `SENHA_PADRAO`). O sistema **pede a troca** no primeiro acesso, e a senha inicial vale 7 dias.
- **Já existia um admin, e você não lembra a senha?** Cadastre `ADMIN_REDEFINIR=true` no Render, espere publicar, entre com `cleiton.santos` e a senha padrão, e **apague a variável**. A conta é a mesma; só o usuário e a senha voltam ao padrão.
- **Escritório de demonstração:** usuário **`demo`** com a senha padrão (troca no primeiro acesso). Traz dados fictícios: 5 clientes, 5 processos, 5 tarefas, 5 compromissos, 6 lançamentos, 2 documentos e 5 pessoas na equipe. Com `DEMO_DATA=false`, ele não é criado.
- **Escritórios novos que você cria no painel:** o responsável entra com o **e-mail** e a **senha padrão** (troca no primeiro acesso, validade de 7 dias). O convite por e-mail continua indo, como alternativa. "Reenviar convite" renova esse acesso.
- **Equipe criada pelo próprio escritório:** recebe uma senha temporária **própria**, mostrada na tela, e **não** a senha padrão da plataforma. Assim, os responsáveis de um escritório não ficam sabendo a senha inicial dos outros escritórios.

> **Segurança:** `password` é a senha mais testada por robôs na internet. Enquanto uma conta nova não troca a senha, ela fica exposta. Para diminuir o risco: a troca é obrigatória, a senha vale só 7 dias, e o login bloqueia depois de 6 erros. **Recomendado:** cadastrar `SENHA_PADRAO` no Render com um valor só seu. A troca continua obrigatória do mesmo jeito.

# Deploy — resumo (TiDB + Render para validação + Fly.io para produção)

1. **Banco (TiDB):** crie o banco `lexcloud`. As tabelas são criadas sozinhas pelo sistema: no Render ao iniciar, no Fly pelo `release_command`. Se preferir, rode `database/schema.sql` no SQL Editor.
2. **Render** (validação): o `render.yaml` já está no plano gratuito. Em Environment, cadastre:
   - Banco, **com os mesmos nomes dos outros sistemas CHS:** `DB_HOST`, `DB_PORT=4000`, `DB_NAME=lexcloud`, `DB_USER` (usuário completo, com prefixo), `DB_PASS` e `DB_SSL=true`. Os nomes antigos (`DATABASE_URL` e `MYSQL_*`) continuam funcionando.
   - `APP_URL=https://<serviço>.onrender.com`, sem barra no fim. É usado nos links dos e-mails.
   - `SETUP_TOKEN` (24 ou mais caracteres) e os `MAIL_*` (mesmo Gmail dos outros sistemas).
   - `TRUST_PROXY` e `RUN_MIGRATIONS` já estão no `render.yaml`.
3. **Primeiro acesso:** abra `https://<serviço>/#/instalar`, crie o admin da plataforma e **apague o `SETUP_TOKEN`**.
4. **Escritórios:** no painel, use **"+ Novo escritório"**. O responsável recebe o convite por e-mail.
5. **Fly** (produção): use o `fly.toml` e cadastre os secrets de `deploy/fly-secrets.example`.

> **Erro `[1105] insecure transport`?** O TiDB exige conexão criptografada. A partir da v2.2.1, o sistema liga o TLS sozinho quando o host é `*.tidbcloud.com`; `DB_SSL=true` também liga. Em versões anteriores, cadastre `MYSQL_SSL_CA=/etc/ssl/certs/ca-certificates.crt`.

> **Erro de banco no Render?** O log agora mostra o motivo real na linha `LexCloud database connection failed [código]: ...`.
> `[1049]` o banco `lexcloud` não existe (rode o `CREATE DATABASE`); `[1045]` usuário ou senha recusados (o usuário do TiDB é o completo, com prefixo, ex.: `23Pxxxx.root`);
> `[1044]` sem permissão no banco `lexcloud`; `[2002]`/`[2006]` host ou porta errados; `[2026]` erro de certificado (cadastre `MYSQL_SSL_VERIFY=false`).

> O plano gratuito do Render "adormece" sem uso. Use-o só para validar, não para dados jurídicos reais.

Detalhes: veja `CORRECOES.md` e os guias em `docs/`.

---

# LexCloud — subir em nuvem

Este pacote contém a aplicação PHP 8.3/MySQL, Dockerfile e configurações para **Render** e **Fly.io**. Extraia o ZIP e siga o guia do provedor escolhido:

- [Deploy Render](docs/deploy-render.md) — Blueprint `render.yaml`; serviço pago com pre-deploy automático da migração.
- [Deploy Fly.io](docs/deploy-flyio.md) — `fly.toml`, segredo do MySQL por `fly secrets`, migração no `release_command`.
- [Pesquisa de mercado](research/market-landscape.md) — comparação do Projuris ADV e outras soluções.
- [Instalação local](docs/installation.md) e [segurança/limites do MVP](docs/security-and-scope.md).

## Antes de subir

1. Tenha um **MySQL compatível gerenciado externamente**, com TLS e backups; configure a URL apenas como segredo do provedor. O container não hospeda o servidor MySQL. A conexão usada pelo app é `DATABASE_URL`; não é necessário definir uma chave `APP_KEY`.
2. Revise `render.yaml` ou `fly.toml` e escolha região próxima ao banco/escritório.
3. Não envie `.env`, `deploy/fly-secrets.local`, credenciais ou documentos reais ao Git.
4. Os dois alvos executam `php bin/migrate.php` antes da versão receber tráfego. A migração é aditiva, mas faça backup e confira a política do provedor antes de conectar um banco com dados existentes.
5. Confirme `/api/health` com HTTP 200 e instale o PWA somente pela URL HTTPS.

## Responsividade e PWA

A interface adapta navegação lateral, barra móvel, cartões e tabelas para telas pequenas. No Chrome/Android, use **Instalar app** ou **Adicionar à tela inicial**; no iOS/iPadOS, Safari → **Compartilhar → Adicionar à Tela de Início**. O service worker mantém somente o shell público versionado e mostra uma tela offline: **não armazena nem disponibiliza dados jurídicos offline**.

## Limite de escala

Esta versão mantém sessões PHP em arquivos locais. As configurações assumem uma instância/máquina; reiniciar o serviço pode exigir novo login. Antes de escalar horizontalmente, implementar e testar armazenamento compartilhado de sessão. Não coloque dados de clientes em Preview/demonstração.
