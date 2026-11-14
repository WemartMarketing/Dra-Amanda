# Plantões de vendas e navegação

Tela operacional para corretores e gestores encontrarem os plantões, verem o
endereço e abrirem a rota no **Google Maps** ou no **Waze** com um toque.
Amplia o cadastro de plantões que já existia (a mesma tabela alimenta a
presença por GPS das roletas) — nada foi duplicado.

## Instalação
1. `git pull`
2. Importe `sql/migration_012_plantoes_navegacao.sql` (phpMyAdmin → Importar).
   Pode rodar mais de uma vez. Antes disso, a tela Plantões mostra um aviso (não
   dá erro).
3. Em **Plantões → Editar plantão**, confira endereço, CEP, latitude/longitude e
   envie a foto do stand.

## Telas
- **Plantões** (menu, para todos os usuários da empresa): cards grandes com foto,
  endereço, bairro/cidade/CEP, horário, empreendimentos, “Você está neste
  plantão”, e os botões **Google Maps**, **Waze**, **Copiar endereço** e **Ver
  detalhes**. Busca por nome, bairro, cidade ou empreendimento; filtros **Todos os
  plantões** e **Meus plantões**.
- **Detalhes do plantão**: foto grande, endereço completo, orientações de acesso,
  rotas, mapa (OpenStreetMap, sem chave de API), próximos 7 dias da roleta
  (pausas e feriados), empreendimentos e **ENTRAR NA ROLETA**.
- **Cadastro (Master / “gerenciar plantões”)**: nome, empresa responsável,
  endereço, complemento, CEP (botão “Preencher pelo CEP”, via ViaCEP), bairro,
  cidade, estado, latitude/longitude (ou “Usar minha localização atual”), raio,
  foto, horário (para plantão sem roleta), orientações, empreendimentos, roleta(s)
  do plantão, corretores autorizados e ativo/inativo.

## Rotas
- Com coordenadas (padrão oficial, coordenadas codificadas na URL):
  - Google Maps: `https://www.google.com/maps/dir/?api=1&destination=LAT%2CLNG&travelmode=driving&dir_action=navigate`
  - Waze: `https://www.waze.com/ul?ll=LAT%2CLNG&navigate=yes`
- Sem coordenadas: busca pelo endereço (codificado) e um aviso para conferir o
  destino. Sem endereço nem coordenadas: nenhum botão de rota (nunca uma URL
  inválida). Coordenadas 0,0 ou adulteradas são ignoradas.
- **No celular** o link abre na mesma aba: o Android/iPhone entrega o link ao
  aplicativo do Google Maps ou do Waze quando instalado; sem app, abre a versão
  web. **No computador** abre em nova aba.
- Abrir a rota **não pede a localização** do usuário.

## Roleta
“ENTRAR NA ROLETA” leva à tela **Minha roleta** da roleta daquele plantão, que
aplica as regras que já existem: vínculo do corretor na roleta, pausa, horário
(com pausas e feriados) e presença por GPS dentro do raio (padrão 300 m). Só
aparece para quem participa da roleta. Ir até o plantão e entrar na roleta são
funcionalidades independentes.

## Permissões
- Só plantões da **empresa ativa**; inativos só para quem gerencia. Id de outra
  empresa → não encontrado. Superadmin não acessa os dados operacionais.
- **Meus plantões**: corretor autorizado no cadastro do plantão **ou** que está
  numa roleta dele. Para receber leads, o corretor precisa estar na roleta.
- Cadastro só com a permissão “gerenciar plantões”. Empreendimentos, roletas e
  corretores de outra empresa são ignorados ao salvar.
- Foto: JPG/PNG/WEBP verificada e regravada, com nome aleatório, em
  `uploads/plantoes/` (a pasta só serve imagens).

## Testes
`php tests/plantoes_test.php` — 50 verificações (URLs de rota, codificação de
endereço com acentos e “&”, coordenadas inválidas, validações do cadastro,
vínculos, lista, busca, “Meus plantões”, inativos, isolamento entre empresas).
6 mutações detectadas. Fluxo pela tela no computador e no celular simulado,
incluindo a abertura das rotas (nova aba no computador, mesma aba no celular).
