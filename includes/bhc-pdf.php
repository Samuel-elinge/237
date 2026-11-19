<?php
/**
 * Business Health Check — PDF Report Generator (Phase 2, Stage 2)
 *
 * Pure-PHP PDF builder (no external library required).
 * Uses a minimal PDF 1.4 structure written from scratch.
 *
 * Usage:
 *   $pdfBytes = bhc_generate_pdf(
 *       $assessment,     // row from bhc_assessments
 *       $catScores,      // ['slug' => float weighted_score, ...]
 *       $catPcts,        // ['slug' => float 0-100, ...]
 *       $catBands,       // ['slug' => band_slug, ...]
 *       $allCategories,  // from bhc_load_categories()
 *       $recommendations,// ['slug' => [rec_row, ...], ...]
 *       $actionPlan,     // ['immediate'=>[], '30d'=>[], '60d'=>[], '90d'=>[]]
 *       $strengthSlugs,  // string[]
 *       $weaknessSlugs,  // string[]
 *       $lang            // 'en'|'fr'
 *   );
 *
 * Returns: string — raw PDF bytes.
 */

defined('IN_237BIZ') or die('Direct access denied');

require_once __DIR__ . '/bhc-config.php';

// ─────────────────────────────────────────────────────────────────────────────
//  Minimal PDF writer
// ─────────────────────────────────────────────────────────────────────────────

class BHC_PDF_Writer {

    private array  $objects  = [];
    private int    $nextId   = 1;
    private array  $pages    = [];
    private int    $pageId   = 0;
    private array  $streams  = [];
    private string $cur      = '';
    private array  $fonts    = [];
    private string $fontRes  = '';

    // A4 portrait (595 × 842 pts)
    private float $W = 595;
    private float $H = 842;

    // ── Colour helpers ────────────────────────────────────────────────────────
    private function rgb(int $r, int $g, int $b): string {
        return sprintf('%.3f %.3f %.3f', $r/255, $g/255, $b/255);
    }

    private function alloc(): int { return $this->nextId++; }

    private function obj(int $id, string $content): void {
        $this->objects[$id] = $content;
    }

    private function esc(string $s): string {
        $s = iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', (string)$s);
        return str_replace(['\\','(',')',"\r"], ['\\\\','\\(','\\)',''], $s);
    }

    // ── Font registration ─────────────────────────────────────────────────────
    public function addFont(string $name, string $pdfName): int {
        if (isset($this->fonts[$name])) return $this->fonts[$name];
        $id = $this->alloc();
        $this->obj($id,
            "$id 0 obj\n"
            . "<< /Type /Font /Subtype /Type1 /BaseFont /$pdfName\n"
            . "   /Encoding /WinAnsiEncoding >>\nendobj\n"
        );
        $this->fonts[$name] = $id;
        $this->fontRes .= "/$name $id 0 R ";
        return $id;
    }

    // ── Page management ───────────────────────────────────────────────────────
    public function newPage(): void {
        if ($this->cur !== '' && $this->pageId !== 0) {
            $this->streams[$this->pageId] = $this->cur;
        }
        $this->cur    = '';
        $this->pageId = $this->alloc();
        $this->pages[]= $this->pageId;
    }

    // ── Drawing primitives ────────────────────────────────────────────────────
    public function setFill(int $r, int $g, int $b): void {
        $this->cur .= $this->rgb($r,$g,$b) . " rg\n";
    }
    public function setStroke(int $r, int $g, int $b): void {
        $this->cur .= $this->rgb($r,$g,$b) . " RG\n";
    }
    public function setLineWidth(float $w): void {
        $this->cur .= "$w w\n";
    }
    public function rect(float $x, float $y, float $w, float $h, bool $fill=true, bool $stroke=false): void {
        // y = top-of-page coordinates; we convert here
        $py = $this->H - $y - $h;
        $op = $fill && $stroke ? 'B' : ($fill ? 'f' : 's');
        $this->cur .= sprintf("%.2f %.2f %.2f %.2f re %s\n", $x, $py, $w, $h, $op);
    }
    public function roundRect(float $x, float $y, float $w, float $h, float $rx): void {
        // Rounded rectangle using Bézier curves (k = 0.5523)
        $py = $this->H - $y - $h;
        $k  = 0.5523 * $rx;
        $this->cur .=
            sprintf("%.2f %.2f m\n", $x + $rx, $py) .
            sprintf("%.2f %.2f %.2f %.2f %.2f %.2f c\n", $x+$rx-$k,$py, $x,$py+$k, $x,$py+$rx) .
            sprintf("%.2f %.2f l\n", $x, $py + $h - $rx) .
            sprintf("%.2f %.2f %.2f %.2f %.2f %.2f c\n", $x,$py+$h-$rx+$k, $x+$rx-$k,$py+$h, $x+$rx,$py+$h) .
            sprintf("%.2f %.2f l\n", $x + $w - $rx, $py + $h) .
            sprintf("%.2f %.2f %.2f %.2f %.2f %.2f c\n", $x+$w-$rx+$k,$py+$h, $x+$w,$py+$h-$rx+$k, $x+$w,$py+$h-$rx) .
            sprintf("%.2f %.2f l\n", $x + $w, $py + $rx) .
            sprintf("%.2f %.2f %.2f %.2f %.2f %.2f c\n", $x+$w,$py+$rx-$k, $x+$w-$rx+$k,$py, $x+$w-$rx,$py) .
            "h f\n";
    }
    public function line(float $x1, float $y1, float $x2, float $y2): void {
        $py1 = $this->H - $y1;
        $py2 = $this->H - $y2;
        $this->cur .= sprintf("%.2f %.2f m %.2f %.2f l S\n", $x1,$py1,$x2,$py2);
    }

