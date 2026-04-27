<?php

namespace App\Services\ISR;

use Illuminate\Support\Str;

class RemarkSpecExtractor
{
    /**
     * Alias ของแต่ละหัวข้อ (เพิ่มได้เรื่อย ๆ ตามของจริง)
     */
    protected array $aliases = [
        'size'         => ['ขนาด', 'dia', 'diameter', 'ø'],
        'length'       => ['ความยาว', 'length', 'l='],
        'tensile'      => ['tensile', 'uts', 'rm', 'แรงดึง'],
        'hardness'     => ['hardness', 'hv', 'h.v', 'ความแข็ง'],
        'elongation'   => ['elongation', 'el', 'e.l', 'ยืด', 'เปอร์เซ็นต์ยืด'],
        'roundness'    => ['roundness', 'ความกลม', 'ovality'],
        'straightness' => ['ความตรง', 'straightness'],
        'roughness'    => ['ra', 'roughness', 'ความเรียบผิว'],
        'magnetic'     => ['magnetic', 'megnetic', 'แม่เหล็ก', 'guass', 'gauss', 'g'],
        'chamfer'      => ['chamfer', 'c ', 'c1', 'c2', 'คม', 'ลบคม'],
        'scratch'      => ['รอย', 'scratch', 'ตำหนิ', 'หลุม', 'ตามด', 'สนิม'],
    ];

    /**
     * หน่วยที่พบบ่อย (ใช้เดา unit)
     */
    protected array $unitHints = [
        'size'     => ['mm', 'มม'],
        'length'   => ['mm', 'มม'],
        'tensile'  => ['n/mm2', 'n/mm²'],
        'hardness' => ['hv'],
        'roundness' => ['mm', 'มม'],
        'roughness' => ['ra', 'mm', 'มม'],
        'magnetic' => ['g', 'gauss', 'guass'],
        'elongation' => ['%'],
    ];

    /**
     * API หลัก: แยก spec หลายหัวข้อจาก remark
     */
    public function extractAll(string $remark): array
    {
        $norm = $this->normalize($remark);
        $chunks = $this->splitIntoChunks($norm);

        $out = [];
        foreach (array_keys($this->aliases) as $key) {
            $best = $this->extractOneFromChunks($chunks, $key);

            // ถ้าไม่เจอใน chunks ลองสแกนทั้งข้อความ (เผื่อเขียนติดกันยาว ๆ)
            if (!$best) {
                $best = $this->extractFromText($norm, $key);
            }

            if ($best) {
                $out[$key] = $best;
            }
        }

        return $out;
    }

    /**
     * API: ดึงหัวข้อเดียว
     */
    public function extractOne(string $remark, string $key): ?array
    {
        $key = trim(strtolower($key));
        if (!isset($this->aliases[$key])) return null;

        $norm = $this->normalize($remark);
        $chunks = $this->splitIntoChunks($norm);

        $best = $this->extractOneFromChunks($chunks, $key)
            ?? $this->extractFromText($norm, $key);

        return $best;
    }

    // ---------------------------------------------------------------------
    // Core pipeline
    // ---------------------------------------------------------------------

