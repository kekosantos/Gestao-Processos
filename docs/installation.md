# Instalação e implantação do LexCloud

> **Padrão CHS:** o banco usa `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` e `DB_SSL` (os mesmos nomes do Gestão de Notas e da Gestão Financeira). `DATABASE_URL` e `MYSQL_*` continuam aceitos, por compatibilidade.


## Requisitos

- PHP 8.3; extensões `pdo_mysql`, `mbstring` e `fileinfo`.
- MySQL 8 / TiDB compatível ou MariaDB compatível com InnoDB, `utf8mb4` e constraints de chave estrangeira.
- HTTPS para instalação PWA fora de `localhost`.
- Container de produção: `Dockerfile` na raiz e variável `PORT` disponibilizada pelo host.

## Configurar conexão

### Nuvem / MySQL gerenciado

Defina `DATABASE_URL` como variável protegida do processo. A aplicação aceita `mysql://` / `mysqls://`, interpreta parâmetro `ssl` e, quando TLS estrito é solicitado, valida o certificado com o bundle CA do sistema ou com o caminho configurado em `MYSQL_SSL_CA`. Exemplo TLS:

```text
mysqls://USUARIO:SENHA_URL_ENCODED@HOST:PORTA/lexcloud
```

Codifique caracteres especiais da senha no formato de URL. Nunca grave o DSN no repositório, bundle do browser, URL pública, logs ou chat. A URL `DATABASE_URL` tem precedência sobre `MYSQL_*` local. Não é necessário configurar `APP_KEY`: o app não lê essa variável.

### MySQL externo/local

Copie `.env.example` para `.env` e preencha apenas no ambiente de execução:

```dotenv
APP_ENV=development
APP_TIMEZONE=America/Sao_Paulo
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=lexcloud
DB_USER=lexcloud
DB_PASS=senha-local
DB_SSL=false
MYSQL_SSL_CA=
```

Para TLS com MySQL externo, configure `MYSQL_SSL_CA` com o caminho do arquivo CA confiável quando necessário. Use usuário de banco com apenas os privilégios necessários sobre o schema do LexCloud.

## Preparar o esquema

Com o processo da aplicação apontando ao banco pretendido, execute:

```bash
php bin/migrate.php
```

A migração usa `CREATE TABLE IF NOT EXISTS`, índices/chaves nomeados e registro de versão; não apaga nem redefine dados existentes. Localmente ela é explícita. No Render e no Fly.io, o `render.yaml` e o `fly.toml` a chamam no hook de release antes de iniciar a versão. Faça backup/exportação antes de qualquer alteração operacional; o projeto não contém serviço de backup gerenciado.

## Desenvolvimento local

```bash
php bin/migrate.php
APP_ENV=development php bin/seed-demo.php   # opcional; apenas dados fictícios
php -S 0.0.0.0:3000 -t public router.php
```

A seed só pode rodar quando `APP_ENV` é explicitamente `dev`, `development`, `local` ou `test` (sem distinção de maiúsculas); valores ausentes, `PROD` e `production` são recusados. Credenciais fictícias: `demo@lexcloud.app` / `Demo@123!`. Exclua tenant demo por uma rotina operacional revisada, nunca com uma migração de produção automática.

## Administração e usuários

Para provisionar a conta global de plataforma em um terminal com acesso ao banco:

```bash
php bin/create-platform-admin.php admin@dominio.com
```

Digite nome e senha exclusiva no prompt. Não passe senha como argumento ou em texto de script versionado. Contas criadas em Equipe recebem senha aleatória, exibida uma única vez, e precisam alterá-la no primeiro acesso. Não existe e-mail de recuperação configurado: o administrador do escritório deve provisionar novo acesso e proteger o canal de entrega.

## Container e comandos de deploy

A imagem PHP/Apache define `public/` como DocumentRoot, compila `pdo_mysql` e `mbstring`, valida `fileinfo`, habilita rewrite/headers e usa o valor numérico de `PORT` do host (padrão `8080`). `docker/entrypoint.sh` ajusta o listener antes de abrir o Apache e, quando recebe um comando explícito, executa-o sem iniciar Apache. Isso permite que os hooks de migração do Render/Fly executem `php bin/migrate.php` no mesmo container. `docker/lexcloud.ini` reserva 13 MB para arquivo e 16 MB para multipart; o controller aplica limite de 12 MiB. `GET /api/health` retorna 200 somente quando DB, extensões e limites de upload estão prontos.

## PWA, telas pequenas e modo offline

O site publica `/manifest.webmanifest`, ícones próprios, `/sw.js` e `/offline.html`. CSS responsivo adapta o menu, navegação móvel, cartões, tabelas e formulários. Em produção, abra a URL HTTPS; Chrome/Android: **Instalar app/Adicionar à tela inicial**; Safari/iOS: **Compartilhar → Adicionar à Tela de Início**. O service worker guarda apenas assets públicos versionados e a tela offline; não armazena sessão, HTML autenticado, API, processo, cliente ou documento. **Offline não é modo de consulta de processos**; reconecte antes de ler ou salvar.

## Publicar no Render ou Fly.io

- [Guia completo do Render](deploy-render.md): Blueprint `render.yaml`, segredo MySQL e serviço pago; o plano gratuito não suporta o pre-deploy configurado e não é indicado para dados jurídicos.
- [Guia completo do Fly.io](deploy-flyio.md): `fly.toml`, secrets cifrados e release command.
- [Resumo para começar](../DEPLOY.md).

A sessão PHP atual fica no filesystem local. As configurações recomendam/fixam uma instância ou uma máquina; restarts podem exigir login novamente. Não escale horizontalmente sem armazenamento compartilhado de sessão testado. Configure backups e restauração do MySQL, domínio, acesso e controles LGPD antes de inserir dados reais.
