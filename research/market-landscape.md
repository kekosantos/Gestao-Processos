# Pesquisa de mercado — sistemas de gestão jurídica no Brasil

**Consulta:** 28 de setembro de 2026. Foram examinadas páginas oficiais dos produtos; funcionalidades abaixo são declarações dos fornecedores, não auditoria independente de desempenho, segurança ou qualidade. Preços e recursos mudam, podem depender de módulos/planos e devem ser confirmados comercialmente.

## Comparativo

| Produto | Público/posicionamento | Forças e fluxos observados | Preço público nas fontes consultadas |
|---|---|---|---|
| **Projuris ADV** (SAJ ADV é denominação histórica) | Autônomos a escritórios estruturados/em expansão; a Projuris separa a solução para jurídico empresarial. | Gestão de processos, prazos, intimações, agenda, tarefas/Kanban, workflows, financeiro/ERP, atendimento, timesheet, documentos, portal do cliente, permissões, unidades, auditoria, API e IA assistiva. Fluxo forte: publicação → revisão → tarefa/prazo → responsável; processos de honorários e atendimento vinculados ao caso. | Não foi possível confirmar tarifa pública para o ADV completo. A página também mostra Projuris ADV Start (ex.: Solo R$79/mês e Compartilhado R$349/mês), valores não atribuídos ao plano completo. Verificar com fornecedor. |
| **Astrea (Aurum)** | Principalmente advogados autônomos e escritórios pequenos/médios; a Aurum distingue o Themis para departamento jurídico/grandes bancas. | Monitoramento de processos e publicações, tarefas/Kanban, prazos, documentos/modelos, honorários, timesheet, portal/app do cliente, IA para classificar andamentos, sugerir prazos/tarefas e traduzir atualizações. Fluxo publicação → sugestão IA → revisão → atividade/cliente. | A página oficial exibia planos Light (gratuito por um ano), Up R$209/mês anual, Smart R$379/mês anual, Company R$689/mês anual e VIP R$1.249/mês anual; teste grátis de 10 dias. Valores/limites variam e precisam ser confirmados. |
| **ADVBOX** | Sobretudo escritórios, com planos para operações menores a grandes bancas; também cita departamentos jurídicos. | Processos, intimações, workflows e controladoria; Kanban e Taskscore; modelos/documentos; BI e gestão financeira; recursos de IA assistiva; integrações com agendas/Asaas em planos elegíveis e API. Fluxo de publicação → triagem → tarefa → produtividade/indicadores. | Valores públicos “a partir de” na página de planos: Essencial R$220, Banca R$450, Banca Max R$900 e Big Offices R$5.000/mês; Elite apresentava discrepância entre R$1.750 e R$1.800 na mesma página. Ativação e condições devem ser confirmadas. |
| **Legal One (Thomson Reuters)** | Oferta para escritórios (Firm) e departamentos jurídicos corporativos (Corporate), com pacotes por porte. | Processos, publicações, prazos, tarefas, audiências, GED, documentos/modelos, Kanban, financeiro (honorários, cobrança, faturamento e relatórios), workflows, APIs, dashboards e portal do cliente. Corporate pode combinar módulos empresariais. | Não identificados preços públicos nas páginas oficiais consultadas; contato/demonstração comercial. |
| **SAJ ADV / Projuris ADV** | A página atual de SAJ ADV remete ao produto Projuris ADV; foco em escritórios e pequenas procuradorias. Não confundir com Projuris Empresas. | Processos, prazos, publicações, agenda, documentos, workflows, gestão financeira, IA e API; fluxos para contencioso e consultivo conforme contratação. Diferencia-se pela oferta escalável e implantação assistida do plano completo. | ADV Start publica Solo R$79/mês e Compartilhado R$349/mês; não são preço do ADV completo, cujo valor não é apresentado nas fontes consultadas. |
| **CPJ-3C (Preâmbulo Tech)** | Escritórios de grande porte e departamentos jurídicos de maior volume/complexidade. | Cadastro configurável, monitoramento, distribuição de publicações, workflows acionados por movimentação, prazos/tarefas, APIs, mobile/CPJ Connect, dashboards/BI, IA assistiva e financeiro ligado ao processo. | Não identificados planos ou preços públicos; direciona a demonstração/cotação. |
| **Themis (Aurum)** | Departamentos jurídicos e grandes bancas; a Aurum distingue o produto de soluções a pequenos escritórios. | Contencioso, processos terceirizados, contratos, intake de áreas internas, aprovação, SLA, prazos, IA/análise preditiva, provisionamento, dashboards, API e trilhas financeiras. Não confirmado como produto da Benner; fontes oficiais identificam a Aurum. | Não foram encontrados preços públicos nas páginas oficiais consultadas. |
| **Docket** | Operações documentais empresariais, departamentos jurídicos e alguns escritórios; solução especializada em documentos, não um ERP completo de processos/financeiro. | Esteira de busca/emissão/análise de certidões, registros, contratos e documentos regulatórios; extração de dados por IA, análise em lote, alertas de validade, rastreabilidade e API. Inspira uma futura integração documental, não substitui o núcleo processual. | Sem tabela pública de assinatura/licenciamento; contato comercial. Produto IA anuncia teste, sem preço/condições suficientes. |