    // Bézier-approximated circle (filled)
    public function circle(float $cx, float $cy, float $r): void {
        $py = $this->H - $cy;
        $k  = 0.5523 * $r;
        $this->cur .=
            sprintf("%.2f %.2f m\n", $cx, $py - $r) .
            sprintf("%.2f %.2f %.2f %.2f %.2f %.2f c\n", $cx+$k,$py-$r, $cx+$r,$py-$k, $cx+$r,$py) .
            sprintf("%.2f %.2f %.2f %.2f %.2f %.2f c\n", $cx+$r,$py+$k, $cx+$k,$py+$r, $cx,$py+$r) .
            sprintf("%.2f %.2f %.2f %.2f %.2f %.2f c\n", $cx-$k,$py+$r, $cx-$r,$py+$k, $cx-$r,$py) .
            sprintf("%.2f %.2f %.2f %.2f %.2f %.2f c\n", $cx-$r,$py-$k, $cx-$k,$py-$r, $cx,$py-$r) .
            "h f\n";
    }

    // ── Text ──────────────────────────────────────────────────────────────────
    // All y coords are top-of-page (converted internally)
    public function text(string $font, float $size, float $x, float $y, string $text, int $r=0, int $g=0, int $b=0): void {
        $py  = $this->H - $y;
        $t   = $this->esc($text);
        $col = $this->rgb($r,$g,$b);
        $this->cur .=
            "BT\n/$font $size Tf\n$col rg\n"
            . sprintf("%.2f %.2f Td\n", $x, $py)
            . "($t) Tj\nET\n";
    }

    // Approximate text width
    public function textWidth(string $text, float $size): float {
        return strlen(iconv('UTF-8','ISO-8859-1//TRANSLIT//IGNORE', $text)) * $size * 0.52;
    }

    // Wrapped text — returns new Y (after last line + lineHeight)
    public function textWrapped(string $font, float $size, float $x, float $y, float $maxW, string $text, int $r=0, int $g=0, int $b=0, float $lineH=0): float {
        if ($lineH === 0.0) $lineH = $size * 1.4;
        $words = explode(' ', $text);
        $line  = '';
        $curY  = $y;
        $avChr = $size * 0.52;
        foreach ($words as $word) {
            $test = $line === '' ? $word : "$line $word";
            if (strlen(iconv('UTF-8','ISO-8859-1//TRANSLIT//IGNORE',$test)) * $avChr > $maxW && $line !== '') {
                $this->text($font, $size, $x, $curY, $line, $r, $g, $b);
                $line = $word;
                $curY += $lineH;
            } else {
                $line = $test;
            }
        }
        if ($line !== '') {
            $this->text($font, $size, $x, $curY, $line, $r, $g, $b);
            $curY += $lineH;
        }
        return $curY;
    }

    // Right-aligned text
    public function textRight(string $font, float $size, float $rightX, float $y, string $text, int $r=0, int $g=0, int $b=0): void {
        $tw = $this->textWidth($text, $size);
        $this->text($font, $size, $rightX - $tw, $y, $text, $r, $g, $b);
    }

    // Centred text within a box
    public function textCentre(string $font, float $size, float $boxX, float $boxW, float $y, string $text, int $r=0, int $g=0, int $b=0): void {
        $tw = $this->textWidth($text, $size);
        $this->text($font, $size, $boxX + ($boxW - $tw) / 2, $y, $text, $r, $g, $b);
    }

