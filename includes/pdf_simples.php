<?php
/**
 * Gerador de PDF mínimo, sem dependências (PDF 1.4, A4, fontes padrão
 * Helvetica/Helvetica-Bold com WinAnsiEncoding — acentos do português
 * funcionam). Só o necessário para os relatórios: texto, retângulos,
 * linhas, cores, quebra de página e numeração. Coordenadas em pontos,
 * com y medido DE CIMA para baixo (como na tela).
 */
final class PdfSimples
{
    public float $largura = 595.28;
    public float $altura = 841.89;
    private array $paginas = [];
    private int $atual = -1;
    private bool $negrito = false;
    private float $tamanho = 10;
    /** @var callable|null fn(PdfSimples $pdf, int $pagina, int $total) */
    public $rodape = null;

    // Larguras (1/1000 em) da Helvetica e Helvetica-Bold para ASCII 32–126.
    private const W = [278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,
        667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,
        556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584];
    private const WB = [278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,
        722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,
        556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584];

    public function novaPagina(): void
    {
        $this->paginas[] = '';
        $this->atual = count($this->paginas) - 1;
    }

    public function paginaAtual(): int
    {
        return $this->atual + 1;
    }

    public function fonte(bool $negrito, float $tamanho): void
    {
        $this->negrito = $negrito;
        $this->tamanho = $tamanho;
    }

    /** UTF-8 → Windows-1252 (o que não existe vira algo parecido: → vira ->). */
    public static function cp1252(string $s): string
    {
        $s = strtr($s, ['→' => '->', '≥' => '>=', '≤' => '<=', '×' => 'x', '…' => '...', '−' => '-']);
        $r = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $s);
        return $r === false ? preg_replace('/[^\x20-\x7E]/', '?', $s) : $r;
    }

    /** Largura do texto (UTF-8) em pontos, na fonte indicada (ou na atual). */
    public function larguraTexto(string $t, ?bool $negrito = null, ?float $tamanho = null): float
    {
        $neg = $negrito ?? $this->negrito;
        $tab = $neg ? self::WB : self::W;
        $soma = 0;
        $b = self::cp1252($t);
        for ($i = 0, $n = strlen($b); $i < $n; $i++) {
            $c = ord($b[$i]);
            if ($c >= 32 && $c <= 126) {
                $soma += $tab[$c - 32];
            } else {
                // Acentuadas: largura da letra base (á → a); demais, média.
                $base = @iconv('Windows-1252', 'ASCII//TRANSLIT//IGNORE', $b[$i]);
                $o = $base !== false && $base !== '' ? ord($base[0]) : 0;
                $soma += ($o >= 32 && $o <= 126) ? $tab[$o - 32] : 556;
            }
        }
        return $soma * ($tamanho ?? $this->tamanho) / 1000;
    }

    /** Corta com "…" para caber em $max pontos. */
    public function caber(string $t, float $max): string
    {
        if ($this->larguraTexto($t) <= $max) {
            return $t;
        }
        while ($t !== '' && $this->larguraTexto($t . '…') > $max) {
            $t = mb_substr($t, 0, -1);
        }
        return rtrim($t) . '…';
    }

    /** Quebra em linhas que cabem em $max pontos. */
    public function quebrar(string $t, float $max): array
    {
        $linhas = [];
        foreach (preg_split('/\R/', $t) as $par) {
            $atual = '';
            foreach (preg_split('/\s+/', trim($par)) as $p) {
                $teste = $atual === '' ? $p : $atual . ' ' . $p;
                if ($this->larguraTexto($teste) <= $max || $atual === '') {
                    $atual = $teste;
                } else {
                    $linhas[] = $atual;
                    $atual = $p;
                }
            }
            $linhas[] = $atual;
        }
        return array_map(fn ($l) => $this->larguraTexto($l) > $max ? $this->caber($l, $max) : $l, $linhas);
    }

    private function cor(array $rgb): string
    {
        return sprintf('%.3F %.3F %.3F', $rgb[0] / 255, $rgb[1] / 255, $rgb[2] / 255);
    }

    /** Texto com a linha de base em $y (de cima). $alinhar: E | D | C. */
    public function texto(float $x, float $y, string $t, array $cor = [21, 26, 45], string $alinhar = 'E'): void
    {
        if ($t === '') {
            return;
        }
        if ($alinhar !== 'E') {
            $w = $this->larguraTexto($t);
            $x -= $alinhar === 'D' ? $w : $w / 2;
        }
        $s = str_replace(['\\', '(', ')', "\r"], ['\\\\', '\\(', '\\)', ''], self::cp1252($t));
        $this->paginas[$this->atual] .= sprintf("BT %s rg /%s %.2F Tf %.2F %.2F Td (%s) Tj ET\n", $this->cor($cor), $this->negrito ? 'F2' : 'F1', $this->tamanho, $x, $this->altura - $y, $s);
    }

    public function retangulo(float $x, float $y, float $w, float $h, array $preenchimento, ?array $borda = null): void
    {
        $op = $borda ? 'B' : 'f';
        $this->paginas[$this->atual] .= sprintf("%s rg %s %.2F %.2F %.2F %.2F re %s\n", $this->cor($preenchimento), $borda ? $this->cor($borda) . ' RG 0.6 w' : '', $x, $this->altura - $y - $h, $w, $h, $op);
    }

    public function linha(float $x1, float $y1, float $x2, float $y2, array $cor = [213, 217, 230], float $espessura = 0.6): void
    {
        $this->paginas[$this->atual] .= sprintf("%s RG %.2F w %.2F %.2F m %.2F %.2F l S\n", $this->cor($cor), $espessura, $x1, $this->altura - $y1, $x2, $this->altura - $y2);
    }

    /** Monta o arquivo PDF (string). */
    public function saida(): string
    {
        $total = count($this->paginas);
        if ($this->rodape) {
            foreach (array_keys($this->paginas) as $i) {
                $this->atual = $i;
                ($this->rodape)($this, $i + 1, $total);
            }
        }
        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $kids = [];
        $n = 5;
        foreach ($this->paginas as $conteudo) {
            $dados = function_exists('gzcompress') ? gzcompress($conteudo, 6) : $conteudo;
            $objs[$n] = '<< /Length ' . strlen($dados) . (function_exists('gzcompress') ? ' /Filter /FlateDecode' : '') . " >>\nstream\n" . $dados . "\nendstream";
            $objs[$n + 1] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>', $this->largura, $this->altura, $n);
            $kids[] = ($n + 1) . ' 0 R';
            $n += 2;
        }
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $total . ' >>';
        $objs[$n] = '<< /Producer (WeMart Sales) /CreationDate (D:' . date('YmdHis') . ') >>';
        ksort($objs);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objs as $id => $o) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $o . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . ($n + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $n; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        return $pdf . "trailer\n<< /Size " . ($n + 1) . " /Root 1 0 R /Info $n 0 R >>\nstartxref\n$xref\n%%EOF\n";
    }
}
