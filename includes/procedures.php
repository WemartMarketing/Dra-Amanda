<?php
/**
 * AURÉA — Narrativa dos 10 estados de scroll (seção 9 do briefing).
 *
 * Cada estado define: identificador, textos, peso relativo de scroll
 * (altura virtual da trilha), configuração de câmera virtual sobre a
 * sequência real de quadros, posição segura do texto e hotspots.
 *
 * SOBRE OS ÍNDICES DE QUADRO (`frame`):
 * A sequência combinada tem 120 quadros (desktop) / 60 (mobile), cobrindo
 * uma rotação real e contínua: 1 = perfil esquerdo completo, ~60/61 =
 * frontal, 120 = perfil direito completo (ver docs/ASSETS_PENDENTES.md).
 * Nenhum ângulo fora dessa faixa existe no material — por isso a câmera
 * virtual reaproveita quadros já capturados fora de ordem linear (ex.:
 * volta ao quadro frontal depois de visitar o perfil), em vez de inventar
 * uma rotação que não foi gravada. Isso é equivalente ao movimento de
 * "afastar, reposicionar e aproximar" descrito na seção 8 do briefing.
 *
 * As coordenadas de hotspot/zona de texto são aproximações razoáveis para
 * um enquadramento `contain` central — ajuste fino visual é recomendado
 * após testar em navegador real (não foi possível renderizar nesta sessão).
 */

