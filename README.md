# LexCloud — Gestão Jurídica

MVP web multi-tenant para escritório de advocacia, em português, feito com **PHP 8.3 puro (sem framework)**, PDO/MySQL, HTML/CSS/JavaScript sem framework e PWA instalável.

## O que está incluído

- Cadastro de escritório/tenant, login, logout, CSRF, limite de tentativas e papéis por usuário.
- Isolamento de dados no servidor por `tenant_id`, reforçado por chaves estrangeiras compostas em MySQL.
- Painel, clientes/partes, processos, tarefas e prazos operacionais, agenda, documentos privados, financeiro básico, equipe, relatórios e configurações.
- Auditoria de mutações e arquivos com download autenticado; uploads de até 12 MiB guardados como BLOB no banco.
- PWA com ícones próprios, instalação em HTTPS, layout responsivo e tela offline segura. O service worker **não** armazena API, login, processos ou documentos.
- Container PHP/Apache para produção; entrada Apache respeita `PORT` e commands one-off para migrações.
- Arquivos prontos `render.yaml` e `fly.toml`, além dos guias de deploy.
- [Pesquisa comparativa](research/market-landscape.md) incluindo Projuris ADV e outras plataformas jurídicas.

## Subir em nuvem

Comece por [`DEPLOY.md`](DEPLOY.md), que aponta para os passos completos:

- [Render](docs/deploy-render.md): conecte um repositório Git privado, preencha `DATABASE_URL` como segredo e use o Blueprint.
- [Fly.io](docs/deploy-flyio.md): `fly launch`, importe o segredo por `fly secrets import` e execute `fly deploy`.

Ambos usam um **MySQL compatível gerenciado externamente com TLS**; as migrações rodam antes do tráfego. Nunca publique `.env` ou `deploy/fly-secrets.local`. O código atual usa sessão PHP local: mantenha uma instância/máquina, como indicado nos guias. Não conecte dados de clientes até estabelecer e testar backups/restauração e os controles operacionais do escritório.

## Executar localmente

Requer PHP 8.3 com `pdo_mysql`, `mbstring` e `fileinfo`, além de MySQL 8/MariaDB compatível.

1. Copie `.env.example` para `.env` e configure `MYSQL_HOST`, `MYSQL_DATABASE`, `MYSQL_USER` e `MYSQL_PASSWORD`; use `DATABASE_URL` se preferir DSN URL.
2. Rode `php bin/migrate.php` para criar/atualizar tabelas de forma aditiva.
3. Opcional, somente em desenvolvimento: `php bin/seed-demo.php` cria registros inteiramente fictícios. Acesso local: `demo@lexcloud.app` / `Demo@123!`.
4. Inicie `php -S 0.0.0.0:3000 -t public router.php` e abra `http://localhost:3000`.

**Não execute a seed em produção.** Ela se recusa quando `APP_ENV=production`.

## PWA no celular

Acesse o endereço HTTPS. No Android/Chrome, escolha **Instalar app** ou **Adicionar à tela inicial**. No iPhone/iPad, use Safari → **Compartilhar → Adicionar à Tela de Início**. Offline, o app mostra uma tela de reconexão; não é possível consultar ou editar registros jurídicos sem internet.

## Segurança, mercado e limites

- `DATABASE_URL` TLS fica somente nas variáveis secretas do provedor; o repositório não inclui credenciais de nuvem.
- Sessão PHP atual é local e pode expirar em restart/redeploy. Implementar armazenamento compartilhado antes de aumentar réplicas.
- Consulte [`docs/security-and-scope.md`](docs/security-and-scope.md) antes de cadastrar dados reais.
- Esta entrega não se conecta ao Projuris nem importa sua base automaticamente; também não consulta tribunais, captura intimações, calcula prazos legais, envia e-mails/SMS, assina documentos, concilia banco, emite boleto/nota fiscal ou gera peças por IA.