    // ── Finalise & output ─────────────────────────────────────────────────────
    public function output(): string {
        if ($this->cur !== '' && $this->pageId !== 0) {
            $this->streams[$this->pageId] = $this->cur;
        }
        if (empty($this->fonts)) {
            $this->addFont('F1','Helvetica-Bold');
            $this->addFont('F2','Helvetica');
            $this->addFont('F3','Helvetica-Oblique');
        }
        $fontResStr = '/Font << ' . $this->fontRes . '>>';

        $pagesKids = '';
        foreach ($this->pages as $pid) {
            $stream   = $this->streams[$pid] ?? '';
            $streamId = $this->alloc();
            $len      = strlen($stream);
            $this->obj($streamId,
                "$streamId 0 obj\n<< /Length $len >>\nstream\n$stream\nendstream\nendobj\n"
            );
            $this->obj($pid,
                "$pid 0 obj\n"
                . "<< /Type /Page /Parent 2 0 R\n"
                . "   /MediaBox [0 0 {$this->W} {$this->H}]\n"
                . "   /Resources << $fontResStr >>\n"
                . "   /Contents $streamId 0 R >>\nendobj\n"
            );
            $pagesKids .= "$pid 0 R ";
        }

        $this->obj(2, "2 0 obj\n<< /Type /Pages /Kids [$pagesKids] /Count " . count($this->pages) . " >>\nendobj\n");
        $this->obj(1, "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n");

        $body = "%PDF-1.4\n";
        $xref = [];
        ksort($this->objects);
        foreach ($this->objects as $id => $content) {
            $xref[$id] = strlen($body);
            $body .= $content;
        }

        $xrefOffset = strlen($body);
        $maxId      = max(array_keys($this->objects));
        $body .= "xref\n0 " . ($maxId + 1) . "\n";
        $body .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $maxId; $i++) {
            $body .= isset($xref[$i])
                ? sprintf("%010d 00000 n \n", $xref[$i])
                : "0000000000 65535 f \n";
        }
        $body .= "trailer\n<< /Size " . ($maxId + 1) . " /Root 1 0 R >>\n";
        $body .= "startxref\n$xrefOffset\n%%EOF\n";
        return $body;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
//  Private colour helpers
// ─────────────────────────────────────────────────────────────────────────────

function _bhc_hex2rgb(string $hex): array {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    return [hexdec(substr($hex,0,2)), hexdec(substr($hex,2,2)), hexdec(substr($hex,4,2))];
}

function _bhc_pct_rgb(float $pct): array {
    if ($pct >= 80) return [34,  197,  94];   // green
    if ($pct >= 60) return [59,  130, 246];   // blue
    if ($pct >= 40) return [245, 158,  11];   // amber
    return [239, 68, 68];                     // red
}

// ─────────────────────────────────────────────────────────────────────────────
//  Reusable layout helpers
// ─────────────────────────────────────────────────────────────────────────────

/** Draw the repeating mini-header used on pages 2+ */
function _bhc_mini_header(BHC_PDF_Writer $pdf, string $businessName, bool $isFr): void {
    $pdf->setFill(13, 17, 23);
    $pdf->rect(0, 0, 595, 44);
    $pdf->setFill(0, 168, 120);
    $pdf->rect(0, 44, 595, 3);
    $pdf->text('F1', 14, 36, 14, '237biz', 255, 255, 255);
    $sub = ($isFr ? 'Bilan de Sante — ' : 'Health Check — ')
         . iconv('UTF-8','ISO-8859-1//TRANSLIT//IGNORE', $businessName);
    $pdf->text('F2', 9, 120, 19, $sub, 160, 170, 180);
}

/** Draw a section heading with a green left-rule */
function _bhc_section_head(BHC_PDF_Writer $pdf, float $x, float $y, string $text): float {
    $pdf->setFill(0, 168, 120);
    $pdf->rect($x, $y, 4, 14);
    $pdf->text('F1', 12, $x + 10, $y + 2, $text, 30, 40, 50);
    return $y + 24;
}

/** Draw the page footer */
function _bhc_footer(BHC_PDF_Writer $pdf, bool $isFr): void {
    $pdf->setStroke(200, 210, 220);
    $pdf->setLineWidth(0.5);
    $pdf->line(36, 814, 559, 814);
    $lbl = $isFr
        ? '237biz — La plateforme numerique des entreprises camerounaises'
        : '237biz — The digital platform for Cameroonian businesses';
    $pdf->text('F2', 8, 36,  822, $lbl,                            120,130,140);
    $pdf->text('F2', 8, 36,  832, '237biz.net | privacy-policy',   150,160,170);
    $year = date('Y');
    $pdf->text('F2', 8, 450, 827, "(c) $year 237biz / MS IT Solutions", 150,160,170);
}

// ─────────────────────────────────────────────────────────────────────────────
//  Main generator
// ─────────────────────────────────────────────────────────────────────────────

/**
 * @param array  $assessment      Row from bhc_assessments
 * @param array  $catScores       ['slug' => float weighted_score, ...]
 * @param array  $catPcts         ['slug' => float 0-100, ...]
 * @param array  $catBands        ['slug' => band_slug, ...]
 * @param array  $allCategories   From bhc_load_categories()
 * @param array  $recommendations ['slug' => [rec_row, ...], ...]
 * @param array  $actionPlan      ['immediate'=>[], '30d'=>[], '60d'=>[], '90d'=>[]]
 * @param array  $strengthSlugs   string[]
 * @param array  $weaknessSlugs   string[]
 * @param string $lang            'en'|'fr'
 * @return string  Raw PDF bytes
 */
/**
 * Convenience wrapper: build PDF bytes for a completed assessment.
 * Loads all required data from the DB and returns raw PDF bytes.
 *
 * @param array $assessment  Row from bhc_assessments
 * @param PDO   $pdo
 * @return string  Raw PDF bytes
 */
function bhc_generate_pdf_for_assessment(array $assessment, \PDO $pdo): string
{
    $lang  = $assessment['lang'] ?? 'en';
    $isFr  = $lang === 'fr';

    $allCategories = bhc_load_categories();

    $catScores = [
        'online_visibility'    => (float)($assessment['score_online_visibility']    ?? 0),
        'customer_acquisition' => (float)($assessment['score_customer_acquisition'] ?? 0),
        'customer_trust'       => (float)($assessment['score_customer_trust']       ?? 0),
        'digital_presence'     => (float)($assessment['score_digital_presence']     ?? 0),
        'customer_management'  => (float)($assessment['score_customer_management']  ?? 0),
        'marketing_growth'     => (float)($assessment['score_marketing_growth']     ?? 0),
        'business_operations'  => (float)($assessment['score_business_operations']  ?? 0),
    ];

    $catPcts  = [];
    $catBands = [];
    foreach ($allCategories as $slug => $cat) {
        $weight = (float)($cat['weight'] ?? 0);
        $score  = $catScores[$slug] ?? 0.0;
        $pct    = $weight > 0 ? min(100.0, ($score / $weight) * 100.0) : 0.0;
        $catPcts[$slug]  = $pct;
        $catBands[$slug] = bhc_score_band($pct);
    }

    $sortedByPct = $catPcts; arsort($sortedByPct);
    $strengthSlugs = array_values(array_filter(
        array_keys(array_slice($sortedByPct, 0, 3, true)),
        fn($s) => $catPcts[$s] >= 60
    ));

    $sortedAsc = $catPcts; asort($sortedAsc);
    $weaknessSlugs = array_values(array_filter(
        array_keys(array_slice($sortedAsc, 0, 3, true)),
        fn($s) => $catPcts[$s] < 70
    ));

    $recommendations = [];
    try {
        foreach ($allCategories as $slug => $cat) {
            $catBand = $catBands[$slug];
            $rStmt = $pdo->prepare(
                "SELECT r.id, r.priority, r.title_en, r.title_fr, r.body_en, r.body_fr,
                        r.score_band, c.slug AS category_slug
                 FROM bhc_recommendations r
                 JOIN bhc_categories c ON c.id = r.category_id
                 WHERE c.slug = :slug AND r.score_band = :band AND r.is_active = 1
                 ORDER BY r.priority ASC LIMIT 2"
            );
            $rStmt->execute([':slug' => $slug, ':band' => $catBand]);
            foreach ($rStmt->fetchAll(\PDO::FETCH_ASSOC) as $rec) {
                $recommendations[$slug][] = $rec;
            }
        }
    } catch (\Exception $e) { /* table not yet seeded */ }

    if (empty($recommendations)) {
        $tmp = $catPcts; arsort($tmp);
        foreach (array_slice($tmp, 0, 4, true) as $slug => $pct) {
            if ($pct >= 80) continue;
            $cat = $allCategories[$slug] ?? [];
            $recommendations[$slug][] = [
                'category_slug' => $slug,
                'title_en' => 'Improve your ' . ($cat['label_en'] ?? $slug),
                'title_fr' => 'Améliorez votre ' . ($cat['label_fr'] ?? $slug),
                'body_en'  => 'Your score in this area indicates room for improvement.',
                'body_fr'  => 'Votre score dans ce domaine indique une marge d\'amélioration.',
            ];
        }
    }

    $actionPlan = ['immediate' => [], '30d' => [], '60d' => [], '90d' => []];
    foreach ($allCategories as $slug => $cat) {
        $pct  = $catPcts[$slug];
        $recs = $recommendations[$slug] ?? [];
        if (empty($recs)) continue;
        $entry = [
            'slug'  => $slug,
            'label' => $cat[$isFr ? 'label_fr' : 'label_en'] ?? $slug,
            'pct'   => $pct,
            'title' => $recs[0][$isFr ? 'title_fr' : 'title_en'] ?? '',
        ];
        if ($pct < 40)     $actionPlan['immediate'][] = $entry;
        elseif ($pct < 60) $actionPlan['30d'][] = $entry;
        elseif ($pct < 80) $actionPlan['60d'][] = $entry;
        else               $actionPlan['90d'][] = $entry;
    }

    return bhc_generate_pdf(
        $assessment, $catScores, $catPcts, $catBands, $allCategories,
        $recommendations, $actionPlan, $strengthSlugs, $weaknessSlugs, $lang
    );
}

function bhc_generate_pdf(
    array  $assessment,
    array  $catScores,
    array  $catPcts,
    array  $catBands,
    array  $allCategories,
    array  $recommendations,
    array  $actionPlan,
    array  $strengthSlugs,
    array  $weaknessSlugs,
    string $lang = 'en'
): string {

    $isFr  = $lang === 'fr';
    $total = (float)($assessment['total_score'] ?? 0);
    $band  = $assessment['score_band'] ?? bhc_score_band($total);
    $bLabel= bhc_band_label($band, $lang);
    [$bR,$bG,$bB] = _bhc_hex2rgb(bhc_band_color($band));

    $pdf = new BHC_PDF_Writer();
    $pdf->addFont('F1','Helvetica-Bold');
    $pdf->addFont('F2','Helvetica');
    $pdf->addFont('F3','Helvetica-Oblique');

    $bname   = $assessment['business_name'] ?? '';
    $contact = $assessment['contact_name']  ?? ($assessment['name'] ?? '');
    $ref     = $assessment['reference'] ?? '';
    $date    = date('d/m/Y', strtotime($assessment['created_at'] ?? 'now'));

    // ════════════════════════════════════════════════════════════════════════════
    //  PAGE 1 — Cover, Score, Category Performance
    // ════════════════════════════════════════════════════════════════════════════
    $pdf->newPage();

    // ── Dark header band (taller to fit business info) ───────────────────────
    $pdf->setFill(13, 17, 23);
    $pdf->rect(0, 0, 595, 200);

    // Green accent strip
    $pdf->setFill(0, 168, 120);
    $pdf->rect(0, 200, 595, 4);

    // ── Logo block: dark green bg pill, "237" in yellow, "Biz" in white ──────
    // Background pill
    $pdf->setFill(10, 40, 20);
    $pdf->roundRect(36, 20, 100, 36, 5);
    // "237" in yellow
    $pdf->text('F1', 20, 42, 46, '237', 245, 190, 0);
    // "Biz" in white
    $pdf->text('F1', 20, 86, 46, 'Biz', 255, 255, 255);
    // ".net" small below
    $pdf->text('F2', 7, 60, 60, '.net', 130, 160, 140);

    // Tagline beside logo
    $tagline = $isFr ? 'La plateforme numerique des entreprises camerounaises'
                     : 'The digital platform for Cameroonian businesses';
    $pdf->text('F2', 8, 148, 36, $tagline, 130, 155, 170);

    // Report title
    $rptTitle = $isFr ? 'BILAN DE SANTE ENTREPRISE' : 'BUSINESS HEALTH CHECK REPORT';
    $pdf->text('F1', 14, 36, 80, $rptTitle, 255, 255, 255);

    // ── Business info block ───────────────────────────────────────────────────
    $bizLabel  = $isFr ? 'Entreprise :' : 'Business Name:';
    $ctcLabel  = $isFr ? 'Contact :' : 'Business Contact:';
    $refLabel  = $isFr ? 'Ref :' : 'Reference:';
    $dateLabel = $isFr ? 'Date :' : 'Date:';

    $bnameClean   = iconv('UTF-8','ISO-8859-1//TRANSLIT//IGNORE', $bname);
    $contactClean = iconv('UTF-8','ISO-8859-1//TRANSLIT//IGNORE', $contact);

    // Label in muted, value in bright
    $pdf->text('F2',  9,  36, 105, $bizLabel,  130,145,160);
    $pdf->text('F1', 10, 130, 105, $bnameClean, 220,235,250);

    $pdf->text('F2',  9,  36, 122, $ctcLabel,  130,145,160);
    $pdf->text('F1', 10, 130, 122, $contactClean ?: '-', 220,235,250);

    $pdf->text('F2',  9,  36, 139, $refLabel,  130,145,160);
    $pdf->text('F2',  9, 130, 139, $ref,        180,195,210);

    $pdf->text('F2',  9, 280, 139, $dateLabel, 130,145,160);
    $pdf->text('F2',  9, 330, 139, $date,       180,195,210);

    // Personalisation note
    $noteText = $isFr
        ? 'Ce rapport est genere sur la base de vos reponses au questionnaire.'
        : 'This report is personalised based on the answers provided in your assessment.';
    $pdf->text('F3', 8, 36, 160, $noteText, 0, 168, 120);

    // Divider line inside header
    $pdf->setFill(40, 55, 70);
    $pdf->rect(36, 170, 523, 1);

    // Report date/ref compact line
    $refLine = ($isFr ? 'Ref : ' : 'Ref: ') . $ref . '   |   ' . ($isFr ? 'Date : ' : 'Date: ') . $date;
    $pdf->text('F2', 8, 36, 185, $refLine, 100,115,130);

    // ── Score circle ──────────────────────────────────────────────────────────
    $cx = 480; $cy = 108; $cr = 46;

    // Outer ring (band colour)
    $pdf->setFill($bR, $bG, $bB);
    $pdf->circle($cx, $cy, $cr + 4);

    // Inner fill
    $pdf->setFill(13, 17, 23);
    $pdf->circle($cx, $cy, $cr - 2);

    // Score text
    $scoreStr = (string)round($total);
    $tw = $pdf->textWidth($scoreStr, 22);
    $pdf->text('F1', 22, $cx - $tw/2, $cy - 10, $scoreStr, $bR, $bG, $bB);
    $pdf->textCentre('F2', 8, $cx - 20, 40, $cy + 7, '/100', 180, 195, 210);

    // Band label below circle
    $pdf->textCentre('F1', 9, $cx - 50, 100, $cy + 25, $bLabel, $bR, $bG, $bB);

    // ── Section: Performance by Category ─────────────────────────────────────
    $y = _bhc_section_head($pdf, 36, 222, $isFr ? 'Performance par categorie' : 'Performance by Category');

    foreach ($allCategories as $slug => $cat) {
        if ($y > 762) break;
        $pct  = $catPcts[$slug] ?? 0.0;
        [$cr2,$cg2,$cb2] = _bhc_pct_rgb($pct);
        $lbl = iconv('UTF-8','ISO-8859-1//TRANSLIT//IGNORE',
            $cat[$isFr ? 'label_fr' : 'label_en'] ?? $slug);

        $pdf->text('F2', 9, 36, $y, $lbl, 50,60,70);

        // Pct right-aligned
        $pctStr = round($pct) . '%';
        $pdf->textRight('F2', 9, 559, $y, $pctStr, $cr2,$cg2,$cb2);

        $y += 12;

        // Bar background
        $pdf->setFill(220, 225, 232);
        $pdf->rect(36, $y, 490, 7);

        // Bar fill
        $fw = max(2, $pct / 100 * 490);
        $pdf->setFill($cr2, $cg2, $cb2);
        $pdf->rect(36, $y, $fw, 7);

        $y += 15;
    }

    _bhc_footer($pdf, $isFr);

    // ════════════════════════════════════════════════════════════════════════════
    //  PAGE 2 — Strengths, Areas to Improve, Recommendations
    // ════════════════════════════════════════════════════════════════════════════
    $pdf->newPage();
    _bhc_mini_header($pdf, $bname, $isFr);
    $y = 62;

    // ── Strengths + Areas to Improve (side by side) ───────────────────────────
    if ($strengthSlugs || $weaknessSlugs) {
        $y = _bhc_section_head($pdf, 36, $y,
            $isFr ? 'Forces et domaines a ameliorer' : 'Key Strengths and Areas to Improve');

        $colW  = 240;
        $leftX = 36;
        $rightX= 310;
        $rowY  = $y;

        // ── Strengths column ─────────────────────────────────────────
        if ($strengthSlugs) {
            $pdf->setFill(230, 248, 238);
            $pdf->roundRect($leftX, $rowY, $colW, 20 + count($strengthSlugs) * 24, 5);
            $pdf->text('F1', 9, $leftX+8, $rowY+8, $isFr ? 'Points forts' : 'Key Strengths', 20,120,70);
            $iy = $rowY + 24;
            foreach ($strengthSlugs as $s) {
                if ($iy > 760 || !isset($allCategories[$s])) continue;
                $lbl = iconv('UTF-8','ISO-8859-1//TRANSLIT//IGNORE',
                    $allCategories[$s][$isFr ? 'label_fr' : 'label_en'] ?? $s);
                $pct = $catPcts[$s] ?? 0;
                [$cr3,$cg3,$cb3] = _bhc_pct_rgb($pct);
                $pdf->text('F2', 8, $leftX+16, $iy, chr(0xd7) . ' ' . $lbl, 30,80,50);
                $pdf->textRight('F1', 8, $leftX+$colW-8, $iy, round($pct).'%', $cr3,$cg3,$cb3);
                $iy += 22;
            }
        }

        // ── Weaknesses column ────────────────────────────────────────
        if ($weaknessSlugs) {
            $pdf->setFill(254, 243, 235);
            $pdf->roundRect($rightX, $rowY, $colW, 20 + count($weaknessSlugs) * 24, 5);
            $pdf->text('F1', 9, $rightX+8, $rowY+8, $isFr ? 'A ameliorer' : 'Areas to Improve', 180,80,20);
            $iy = $rowY + 24;
            foreach ($weaknessSlugs as $s) {
                if ($iy > 760 || !isset($allCategories[$s])) continue;
                $lbl = iconv('UTF-8','ISO-8859-1//TRANSLIT//IGNORE',
                    $allCategories[$s][$isFr ? 'label_fr' : 'label_en'] ?? $s);
                $pct = $catPcts[$s] ?? 0;
                [$cr3,$cg3,$cb3] = _bhc_pct_rgb($pct);
                $pdf->text('F2', 8, $rightX+16, $iy, chr(0xd7) . ' ' . $lbl, 160,60,20);
                $pdf->textRight('F1', 8, $rightX+$colW-8, $iy, round($pct).'%', $cr3,$cg3,$cb3);
                $iy += 22;
            }
        }

        $y = max(
            $strengthSlugs ? ($rowY + 20 + count($strengthSlugs) * 24 + 14) : $y,
            $weaknessSlugs ? ($rowY + 20 + count($weaknessSlugs) * 24 + 14) : $y
        );
    }

    // ── Recommendations ───────────────────────────────────────────────────────
    if (!empty($recommendations)) {
        $y = _bhc_section_head($pdf, 36, $y,
            $isFr ? 'Recommandations personnalisees' : 'Personalised Recommendations');

        // Priority labels
        $priorityLabels = [
            'high'   => [$isFr ? 'HAUTE PRIORITE' : 'HIGH PRIORITY',    220, 38,  38],
            'medium' => [$isFr ? 'PRIORITE MOYENNE' : 'MEDIUM PRIORITY',180, 90,  20],
            'low'    => [$isFr ? 'FAIBLE PRIORITE' : 'LOW PRIORITY',     40,120,  70],
        ];

        $cardH   = 56;
        $cardGap = 6;
        $printed = 0;

        foreach ($allCategories as $slug => $cat) {
            if (!isset($recommendations[$slug])) continue;
            $recs = $recommendations[$slug];
            $pct  = $catPcts[$slug] ?? 0;

            foreach (array_slice($recs, 0, 2) as $rec) {
                if ($y + $cardH > 800) {
                    // Start new page
                    _bhc_footer($pdf, $isFr);
                    $pdf->newPage();
                    _bhc_mini_header($pdf, $bname, $isFr);
                    $y = 62;
                }

                // Determine priority
                if ($pct < 40)       $pKey = 'high';
                elseif ($pct < 60)   $pKey = 'medium';
                elseif ($pct < 80)   $pKey = 'low';
                else                 continue; // already strong, skip

                [$pLabel, $pR, $pG, $pB] = $priorityLabels[$pKey];

                $catLabel = iconv('UTF-8','ISO-8859-1//TRANSLIT//IGNORE',
                    $cat[$isFr ? 'label_fr' : 'label_en'] ?? $slug);
                $recTitle = iconv('UTF-8','ISO-8859-1//TRANSLIT//IGNORE',
                    $rec[$isFr ? 'title_fr' : 'title_en'] ?? '');
                $recBody  = iconv('UTF-8','ISO-8859-1//TRANSLIT//IGNORE',
                    $rec[$isFr ? 'body_fr' : 'body_en'] ?? '');

                // Card background
                $pdf->setFill(246, 248, 251);
                $pdf->roundRect(36, $y, 523, $cardH, 4);

                // Priority dot
                [$cr3,$cg3,$cb3] = _bhc_pct_rgb($pct);
                $pdf->setFill($pR,$pG,$pB);
                $pdf->rect(36, $y, 4, $cardH);

                // Category + priority labels
                $pdf->text('F3', 8, 46, $y + 11, strtoupper($catLabel), 80,100,120);
                $pdf->textRight('F1', 7, 554, $y + 11, $pLabel, $pR,$pG,$pB);

                // Title
                $pdf->text('F1', 10, 46, $y + 24, $recTitle, 25,35,55);

                // Body (truncated to ~110 chars to fit one line)
                $bodyShort = strlen($recBody) > 110 ? substr($recBody,0,107).'...' : $recBody;
                $pdf->text('F2', 8, 46, $y + 37, $bodyShort, 80,90,110);

                $y += $cardH + $cardGap;
                $printed++;
                if ($printed >= 10) break 2; // cap at 10 recs in PDF
            }
        }
    }

    _bhc_footer($pdf, $isFr);

    // ════════════════════════════════════════════════════════════════════════════
    //  PAGE 3 — Business Improvement Action Plan + CTA
    // ════════════════════════════════════════════════════════════════════════════
    $pdf->newPage();
    _bhc_mini_header($pdf, $bname, $isFr);
    $y = 62;

    // ── Action Plan ───────────────────────────────────────────────────────────
    $y = _bhc_section_head($pdf, 36, $y,
        $isFr ? 'Plan d\'action amelioration' : 'Business Improvement Action Plan');

    $planBuckets = [
        'immediate' => [$isFr ? 'Actions immediates'       : 'Immediate Actions',    220, 38,  38],
        '30d'       => [$isFr ? 'Dans 30 jours'            : '30-Day Actions',       180, 90,  20],
        '60d'       => [$isFr ? 'Dans 60 jours'            : '60-Day Actions',        59,130, 246],
        '90d'       => [$isFr ? 'Dans 90 jours'            : '90-Day Actions',        34,120,  70],
    ];

    // Check if we have anything
    $planHasItems = false;
    foreach ($actionPlan as $bucket) {
        if (!empty($bucket)) { $planHasItems = true; break; }
    }

    if ($planHasItems) {
        // Header row
        $colW2  = 100;
        $rowH2  = 12;
        $hdrY   = $y;

        $pdf->setFill(50, 60, 80);
        $pdf->rect(36, $hdrY, 523, $rowH2 + 4);
        $pdf->text('F1', 8, 42,  $hdrY + 4, $isFr ? 'Periode'      : 'Timeframe', 220,230,240);
        $pdf->text('F1', 8, 155, $hdrY + 4, $isFr ? 'Domaine'      : 'Area',       220,230,240);
        $pdf->text('F1', 8, 310, $hdrY + 4, $isFr ? 'Action cle'   : 'Key Action', 220,230,240);
        $y = $hdrY + $rowH2 + 4;

        $rowFill = [[240,245,252],[248,250,255]]; // alternating rows
        $ri = 0;
        foreach ($planBuckets as $key => [$label, $bkR, $bkG, $bkB]) {
            foreach ($actionPlan[$key] ?? [] as $entry) {
                if ($y > 790) break 2;
                $rh = 18;
                [$fr,$fg,$fb] = $rowFill[$ri % 2];
                $pdf->setFill($fr,$fg,$fb);
                $pdf->rect(36, $y, 523, $rh);

                // Period
                $pdf->setFill($bkR,$bkG,$bkB);
                $pdf->rect(36, $y, 3, $rh);
                $pdf->text('F1', 8, 42, $y + 6, $label, $bkR,$bkG,$bkB);

                // Area
                $areaLbl = iconv('UTF-8','ISO-8859-1//TRANSLIT//IGNORE', $entry['label'] ?? '');
                $pdf->text('F2', 8, 155, $y + 6, $areaLbl, 50,60,70);

                // Key action
                $actionTxt = iconv('UTF-8','ISO-8859-1//TRANSLIT//IGNORE', $entry['title'] ?? '');
                if (strlen($actionTxt) > 55) $actionTxt = substr($actionTxt,0,52).'...';
                $pdf->text('F2', 8, 310, $y + 6, $actionTxt, 30,40,60);

                $y += $rh;
                $ri++;
            }
        }
        $y += 16;
    }

    // ── CTA box ───────────────────────────────────────────────────────────────
    if ($y < 760) {
        $y = _bhc_section_head($pdf, 36, $y,
            $isFr ? 'Passez a l\'action' : 'Take Action Today');

        $pdf->setFill(234, 244, 255);
        $pdf->roundRect(36, $y, 523, 90, 6);

        // CTA message based on score
        if ($total >= 80) {
            $ctaMsg = $isFr
                ? 'Excellente performance ! Continuez sur cette lancee et affinez votre strategie de croissance avec un expert 237biz.'
                : 'Outstanding performance! Keep the momentum and refine your growth strategy with a 237biz expert.';
        } elseif ($total >= 60) {
            $ctaMsg = $isFr
                ? 'Bonne base ! Quelques ameliorations ciblées peuvent propulser votre entreprise vers un niveau superieur.'
                : 'Good foundation! A few targeted improvements can take your business to the next level.';
        } elseif ($total >= 40) {
            $ctaMsg = $isFr
                ? 'Vous progressez. Agissez sur les domaines prioritaires pour accelerer votre croissance.'
                : 'You\'re making progress. Act on priority areas to accelerate your growth.';
        } else {
            $ctaMsg = $isFr
                ? 'Une attention immediate dans les domaines cles transformera rapidement vos resultats.'
                : 'Immediate attention in key areas will quickly transform your business results.';
        }
        $y = $pdf->textWrapped('F2', 10, 46, $y + 14, 500,
            iconv('UTF-8','ISO-8859-1//TRANSLIT//IGNORE', $ctaMsg), 30,40,70);

        $ctaLink = $isFr
            ? 'Visitez 237biz.net/contact pour parler avec un expert en croissance.'
            : 'Visit 237biz.net/contact to speak with a growth expert today.';
        $pdf->text('F1', 9, 46, $y + 6, $ctaLink, 0, 130, 100);
        $y += 30;
    }

    // ── How to request assistance ─────────────────────────────────────────────
    if ($y < 750) {
        $y += 10;
        $y = _bhc_section_head($pdf, 36, $y,
            $isFr ? 'Comment obtenir de l\'aide' : 'How to Request Assistance');

        $steps = $isFr ? [
            '1. Visitez 237biz.net et connectez-vous ou creez un compte.',
            '2. Accdez a votre tableau de bord et consultez vos recommandations.',
            '3. Demandez un service ou un devis aupres de nos prestataires verifies.',
            '4. Recevez des propositions et choisissez le bon partenaire.',
        ] : [
            '1. Visit 237biz.net and sign in or create a free account.',
            '2. Access your dashboard and review your personalised recommendations.',
            '3. Request a service or quotation from our verified service providers.',
            '4. Receive proposals and choose the right partner for your business.',
        ];
        foreach ($steps as $step) {
            if ($y > 795) break;
            $pdf->text('F2', 9, 46, $y, iconv('UTF-8','ISO-8859-1//TRANSLIT//IGNORE',$step), 50,60,80);
            $y += 15;
        }
    }

    _bhc_footer($pdf, $isFr);

    return $pdf->output();
}
