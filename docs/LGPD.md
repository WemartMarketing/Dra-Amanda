# Privacidade e uso dos dados (LGPD)

Este texto orienta o uso do WeMart Sales de acordo com a Lei Geral de Proteção de Dados (Lei 13.709/2018). **Não é parecer jurídico**: cada empresa que usa o sistema é a controladora dos dados dos seus clientes e deve validar suas regras com o próprio jurídico ou encarregado (DPO).

## Que dados o sistema guarda

- Dos clientes (leads): nome, telefone, e-mail, interesse, renda informada, campanha de origem, histórico de atendimento, compromissos de agenda e arquivos enviados pela equipe.
- Dos usuários: nome, e-mail, função, equipe e registros de acesso (auditoria).
- Leads recebidos do Meta Ads chegam com os campos do formulário que o próprio cliente preencheu.

## Para que os dados são usados

- Atender o cliente que demonstrou interesse num empreendimento: contato, visitas, propostas e contrato.
- Medir o desempenho comercial em números agregados (dashboards e relatórios), sem expor o cadastro de cada cliente.

## Quem vê o quê

- Cada pessoa só vê os leads do seu recorte: o corretor vê a própria carteira, o gerente a equipe, o superintendente as equipes dele, o GP os produtos vinculados.
- Dashboards e relatórios mostram apenas contagens e nomes de colaboradores — nunca nome ou telefone de clientes.
- Arquivos dos clientes ficam em pasta privada e só saem pelo download autenticado.

## Exportações

- Só quem tem a permissão **Exportar relatórios** consegue baixar planilhas, e cada exportação fica registrada na auditoria (quem, quando, quais filtros, quantas linhas).
- A exportação da lista de leads contém dados pessoais: use apenas para o atendimento, não compartilhe fora da empresa e apague o arquivo quando não precisar mais.

## Direitos do titular

Quando um cliente pedir:

- **Acesso aos dados**: na ficha do lead, **Mais → Exportar dados do titular (LGPD)** gera um arquivo com tudo o que o sistema guarda sobre aquela pessoa. Entregue só a ela, por um canal seguro.
- **Eliminação**: na ficha, **Mais → Anonimizar lead (LGPD)**. Nome, telefone, e-mail, interesse, campanha, renda, observações e arquivos são apagados de forma **irreversível**; ficam só dados estatísticos (etapa, datas, origem, empreendimento e responsável), para que os relatórios continuem corretos.
- **Correção**: edite os dados na própria ficha.

As duas ações exigem a permissão **Excluir leads** e ficam registradas na auditoria.

## Segurança

- Senhas guardadas com hash; bloqueio após tentativas erradas de login.
- Proteção contra envio de formulários forjados (CSRF) em todas as gravações.
- Tokens de integração cifrados no banco.
- Backups diários do banco em pasta protegida; recomenda-se manter também os backups da hospedagem e uma cópia fora do servidor.

## Retenção

Defina com o jurídico por quanto tempo manter leads que não viraram negócio. Quando o prazo vencer, anonimize os leads antigos.

## Contato

Dúvidas sobre os seus dados: fale com a empresa que atendeu você (a imobiliária ou incorporadora responsável pelo atendimento).