return [
    [
        'id'          => 'apresentacao',
        'index'       => 0,
        'scrollWeight'=> 0.6,
        'title'       => 'Harmonização Facial',
        'subtitle'    => 'Beleza, equilíbrio e sofisticação em cada detalhe.',
        'description' => '',
        'isHero'      => true,
        'scrollCue'   => 'SCROLL PARA EXPLORAR',
        'camera'      => ['frame' => 60, 'zoom' => 0.48, 'focalX' => 50, 'focalY' => 46],
        'text'        => ['side' => 'center', 'anchor' => 'top'],
        'faceExclusion' => ['cx' => 50, 'cy' => 50, 'rx' => 48, 'ry' => 48],
        'hotspots'    => [],
        'pendingAsset'=> null,
    ],
    [
        'id'          => 'olheiras',
        'index'       => 1,
        'scrollWeight'=> 1.0,
        'title'       => 'Tratamento de Olheiras',
        'description' => 'Conheça as possibilidades de suavizar determinadas alterações da região dos olhos, respeitando as características individuais.',
        'camera'      => ['frame' => 58, 'zoom' => 1.6, 'focalX' => 48, 'focalY' => 36],
        'text'        => ['side' => 'left', 'anchor' => 'middle'],
        'faceExclusion' => ['cx' => 50, 'cy' => 40, 'rx' => 26, 'ry' => 37],
        'hotspots'    => [
            ['x' => 44, 'y' => 40, 'label' => 'Região infraorbital'],
        ],
        'pendingAsset'=> null,
    ],
    [
        'id'          => 'preenchimento-labial',
        'index'       => 2,
        'scrollWeight'=> 1.0,
        'title'       => 'Preenchimento Labial',
        'description' => 'Contorno, volume e proporção planejados para valorizar a harmonia dos lábios.',
        'camera'      => ['frame' => 62, 'zoom' => 1.7, 'focalX' => 50, 'focalY' => 60],
        'text'        => ['side' => 'left', 'anchor' => 'middle'],
        'faceExclusion' => ['cx' => 50, 'cy' => 58, 'rx' => 24, 'ry' => 22],
        'hotspots'    => [
            ['x' => 50, 'y' => 57, 'label' => 'Lábios'],
        ],
        'interactiveDemo' => 'labial-volume',
        'pendingAsset'=> 'Animação ilustrativa de variação de volume labial (ativável por hover/clique/toque) — aguardando vídeo ou sequência de quadros apropriada.',
    ],
    [
        'id'          => 'contorno-mandibular',
        'index'       => 3,
        'scrollWeight'=> 1.0,
        'title'       => 'Contorno Mandibular',
        'description' => 'Uma abordagem voltada ao equilíbrio e à definição do contorno facial.',
        'camera'      => ['frame' => 90, 'zoom' => 1.3, 'focalX' => 54, 'focalY' => 62],
        'text'        => ['side' => 'right', 'anchor' => 'middle'],
        'faceExclusion' => ['cx' => 52, 'cy' => 55, 'rx' => 30, 'ry' => 42],
        'hotspots'    => [
            ['x' => 62, 'y' => 66, 'label' => 'Linha mandibular'],
        ],
        'jawGuideLine'=> true,
        'interactiveDemo' => 'mandibular-contour',
        'pendingAsset'=> 'Animação ilustrativa de alteração do contorno mandibular — aguardando vídeo ou sequência de quadros apropriada.',
    ],
    [
        'id'          => 'queixo',
        'index'       => 4,
        'scrollWeight'=> 1.0,
        'title'       => 'Harmonização do Queixo',
        'description' => 'A proporção do queixo desempenha um papel importante no equilíbrio do perfil facial.',
        'camera'      => ['frame' => 78, 'zoom' => 1.5, 'focalX' => 52, 'focalY' => 72],
        'text'        => ['side' => 'right', 'anchor' => 'middle'],
        'faceExclusion' => ['cx' => 52, 'cy' => 65, 'rx' => 26, 'ry' => 28],
        'hotspots'    => [
            ['x' => 54, 'y' => 76, 'label' => 'Projeção do mento'],
        ],
        'interactiveDemo' => 'chin-projection',
        'pendingAsset'=> 'Animação ilustrativa de projeção/proporção do queixo — aguardando vídeo ou sequência de quadros apropriada.',
    ],
    [
        'id'          => 'perfiloplastia',
        'index'       => 5,
        'scrollWeight'=> 1.0,
        'title'       => 'Perfiloplastia',
        'description' => 'Uma análise integrada das proporções entre nariz, lábios e queixo para valorizar a harmonia do perfil.',
        'camera'      => ['frame' => 118, 'zoom' => 1.15, 'focalX' => 50, 'focalY' => 50],
        'text'        => ['side' => 'right', 'anchor' => 'middle'],
        'faceExclusion' => ['cx' => 46, 'cy' => 50, 'rx' => 34, 'ry' => 40],
        'hotspots'    => [
            ['x' => 40, 'y' => 46, 'label' => 'Linha de perfil'],
        ],
        'profileGuideLine' => true,
        'pendingAsset'=> null,
    ],
    [
        'id'          => 'toxina-botulinica',
        'index'       => 6,
        'scrollWeight'=> 1.0,
        'title'       => 'Toxina Botulínica',
        'description' => 'Tratamento que pode suavizar determinadas linhas de expressão, conforme avaliação profissional individualizada.',
        'camera'      => ['frame' => 60, 'zoom' => 1.45, 'focalX' => 50, 'focalY' => 30],
        'text'        => ['side' => 'left', 'anchor' => 'middle'],
        'faceExclusion' => ['cx' => 50, 'cy' => 32, 'rx' => 28, 'ry' => 33],
        'hotspots'    => [
            ['x' => 50, 'y' => 20, 'label' => 'Testa'],
            ['x' => 36, 'y' => 32, 'label' => 'Região periocular'],
        ],
        'interactiveDemo' => 'expression-lines',
        'pendingAsset'=> 'Animação educativa de expressão facial (terço superior) — aguardando vídeo ou sequência de quadros apropriada.',
    ],
    [
        'id'          => 'tercos-faciais',
        'index'       => 7,
        'scrollWeight'=> 1.0,
        'title'       => 'Terços Faciais',
        'description' => 'Explore a relação entre os terços superior, médio e inferior da face.',
        'camera'      => ['frame' => 60, 'zoom' => 0.52, 'focalX' => 50, 'focalY' => 46],
        'text'        => ['side' => 'center', 'anchor' => 'top'],
        'faceExclusion' => ['cx' => 50, 'cy' => 45, 'rx' => 30, 'ry' => 42],
        'hotspots'    => [
            ['x' => 50, 'y' => 18, 'label' => 'Terço superior'],
            ['x' => 50, 'y' => 48, 'label' => 'Terço médio'],
            ['x' => 50, 'y' => 76, 'label' => 'Terço inferior'],
        ],
        'pendingAsset'=> null,
    ],
    [
        'id'          => 'harmonizacao-global',
        'index'       => 8,
        'scrollWeight'=> 1.4,
        'title'       => 'Harmonização Global da Face',
        'description' => 'Uma visão integrada da estética facial, valorizando proporção, equilíbrio e naturalidade.',
        'camera'      => ['frameRange' => [1, 120], 'zoom' => 0.46, 'focalX' => 50, 'focalY' => 42],
        'text'        => ['side' => 'center', 'anchor' => 'bottom'],
        'faceExclusion' => ['cx' => 50, 'cy' => 46, 'rx' => 48, 'ry' => 48],
        'isClimax'    => true,
        'hotspots'    => [],
        'pendingAsset'=> null,
    ],
    [
        'id'          => 'agendamento',
        'index'       => 9,
        'scrollWeight'=> 0.8,
        'title'       => 'Sua beleza merece um olhar individualizado.',
        'description' => 'Agende sua avaliação e descubra as possibilidades para valorizar sua beleza com naturalidade.',
        'camera'      => ['frame' => 60, 'zoom' => 1.05, 'focalX' => 50, 'focalY' => 44],
        'text'        => ['side' => 'left', 'anchor' => 'middle'],
        'faceExclusion' => ['cx' => 50, 'cy' => 44, 'rx' => 40, 'ry' => 40],
        'isBooking'   => true,
        'ctaLabel'    => 'Agendar avaliação',
        'hotspots'    => [],
        'pendingAsset'=> null,
    ],
];
