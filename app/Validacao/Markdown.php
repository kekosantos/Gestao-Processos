<?php

declare(strict_types=1);

namespace App\Validacao;

final class Markdown
{
    public static function html(string $md): string
    {
        $linhas = preg_split('/\R/', str_replace("\t", '    ', $md));
        $saida = [];
        $para = [];
        $lista = null;
        $tabela = [];
        $codigo = null;
        $fechaPara = function () use (&$para, &$saida) { if ($para) { $saida[] = '<p>' . implode('<br>', array_map(fn ($x) => self::inline($x), $para)) . '</p>'; $para = []; } };
        $fechaLista = function () use (&$lista, &$saida) { if ($lista) { $saida[] = '<' . $lista[0] . '>' . implode('', $lista[1]) . '</' . $lista[0] . '>'; $lista = null; } };
        $fechaTabela = function () use (&$tabela, &$saida) {
            if (!$tabela) return;
            $h = '<div class="vk-tabela"><table>';
            foreach ($tabela as $i => $l) {
                if ($i === 1 && preg_match('/^\|?\s*:?-{2,}/', $l)) continue;
                $cels = array_map('trim', explode('|', trim(trim($l), '|')));
                $tag = $i === 0 ? 'th' : 'td';
                $h .= '<tr>' . implode('', array_map(fn ($c) => "<{$tag}>" . self::inline($c) . "</{$tag}>", $cels)) . '</tr>';
            }
            $saida[] = $h . '</table></div>';
            $tabela = [];
        };
        foreach ($linhas as $l) {
            if ($codigo !== null) {
                if (str_starts_with(trim($l), '```')) { $saida[] = '<pre><code>' . htmlspecialchars(implode("\n", $codigo), ENT_QUOTES, 'UTF-8') . '</code></pre>'; $codigo = null; }
                else $codigo[] = $l;
                continue;
            }
            $t = trim($l);
            if (str_starts_with($t, '```')) { $fechaPara(); $fechaLista(); $fechaTabela(); $codigo = []; continue; }
            if ($t === '') { $fechaPara(); $fechaLista(); $fechaTabela(); continue; }
            if (str_starts_with($t, '|')) { $fechaPara(); $fechaLista(); $tabela[] = $t; continue; }
            $fechaTabela();
            if (preg_match('/^(#{1,4})\s+(.*)$/', $t, $m)) {
                $fechaPara(); $fechaLista();
                $n = strlen($m[1]);
                $id = self::ancora($m[2]);
                $saida[] = "<h{$n} id=\"{$id}\">" . self::inline($m[2]) . "</h{$n}>";
                continue;
            }
            if (preg_match('/^(-{3,}|\*{3,})$/', $t)) { $fechaPara(); $fechaLista(); $saida[] = '<hr>'; continue; }
            if (str_starts_with($t, '>')) { $fechaPara(); $fechaLista(); $saida[] = '<blockquote>' . self::inline(ltrim(substr($t, 1))) . '</blockquote>'; continue; }
            if (preg_match('/^[-*]\s+(.*)$/', $t, $m) || preg_match('/^\d+[.)]\s+(.*)$/', $t, $m2)) {
                $fechaPara();
                $tipo = isset($m2) && $m2 ? 'ol' : 'ul';
                $texto = $m[1] ?? $m2[1];
                if (!$lista || $lista[0] !== $tipo) { $fechaLista(); $lista = [$tipo, []]; }
                $texto = preg_replace('/^\[( |x)\]\s*/i', '<span class="vk-check">☐</span> ', $texto);
                $lista[1][] = '<li>' . self::inline($texto, true) . '</li>';
                unset($m2);
                continue;
            }
            unset($m2);
            $fechaLista();
            $para[] = $t;
        }
        if ($codigo !== null) $saida[] = '<pre><code>' . htmlspecialchars(implode("\n", $codigo), ENT_QUOTES, 'UTF-8') . '</code></pre>';
        $fechaPara(); $fechaLista(); $fechaTabela();
        return implode("\n", $saida);
    }

    public static function ancora(string $t): string
    {
        $t = mb_strtolower(strip_tags($t));
        $t = strtr($t, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c']);
        return trim(preg_replace('/[^a-z0-9]+/', '-', $t), '-');
    }

    public static function inline(string $t, bool $html = false): string
    {
        $marcas = [];
        if ($html) $t = preg_replace_callback('/<span class="vk-check">☐<\/span>/', function ($m) use (&$marcas) { $marcas[] = $m[0]; return "\x01" . (count($marcas) - 1) . "\x01"; }, $t);
        $t = htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
        $t = preg_replace_callback('/`([^`]+)`/', fn ($m) => '<code>' . $m[1] . '</code>', $t);
        $t = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $t);
        $t = preg_replace('/(?<![*\w])\*([^*]+)\*(?![*\w])/', '<em>$1</em>', $t);
        $t = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function ($m) {
            $url = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
            if (!preg_match('#^(https?://|/|\#|mailto:)#', $url)) return $m[1];
            $ext = str_starts_with($url, 'http') ? ' target="_blank" rel="noopener"' : '';
            return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"' . $ext . '>' . $m[1] . '</a>';
        }, $t);
        if ($html) $t = preg_replace_callback("/\x01(\d+)\x01/", fn ($m) => $marcas[(int) $m[1]], $t);
        return $t;
    }
}