> Nota: Projuris ADV e SAJ ADV aparecem relacionados nas fontes atuais; são tratados como a mesma linhagem de produto, não como duas soluções independentes para contagem de cobertura.

## Padrões do mercado

1. O núcleo recorrente é processo/caso, cliente/parte, prazo, tarefa, agenda, documento e responsável.
2. Monitoramento de publicações/intimações é valorizado, mas cobertura, limites, tribunais e planos variam. O fluxo mais seguro é publicação → classificação/sugestão → revisão humana → tarefa e prazo.
3. Kanban, listas, tarefas recorrentes, workflows e distribuição de responsáveis ajudam a padronizar a equipe; automação e SLA variam de simples a enterprise.
4. Financeiro jurídico normalmente conecta honorários/recebíveis e processos; boletos/Pix, conciliação, fiscal, ERP e provisionamento dependem de módulos e integrações.
5. Portais e aplicativos podem compartilhar documentos e atualizações com clientes, sempre com permissões.
6. IA aparece como assistência contextual (resumo, classificação, sugestão de prazo/tarefa, tradução e apoio documental), não como substituto do advogado; revisão é essencial.
7. API, dashboards e gestão por unidades ajudam a escalar; análise preditiva exige governança de dados e validação própria.

## Decisões de produto derivadas

- **MVP:** base multi-tenant isolada por tenant, papéis, clientes/partes, casos, histórico, tarefas/prazos, agenda, documentos, financeiro mínimo, equipe, configurações e relatórios descritivos.
- **MVP sem dependência externa:** usar cadastro manual dos processos e prazos, permitir que advogado revise/crie a atividade e não declarar captura automática de tribunais ainda não integrada.
- **Segurança:** manter tenant e papel como filtro obrigatório no servidor; auditoria, CSRF, permissões e respostas privadas. Compartilhamento com cliente deve ser concedido intencionalmente.
- **Próxima fase:** captura automática de intimações em tribunais selecionados e IA assistiva com confirmação humana; validar cobertura, licenciamento e responsabilidade antes de ativar.
- **Depois:** portal completo do cliente, financeiro avançado/boletos, assinatura digital, integrações API/ERP/CRM, analytics e esteira documental tipo Docket.
- **Evitar no primeiro corte:** fluxo empresarial de intake/contratos/provisionamento, BPM genérico complexo, automação autônoma de prazos, previsões de êxito, emissão fiscal, scraping e promessas de cobertura/segurança não verificadas.

## Fontes oficiais consultadas

- [Projuris ADV](https://www.projuris.com.br/adv/) · [Central de Ajuda Projuris ADV](https://ajuda.projurisadv.com.br/space/BDC/34963592) · [Workflow jurídico Projuris](https://www.projuris.com.br/blog/workflow-juridico/)
- [Astrea](https://www.aurum.com.br/astrea/) · [Planos e preços](https://www.aurum.com.br/astrea/planos-e-precos/) · [Gestão de tarefas](https://www.aurum.com.br/astrea/gestao-de-tarefas/) · [IA](https://www.aurum.com.br/astrea/inteligencia-artificial/)
- [ADVBOX — software](https://advbox.com.br/software-juridico) · [Planos](https://advbox.com.br/planos) · [API](https://advbox.com.br/api)
- [Legal One Firm](https://www.thomsonreuters.com.br/pt/juridico/legal-one/firm.html) · [Legal One Corporate](https://www.thomsonreuters.com.br/pt/juridico/legal-one/corporate.html)
- [SAJ ADV / Projuris](https://www.sajadv.com.br/) · [Projuris ADV](https://www.projuris.com.br/adv/)
- [CPJ-3C](https://preambulo.com.br/cpj-3c/) · [Software CPJ-3C](https://preambulo.com.br/software-cpj-3c/)
- [Themis (Aurum)](https://www.aurum.com.br/themis/) · [Themis para departamento jurídico](https://www.aurum.com.br/blog/themis-para-departamento-juridico/) · [Ajuda Themis](https://themis.aurum.com.br/pt-BR/articles/15521047-como-utilizar-as-requisicoes-financeiras-no-themis)
- [Docket — produtos](https://docket.com.br/produtos/) · [Docket IA](https://docket.com.br/docket-ia/) · [Docket e Legal Ops](https://docket.com.br/blog/o-impacto-da-esteira-de-documentacao-em-legal-ops/)

**Limitação da pesquisa:** páginas de fornecedor são fontes primárias de posicionamento e funcionalidades anunciadas, mas não validam desempenho independente, cobertura real dos tribunais, termos contratuais, SLA ou segurança certificada. Preços acima são retratos de páginas públicas, não recomendação comercial.