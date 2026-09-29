# Plano de implementação — LexCloud

## Objetivo e escopo

Construir uma primeira versão SaaS multi-tenant em português para escritórios brasileiros, com PHP puro (sem framework PHP), MySQL e PWA responsivo. A pesquisa compara Projuris ADV e outros sete produtos; o recorte prioriza o núcleo comum (clientes, casos, prazos, tarefas, agenda, documentos, financeiro básico, equipe e relatórios), deixando captura judicial, IA e automações enterprise para fases com fornecedores e revisão humana.

## Arquitetura e operação

- **Aplicação:** PHP 8.3, front controller e API JSON same-origin; container PHP/Apache na produção, endpoint de prontidão `GET /api/health`. O Preview usa o servidor PHP na porta 3000 com `public/` como document root.
- **Interface:** HTML/PHP para o shell e CSS/JavaScript sem framework para as páginas autenticadas. A navegação das áreas internas é por hash. Não há dependências PHP externas obrigatórias.
- **Banco:** MySQL 8 compatível; `DATABASE_URL` do ambiente de nuvem tem precedência sobre `MYSQL_*` local. Quando o DSN solicita TLS, PDO valida o certificado usando o bundle CA ou `MYSQL_SSL_CA`. `php bin/migrate.php` é uma migração aditiva fora do startup de requisições; `render.yaml` e `fly.toml` a executam, respectivamente, como pre-deploy pago do Render e `release_command` do Fly.io.
- **Multi-tenancy:** usuário de escritório pertence a um tenant; registros jurídicos têm `tenant_id`, consultas são filtradas por tenant e FKs compostas impedem vínculo entre escritórios. Administrador global é provisionado separadamente e só vê metadados agregados.
- **Segurança:** prepared statements, `password_hash`, regeneração de sessão, CSRF, cookies protegidos em HTTPS, rate limit de login, auditoria e respostas privadas `no-store`. Papéis controlam rotas e ações na interface, com autorização repetida no servidor. Senhas temporárias bloqueiam também download até a troca; recebíveis e relatórios financeiros ficam limitados a papéis autorizados.
- **Sessões de produção:** a sessão PHP atual usa arquivos locais; Render e Fly ficam configurados com uma única instância/máquina. Reinícios podem exigir novo login. Antes de escalar horizontalmente, implementar e validar um armazenamento compartilhado de sessões.
- **Arquivos:** PDF, DOCX, PNG, JPG e TXT até 12 MiB por controller, como BLOB no MySQL; o container reserva 13 MB para upload e 16 MB para o corpo multipart. O health check exige extensões e limites coerentes.
- **PWA:** manifesto e service worker próprios, modo standalone, ativos responsivos e tela offline. Somente o shell público versionado é cacheado; sessão, API, páginas autenticadas e documentos não entram no cache. O cache do shell é invalidado quando os assets mudam.

## Fluxos e telas do MVP

1. **Acesso:** cadastro de escritório/tenant, login/logout e alteração obrigatória da senha temporária; não há recuperação self-service por e-mail/MFA nesta versão.
2. **Painel:** indicadores do tenant, tarefas, próximos eventos e casos; indicadores financeiros só aparecem para papéis autorizados.
3. **Clientes e partes:** pesquisa/listagem e cadastro, com botões de alteração apresentados conforme o papel.
4. **Processos/casos:** pesquisa/listagem, cadastro, consulta individual, responsável, status e histórico de atividade; ações de criação e edição respeitam o papel.
5. **Tarefas e prazos:** criação, atribuição, filtro/listagem e alteração de status; equipe de apoio pode se autoatribuir sem consultar o diretório da equipe.
6. **Agenda e audiências:** criar e consultar eventos, com vínculo opcional ao caso.
7. **Documentos:** upload permitido, metadados, vínculo opcional ao caso/cliente e download autenticado após conclusão da troca de senha.
8. **Financeiro:** receitas/despesas, recebíveis básicos, vínculo opcional e alteração de status; leitura e mutação financeiras são limitadas; não emite nota fiscal, boleto nem Pix.
9. **Equipe:** convite de usuário com senha temporária, papéis por tenant e ativação/desativação; leitura e mutação respeitam o papel.
10. **Relatórios:** agregados descritivos de casos, tarefas, financeiro e equipe; a parte financeira é omitida para quem não tem permissão financeira.
11. **Configurações:** nome do escritório e fuso horário.
12. **Administração de plataforma:** lista de tenants e contagens; sem acesso ao conteúdo dos processos.

O MVP privilegia os fluxos essenciais de tela e não promete operações de editar/excluir para todos os campos, automação judicial ou substituição de sistemas legados.

## Estrutura real

