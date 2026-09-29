# Deploy — resumo (TiDB + Render para validação + Fly.io para produção)

1. **Banco (TiDB):** crie o banco `lexcloud`. As tabelas são criadas sozinhas pelo sistema: no Render ao iniciar, no Fly pelo `release_command`. Se preferir, rode `database/schema.sql` no SQL Editor.
2. **Render** (validação): o `render.yaml` já está no plano gratuito. Em Environment, cadastre:
   - `DATABASE_URL=mysqls://USUARIO:SENHA@HOST:4000/lexcloud`. O `mysqls` ativa o TLS que o TiDB exige, e a senha vai com caracteres especiais codificados.
   - `APP_URL=https://<serviço>.onrender.com`, sem barra no fim. É usado nos links dos e-mails.
   - `SETUP_TOKEN` (24 ou mais caracteres) e os `MAIL_*` (mesmo Gmail dos outros sistemas).
   - `TRUST_PROXY` e `RUN_MIGRATIONS` já estão no `render.yaml`.
3. **Primeiro acesso:** abra `https://<serviço>/#/instalar`, crie o admin da plataforma e **apague o `SETUP_TOKEN`**.
4. **Escritórios:** no painel, use **"+ Novo escritório"**. O responsável recebe o convite por e-mail.
5. **Fly** (produção): use o `fly.toml` e cadastre os secrets de `deploy/fly-secrets.example`.

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