    protected function normalize(string $text): string
    {
        // normalize symbols
        $text = str_replace(
            ['：', '±', '＋', '－', '–', '—', '／', '㎜', 'Ｎ', 'Ｍ', '²'],
            [':', '+/-', '+', '-', '-', '-', '/', 'mm', 'N', 'M', '2'],
            $text
        );

        // normalize n/mm² -> n/mm2
        $text = preg_replace('/n\s*\/\s*mm\s*2/i', 'N/mm2', $text) ?? $text;
        $text = preg_replace('/n\s*\/\s*mm\s*\^?\s*2/i', 'N/mm2', $text) ?? $text;

        // remove number commas: 2,500 -> 2500
        $text = preg_replace('/(?<=\d),(?=\d)/', '', $text) ?? $text;

        // unify +/- form inside brackets: "(+/-100)" => "+/-100"
        $text = str_replace(['(+/-', '(+/- '], ['+/-', '+/-'], $text);
        $text = str_replace([')', '（', '）'], [')', '(', ')'], $text);

        // unify whitespace
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * split เป็น chunk ที่ parser ใช้ง่าย (รองรับ bullet, newline, (1), ;, |)
     */
    protected function splitIntoChunks(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = str_replace(['•', '●', '–', '—'], '-', $text);

        // แทรก newline ก่อน bullet "- " และ "(1)" "(2)"
        $text = preg_replace('/\s-\s+/', "\n- ", $text) ?? $text;
        $text = preg_replace('/\((\d+)\)\s*/', "\n($1) ", $text) ?? $text;

        // split ด้วย newline / ; / |
        $parts = preg_split('/[\n;\|]+/u', $text) ?: [];

        // trim และตัดของว่าง
        $parts = array_values(array_filter(array_map('trim', $parts), fn($v) => $v !== ''));

        return $parts;
    }

    protected function extractOneFromChunks(array $chunks, string $key): ?array
    {
        $best = null;

        foreach ($chunks as $chunk) {
            if (!$this->chunkMatchesKey($chunk, $key)) continue;

            $cand = $this->parseSpecFromChunk($chunk, $key);
            if (!$cand) continue;

            // เลือกอันที่ confidence สูงสุด
            if (!$best || ($cand['confidence'] ?? 0) > ($best['confidence'] ?? 0)) {
                $best = $cand;
            }
        }

        return $best;
    }

    protected function extractFromText(string $text, string $key): ?array
    {
        // หา segment หลัง keyword แบบ "ไม่ตัดวงเล็บ" (แก้ปัญหา 700 (+/-100))
        $segment = $this->extractSegmentByAliases($text, $this->aliases[$key], 220);
        if (!$segment) return null;

        return $this->parseSpecFromChunk($segment, $key);
    }

    protected function chunkMatchesKey(string $chunk, string $key): bool
    {
        foreach ($this->aliases[$key] as $a) {
            $a = trim($a);
            if ($a === '') continue;
            if (Str::contains(mb_strtolower($chunk), mb_strtolower($a))) {
                return true;
            }
        }
        return false;
    }

    /**
     * ดึง segment หลัง alias โดยไม่ตัดด้วย () เพื่อให้ (+/-) อยู่ครบ
     */
    protected function extractSegmentByAliases(string $text, array $aliases, int $maxLen = 160): ?string
    {
        $aliasPattern = implode('|', array_map(fn($s) => preg_quote($s, '/'), $aliases));
        if ($aliasPattern === '') return null;

        // จับ "alias : <content...>" หรือ "alias <content...>"
        $pattern = '/(?:^|[\[\(\s\|])(?:' . $aliasPattern . ')\s*(?:[:：\-–—]\s*)?(.{1,' . $maxLen . '})/iu';

        if (preg_match($pattern, $text, $m)) {
            return trim($m[0]); // เก็บ alias+content เพื่อ parse ง่าย
        }
        return null;
    }

    // ---------------------------------------------------------------------
    // Parsing: Pattern ladder
    // ---------------------------------------------------------------------

    protected function parseSpecFromChunk(string $chunk, string $key): ?array
    {
        $raw = trim($chunk);

        if ($key === 'roundness') {
            $anch = $this->parseAnchoredAfterKeyword($raw, ['ความกลม', 'roundness', 'ovality']);
            if ($anch) {
                return $this->buildResult(
                    $key,
                    $anch['std'],
                    $anch['lsl'],
                    $anch['usl'],
                    'mm',
                    $anch['confidence'],
                    $raw,
                    isset($anch['op']) ? ['op' => $anch['op']] : []
                );
            }
            if ($anch) {
                return $this->buildResult(
                    $key,
                    $anch['std'],
                    $anch['lsl'],
                    $anch['usl'],
                    'mm',
                    $anch['confidence'],
                    $raw,
                    isset($anch['op']) ? ['op' => $anch['op']] : []
                );
            }

            // ✅ สำคัญมาก: ถ้าไม่ match anchored → หยุดเลย
            return null;
        }

        $clean = mb_strtolower($raw);
        $clean = str_replace(['(', ')'], ' ', $clean);
        $clean = preg_replace('/\s+/', ' ', $clean) ?? $clean;

        // unit guess
        $unit = $this->guessUnit($raw, $key);

        // 1) explicit USL/LSL
        // ตัวอย่าง: usl 800 lsl 600 / upper 800 lower 600
        if (preg_match('/\b(?:usl|upper|max)\b\s*[:=]?\s*(\d+(?:\.\d+)?)\b.*\b(?:lsl|lower|min)\b\s*[:=]?\s*(\d+(?:\.\d+)?)\b/i', $raw, $m)) {
            $usl = (float)$m[1];
            $lsl = (float)$m[2];
            return $this->buildResult($key, null, $lsl, $usl, $unit, 0.95, $raw);
        }

        // 2) range A - B / A ~ B / A to B
        if (preg_match('/\b(\d+(?:\.\d+)?)\s*(?:-|~|to)\s*(\d+(?:\.\d+)?)\b/i', $clean, $m)) {
            $a = (float)$m[1];
            $b = (float)$m[2];
            $lsl = min($a, $b);
            $usl = max($a, $b);
            $std = ($lsl + $usl) / 2;
            return $this->buildResult($key, $std, $lsl, $usl, $unit, 0.90, $raw);
        }

        // 3) ± tolerance: 700 +/- 100
        if (preg_match('/\b(\d+(?:\.\d+)?)\s*\+\/-\s*(\d+(?:\.\d+)?)\b/i', $clean, $m)) {
            $std = (float)$m[1];
            $tol = (float)$m[2];
            return $this->buildResult($key, $std, $std - $tol, $std + $tol, $unit, 0.90, $raw);
        }

        // 4) +a/-b: 3.05 +0.008/-0.008
        if (preg_match('/\b(\d+(?:\.\d+)?)\s*\+(\d+(?:\.\d+)?)\s*\/\s*-(\d+(?:\.\d+)?)\b/i', $clean, $m)) {
            $std = (float)$m[1];
            $up  = (float)$m[2];
            $dn  = (float)$m[3];
            return $this->buildResult($key, $std, $std - $dn, $std + $up, $unit, 0.92, $raw);
        }

        // 5) inequality: ra < 0.70 / elongation > 8%
        if (preg_match('/\b(<=|>=|<|>)\s*(\d+(?:\.\d+)?)\b/i', $clean, $m)) {
            $op = $m[1];
            $v  = (float)$m[2];

            // เก็บเป็น usl/lsl ตาม operator
            if ($op === '<' || $op === '<=') {
                return $this->buildResult($key, null, null, $v, $unit, 0.75, $raw, ['op' => $op]);
            }
            return $this->buildResult($key, null, $v, null, $unit, 0.75, $raw, ['op' => $op]);
        }

        // 6) single numeric value: tensile 540 / hardness 170
        if (preg_match('/\b(\d+(?:\.\d+)?)\b/i', $clean, $m)) {
            $std = (float)$m[1];
            return $this->buildResult($key, $std, null, null, $unit, 0.55, $raw);
        }

        return null;
    }

    protected function guessUnit(string $raw, string $key): ?string
    {
        $low = mb_strtolower($raw);

        foreach (($this->unitHints[$key] ?? []) as $u) {
            if (Str::contains($low, mb_strtolower($u))) {
                // normalize unit presentation
                if (mb_strtolower($u) === 'มม') return 'mm';
                if (mb_strtolower($u) === 'n/mm²') return 'N/mm2';
                return $u;
            }
        }

        // special guesses
        if ($key === 'tensile') return 'N/mm2';
        if ($key === 'hardness') return 'HV';
        if ($key === 'elongation') return '%';
        if (in_array($key, ['size', 'length', 'roundness', 'roughness', 'straightness'], true)) return 'mm';

        return null;
    }

    protected function buildResult(
        string $key,
        ?float $std,
        ?float $lsl,
        ?float $usl,
        ?string $unit,
        float $confidence,
        string $raw,
        array $extra = []
    ): array {
        return array_merge([
            'key'        => $key,
            'std'        => $std,
            'lsl'        => $lsl,
            'usl'        => $usl,
            'unit'       => $unit,
            'confidence' => $confidence,
            'raw'        => $raw,
        ], $extra);
    }

    protected function parseAnchoredAfterKeyword(string $text, array $keywords): ?array
    {
        // จับเฉพาะเลขที่อยู่หลัง keyword เท่านั้น (กัน 10-15, 250, 500 โผล่มา)
        $kw = implode('|', array_map(fn($s) => preg_quote($s, '/'), $keywords));

        // รองรับ: "ความกลม 0.005", "ความกลม: 0.005", "ความกลม < 0.005"
        $pattern = '/(?:' . $kw . ')\s*[:：\-–—]?\s*([<>]=?|<=|>=)?\s*(\d+(?:\.\d+)?)/iu';

        if (!preg_match($pattern, $text, $m)) {
            return null;
        }

        $op = $m[1] ?? '';
        $v  = (float)$m[2];

        // ถ้าเป็น < / <= ให้เป็น USL, ถ้า > / >= ให้เป็น LSL, ถ้าไม่มีเครื่องหมายให้เป็น standard
        if ($op === '<' || $op === '<=') {
            return ['std' => null, 'lsl' => null, 'usl' => $v, 'confidence' => 0.98, 'op' => $op];
        }
        if ($op === '>' || $op === '>=') {
            return ['std' => null, 'lsl' => $v, 'usl' => null, 'confidence' => 0.98, 'op' => $op];
        }

        return ['std' => $v, 'lsl' => null, 'usl' => null, 'confidence' => 0.98];
    }
}