- `public/`: front controller, assets, manifesto de rotas, ícones PWA, service worker, tela offline e `.htaccess`.
- `app/Support/`: conexão PDO/MySQL, HTTP, CSRF, sessão, papéis e controles de tenant.
- `app/ApiController.php`: autenticação, módulos jurídicos e APIs.
- `database/schema.sql` e `bin/migrate.php`: tabelas/constraints e migração aditiva.
- `bin/`: seed fictício opcional (aceita somente `dev`, `development`, `local` ou `test`) e provisionamento de administrador de plataforma.
- `docker/` e `Dockerfile`: Apache, document root restrito a `public/`, extensões PHP verificadas, limites de upload e porta `PORT`.
- `render.yaml`, `fly.toml`, `deploy/fly-secrets.example`, `DEPLOY.md` e `docs/deploy-*.md`: configurações e guias reproduzíveis para Render e Fly.io.
- `tests/`: smoke test HTTP multi-tenant, controle de papéis/download e limpeza transacional restrita a tenants fictícios.
- `docs/`: instalação, segurança e limites; `research/market-landscape.md`: comparação de mercado.

## Rotas e manifesto

- Shell/páginas: `/`, `/login`, `/cadastro`, `/offline`; áreas internas via `/#/dashboard`, `/#/processos`, `/#/clientes`, `/#/tarefas`, `/#/agenda`, `/#/documentos`, `/#/financeiro`, `/#/equipe`, `/#/relatorios`, `/#/configuracoes`, `/#/plataforma/tenants`.
- API same-origin: `/api/auth/*`, `/api/health`, `/api/dashboard`, `/api/reports`, `/api/platform/tenants`, `/api/clients`, `/api/cases`, `/api/tasks`, `/api/events`, `/api/documents`, `/api/finance`, `/api/team` e `/api/settings`.
- `GET /manus-routes.json` declara as páginas. Não há cache compartilhado nem regra de static fallback para dados privados; os pedidos seguem ao container PHP.

## Hospedagem, cache e publicação

O projeto gerenciado continua com Preview temporário e manifest/service worker próprios. O pacote também traz deploy externo por container: Render lê `render.yaml`, recebe `DATABASE_URL` como segredo, verifica `/api/health` e executa migração no pre-deploy de serviço pago; Fly.io lê `fly.toml`, importa `DATABASE_URL` como segredo e executa a mesma migração via `release_command`. Ambos apontam para MySQL externo compatível com TLS, sem guardar dados em filesystem local. O Render usa uma instância paga; o Fly.io é documentado e dimensionado para uma Machine. O plano gratuito do Render não é indicado para dados jurídicos de produção e não oferece o pre-deploy usado no Blueprint.

As instruções completas estão em `DEPLOY.md`, `docs/deploy-render.md` e `docs/deploy-flyio.md`. Não foi criado serviço nem deploy fora do Sandbox. Conteúdo privado mantém `Cache-Control: private, no-store`; somente assets públicos versionados podem ser cacheados. O service worker dá uma tela offline, não consulta nem sincroniza processos offline. A aplicação não foi publicada em produção; o Preview temporário não deve ser apresentado como domínio público.

## Evidências de validação

- `php -l` em todos os PHP, `node --check` no JS, `python3 -m py_compile` no teste HTTP e leitura dos manifests JSON.
- Extensões `pdo_mysql`, `mbstring` e `fileinfo` disponíveis; readiness verifica módulos, limites de upload e `SELECT 1` no MySQL gerenciado.
- `tests/integration_http.py` exercita cadastro, clientes, casos, tarefas/datas, eventos, financeiro, upload/download, gate de senha temporária, papéis, relatório financeiro redigido, autoatribuição da equipe, CSRF e isolamento de dados/arquivos em dois tenants; fixtures `LexCloud TEST-*` são removidos em transação.
- O service worker e as URLs de CSS/JS avançam para a versão 2 do cache. `GET /manus-routes.json`, manifest, service worker e assets são verificados no Preview; APIs privadas respondem `no-store`.
- O entrypoint aceita comandos one-off sem subir Apache para que os release hooks do Render/Fly executem `php bin/migrate.php`. `render.yaml` é analisado como YAML e `fly.toml` como TOML; os dois apontam ao mesmo health check e ao mesmo `DATABASE_URL` TLS.

## Limites explícitos

Não há importação validada ou integração com Projuris, consulta de processos, captura de intimações, cálculo legal de prazo, scraping, geração de peça por IA, envio de e-mail/SMS, portal do cliente, assinatura digital, integração bancária, Pix/boleto, emissão fiscal, MFA, backup gerenciado ou armazenamento de arquivos externo. Não cadastrar documentos reais no Preview; concluir revisão de segurança, backups, LGPD e operação antes de usar dados jurídicos de produção.
