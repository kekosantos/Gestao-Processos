# Deploy do LexCloud no Render

## Antes de começar

- Conta Render e um repositório Git privado com o projeto extraído do ZIP. O Render cria o serviço a partir de um repositório conectado; não envie `.env` nem segredos ao Git.
- Um banco MySQL compatível, gerenciado fora do container, acessível a partir da região escolhida e configurado com TLS. O exemplo foi validado com MySQL/TiDB compatível; o Render Blueprint não cria um MySQL gerenciado para esta aplicação.
- Para o fluxo automatizado abaixo, use serviço **pago**. O `preDeployCommand` que aplica a migração não está disponível em serviços web gratuitos.

## 1. Preparar o MySQL

1. Crie um banco vazio chamado `lexcloud` (ou escolha outro nome e ajuste a URL).
2. Crie um usuário exclusivo para a aplicação e conceda somente os privilégios necessários sobre esse banco, inclusive criação de tabelas para a migração inicial.
3. Habilite TLS e configure a lista de rede/IP de origem no provedor, se necessária. Nunca use o usuário root do servidor.
4. Tenha a URL no formato suportado pelo LexCloud, por exemplo:

   ```text
   mysqls://USUARIO:SENHA_URL_ENCODED@HOST:4000/lexcloud
   ```

   `mysqls://` exige TLS e valida o certificado contra as CAs confiáveis do container. A porta `4000` é comum no TiDB Cloud, mas use host, porta e database fornecidos pelo seu provedor. Codifique em URL caracteres especiais da senha (`@`, `:`, `/`, `#`, `%` etc.). **Não cole esse valor em issues, chats, arquivos versionados ou logs.**

## 2. Colocar o código em um repositório

Extraia o ZIP e envie a pasta do projeto para um repositório privado GitHub/GitLab/Bitbucket. Exemplo em um projeto novo:

```bash
git init
git add .
git status --short   # confira que .env e deploy/fly-secrets.local não aparecem
git commit -m "Publica LexCloud"
git branch -M main
git remote add origin URL_DO_SEU_REPOSITORIO
git push -u origin main
```

O arquivo `render.yaml` já declara container Docker, uma instância paga `0.5c-512mb`, `GET /api/health` e a migração antes de cada deploy. O nome do serviço pode ser alterado para não colidir com outro serviço na sua conta.

## 3. Criar o serviço pelo Blueprint

1. No Render Dashboard, escolha **New → Blueprint** e conecte o repositório/branch `main`.
2. Revise `render.yaml`. Escolha uma região adequada à localização do banco e dos usuários; manter aplicação e banco próximos reduz latência.
3. Ao sincronizar o Blueprint, informe `DATABASE_URL` quando o Render solicitar o valor de `sync: false`. Use a URL TLS completa do passo 1.
4. Revise a instância e os custos na tela do Render antes de confirmar a criação.
5. O Render constrói o `Dockerfile`, executa `php bin/migrate.php` no pre-deploy e só então inicia a aplicação. A migração é aditiva (`CREATE TABLE IF NOT EXISTS`); ainda assim, mantenha backups antes de alterações operacionais.
6. Aguarde o deploy e confirme `GET https://SEU_SERVICO.onrender.com/api/health` com resposta HTTP 200. O health check valida banco, extensões PHP e limites de upload; erro 503 geralmente indica URL/TLS, permissões da migração, rede do provedor ou `MAX_UPLOAD_BYTES` inválido.

O container recebe `PORT` do Render (padrão documentado do serviço: `10000`). O entrypoint altera o listener Apache antes de iniciar; não fixe a porta `80` no painel.

## PWA e uso no celular

Acesse a URL HTTPS e faça login. No Android/Chrome, use **Instalar app** ou **Adicionar à tela inicial**; no iPhone/iPad, abra no Safari, toque em **Compartilhar → Adicionar à Tela de Início**. O layout adapta menu, cartões e tabelas a telas pequenas. O PWA offline apresenta apenas uma página de indisponibilidade: **não abre nem grava processos, clientes ou documentos sem rede**. Sessão e dados jurídicos não são colocados no cache do navegador.

## Cuidados de produção

- O plano gratuito do Render não é indicado para dados jurídicos: ele suspende serviços ociosos, tem filesystem efêmero e não oferece o pre-deploy deste Blueprint. Use-o apenas para demonstração descartável, sem dados reais.
- O LexCloud guarda documentos no MySQL e não depende de disco persistente para uploads. Configure backups e teste restauração no provedor de banco antes de inserir dados de clientes.
- Esta versão usa sessão PHP em arquivo local. O Blueprint fixa **uma instância**; não aumente `numInstances` sem implementar armazenamento compartilhado de sessão (por exemplo, sessão em banco/Redis) e validá-lo.
- Configure domínio próprio, TLS, política de backup, retenção, acesso e processo LGPD conforme a operação do escritório. A aplicação não automatiza esses controles do provedor.
- Não publique base importada do Projuris sem plano de migração, mapeamento, validação e autorização do responsável pelos dados.

## Referências oficiais (consultadas em 28/09/2026)

- [Render: Docker](https://render.com/docs/docker) — build do Dockerfile e comportamento do serviço.
- [Render: Blueprint YAML](https://render.com/docs/blueprint-spec) — `runtime: docker`, `dockerfilePath`, `healthCheckPath`, `preDeployCommand` e `sync: false`.
- [Render: Deploys](https://render.com/docs/deploys) — ordem do pre-deploy e disponibilidade apenas para serviços web pagos.
- [Render: Web Services](https://render.com/docs/web-services) — bind em `0.0.0.0` e variável `PORT`.
- [Render: Compute Plans](https://render.com/docs/compute-plans) — identificador atual `0.5c-512mb`.
- [Render: plano gratuito](https://render.com/docs/free) — suspensão, filesystem efêmero e limitações; não usar em produção jurídica.
- [TiDB Cloud: conexão Starter/Essential](https://docs.pingcap.com/tidbcloud/connect-to-tidb-cluster-serverless/) e [conexões TLS](https://docs.pingcap.com/tidbcloud/secure-connections-to-serverless-clusters/) — MySQL protocol e requisito de TLS.
