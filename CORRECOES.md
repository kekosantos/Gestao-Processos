# LexCloud — Correções (revisão CHS)

O layout e as funcionalidades foram mantidos. As mudanças são de segurança, de operação no Render e no Fly, e de administração da plataforma.

## Falhas corrigidas

| # | Gravidade | Problema | Correção |
|---|---|---|---|
| 1 | 🔴 Alta | **Funcionário desativado continuava com acesso**: seguia vendo clientes e processos até a sessão expirar. O mesmo valia para escritório suspenso. | A cada requisição, o usuário é reconferido no banco: conta ativa, escritório não suspenso, perfil atual. O acesso cai **na hora**. |
| 2 | 🔴 Alta | **Senha errada dava erro 500 e o bloqueio contra força bruta nunca ativava.** A primeira tentativa errada quebrava ao registrar e nunca contava. | Tentativas registradas corretamente: 6 erros bloqueiam por 15 minutos, com a resposta certa ("e-mail ou senha inválidos"). |
| 3 | 🟡 Média | **Sessão em arquivo**: todo deploy ou reinício deslogava todo mundo. | Sessão no banco (tabela `sessions`). |
| 4 | 🟡 Média | **Sem recuperação de senha e sem envio de e-mail.** O admin da plataforma só era criado por terminal, que o Render gratuito não tem. | **"Esqueci minha senha"** por e-mail: link de 1 hora, uso único, limite de 3 pedidos a cada 15 minutos, mesma resposta exista ou não a conta. **Instalação pela web** com `SETUP_TOKEN`, que se desliga sozinha. |
| 5 | 🟡 Média | **Qualquer pessoa criava um escritório** pelo autocadastro público. | Autocadastro **desligado** (`ALLOW_SIGNUP=true` religa). Os escritórios são cadastrados pelo admin da plataforma. |
| 6 | ⚪ Baixa | O bloqueio de login e a auditoria usavam o IP do proxy. | IP real (`TRUST_PROXY=true`). Quem erra a senha é bloqueado; a dona da conta, em outro IP, continua entrando. |
| 7 | ⚪ Baixa | Trocar a senha não derrubava sessões abertas em outros aparelhos. | Trocar ou redefinir a senha encerra as outras sessões. |

## Novo: painel da plataforma (admin CHS)

- **+ Novo escritório**: nome, plano e responsável. O responsável recebe por e-mail um link para **criar a própria senha**, válido por 48h e uso único. O link também aparece para você, caso o e-mail não chegue.
- **Reenviar convite**, **Suspender** e **Reativar**: um escritório suspenso perde o acesso imediatamente.
- **Suporte**: você vê o escritório **somente leitura**, com uma faixa de aviso. Não altera nada, e a entrada fica registrada na auditoria.

## Deploy
- **Render gratuito:** as tabelas são criadas ao iniciar o container (`RUN_MIGRATIONS=true`), porque o pré-deploy só existe nos planos pagos.
- **Fly:** o `release_command` já cria as tabelas antes de cada versão.

## Testes
- `tests/integration_http.py`: a bateria original, **13 de 13**. Rode com `ALLOW_SIGNUP=true`, porque ela usa o autocadastro.
- `tests/correcoes_http.py`: **35 de 35**, cobrindo tudo o que está acima, com e-mail real por SMTP de teste.


## v2.1: acesso inicial, demo e login no celular
- **Admin da plataforma criado sozinho:** usuário `cleiton.santos`, senha padrão, troca obrigatória. `ADMIN_REDEFINIR=true` recupera um admin existente.
- **Login por e-mail ou usuário.**
- **Senha padrão (`SENHA_PADRAO`, sem ela vale `password`)** para as contas criadas pela plataforma:
  - troca obrigatória no primeiro acesso;
  - validade de 7 dias, verificada no banco para não sofrer com diferença de fuso;
  - não pode ser repetida como senha nova.
- **Equipe criada pelo escritório:** mantém uma senha temporária própria, para que um escritório não conheça a senha inicial dos outros.
- **Escritório DEMO com dados fictícios**, criado junto com a migração, só uma vez.
- **Banco que já existia:** a migração acrescenta as colunas novas sem apagar nada. Isso foi testado num banco no formato antigo, com dados.
- **Login no tablet e no celular:** a faixa do sistema fica compacta em cima, e o formulário aparece inteiro sem rolar. No computador, nada muda.
- **Saudação do painel:** ignora títulos ("Bom dia, Helena", e não "Bom dia, Dra.").
- **Testes:** `tests/senha_demo_http.py`, com 26 verificações. Total: 74.


## v2.2: acesso da CHS igual ao Gestão de Notas
- **No painel da plataforma**, clicar no nome do escritório ou em **"Acessar"** abre o **dashboard dele**, com **acesso completo**, e "Voltar ao painel" retorna. É o mesmo esquema do "Dashboard" do Gestão de Notas.
- **Autoria:** o que a CHS faz lá dentro fica em nome do usuário oculto **"Suporte CHS"** do escritório. Ele não aparece na equipe, não entra pelo login e não conta nos usuários, mas deixa claro na auditoria o que foi feito pela CHS. A entrada também é registrada, com o e-mail de quem acessou.
- **Trocar senha:** dentro do escritório, não é possível (a conta de suporte não tem senha).
- **Testes:** `correcoes_http.py` passou a ter 42 verificações. Total: 81.


## v2.2.1: banco com os nomes padrão da CHS
- **Nomes iguais ao Gestão de Notas:** `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` e `DB_SSL`. Os nomes antigos (`DATABASE_URL` e `MYSQL_*`) continuam aceitos.
- **TLS ligado sozinho** quando o host é do TiDB (`*.tidbcloud.com`) ou com `DB_SSL=true`. Isso corrige o erro `[1105] Connections using insecure transport are prohibited`.
- **Testes:** passam com os nomes novos e com os antigos.


## v2.3: biometria (digital, Face ID, Windows Hello)
- **Mesmo motor do Gestão de Notas:** assinatura verificada (ES256 e RS256), desafio de uso único, origem e domínio conferidos, biometria obrigatória e contador contra clonagem.
- **Login:** botão "Entrar com digital ou Face ID". O sistema **não lista** as contas cadastradas; é o próprio aparelho que oferece a conta.
- **Tela "Biometria"** (menu, embaixo): cadastrar este aparelho, listar e remover. Vale para todos os usuários, inclusive o admin da plataforma.
- **Mesmas regras do login por senha:** conta desativada, escritório suspenso ou senha trocada derrubam o acesso. A conta oculta "Suporte CHS" nunca entra.
- **O banco guarda só a chave pública.**
- **Testes:**
  - `tests/biometria_http.py`, com 33 verificações nos dois tipos de aparelho, incluindo assinatura falsa, clonagem, reuso da resposta, site falso e contador;
  - teste no navegador com o autenticador virtual do Chrome: cadastrar, sair e entrar.
- **Observação:** a biometria só funciona em endereço com nome (`*.onrender.com`, `*.fly.dev`, domínio próprio). Não funciona em IP. Confira se o `APP_URL` é o endereço público.


## v2.4: botão "Instalar app" (PWA)
- **O app já era instalável** (o Chrome não apontou nenhum erro), mas não tinha botão próprio. Sem ele, no celular a opção fica escondida no menu do navegador.
- **Agora, como no Gestão de Notas:**
  - convite "Instale o LexCloud" no login (retirado na v2.4.1: a instalação fica só para quem já entrou);
  - item **"Instalar app"** no menu.
  - Os dois só aparecem quando o navegador permite instalar.
- **iPhone:** o Safari não tem o convite automático, então aparece a instrução **Compartilhar → Adicionar à Tela de Início**.
- **Já instalado:** quando o sistema é aberto como app, não aparece nada.
- **Cache:** a versão dos arquivos passou de `?v=2` para `?v=24`. Antes, um celular que já tinha aberto o sistema continuava com o `app.js` antigo guardado pelo service worker.


## v2.5: nome exibido AdvCloud
- **Onde muda:** telas, título da aba, app instalado (manifest), página offline, ícones, e-mails, remetente padrão e nome mostrado na janela da biometria.
- **O que fica igual, de propósito:** os nomes internos (código `LexCloud\...`, banco `lexcloud`, repositório, serviço no Render e linhas de log). Mudá-los não traria nada ao usuário e só criaria risco.
- **Demo:** a equipe fictícia passa a usar `@demo.advcloud`, inclusive na demo que já existia (pela migração, sem apagar nada).
- **Biometria:** as já cadastradas continuam valendo, porque o domínio não mudou.


## v2.6.0: versão do sistema e ajustes do login
- **Versão do sistema** em `app/version.php`, mostrada no rodapé do login ("Versão 2.6.0") e embaixo do menu ("AdvCloud v2.6.0"), com a data ao passar o mouse. Atualize o número a cada entrega.
- **Título do login:** "Bem-vindo!".
- **Círculo do admin da plataforma:** mostra **"A"** de AdvCloud (antes era "L" de LexCloud).
