<?php
/**
 * Business Health Check — PDF Report Generator
 *
 * Pure-PHP PDF builder (no external library required).
 * Uses a minimal PDF 1.4 structure written from scratch.
 *
 * Usage:
 *   $pdfBytes = bhc_generate_pdf($assessment, $catScores, $allCategories, $recommendations, $lang);
 *
 * Returns: string — raw PDF bytes, or false on failure.
 */

defined('IN_237BIZ') or die('Direct access denied');

require_once __DIR__ . '/bhc-config.php';

// ─────────────────────────────────────────────────────────────────────────────
//  Minimal PDF writer
// ─────────────────────────────────────────────────────────────────────────────

class BHC_PDF_Writer {

    private array  $objects  = [];   // PDF indirect objects: [id => string]
    private int    $nextId   = 1;
    private array  $pages    = [];   // page object IDs
    private int    $pageId   = 0;    // current page object ID
    private array  $streams  = [];   // content stream for each page (indexed by pageId)
    private string $cur      = '';   // current page content buffer
    private array  $fonts    = [];   // registered fonts: name => obj id
    private string $fontRes  = '';   // /Font resource dict entries

    // Page dimensions (A4 in pts: 595×842)
    private float $W = 595;
    private float $H = 842;

    // ── Colour helpers (RGB 0-255 → PDF 0-1) ─────────────────────────────────
    private function rgb(int $r, int $g, int $b): string {
        return sprintf('%.3f %.3f %.3f', $r/255, $g/255, $b/255);
    }

    // ── Allocate an object ID ─────────────────────────────────────────────────
    private function alloc(): int { return $this->nextId++; }

    // ── Store a finalized object ──────────────────────────────────────────────
    private function obj(int $id, string $content): void {
        $this->objects[$id] = $content;
    }

    // ── PDF string escaping ───────────────────────────────────────────────────
    private function str(string $s): string {
        // We use UTF-16BE for safe Unicode via PDFDocEncoding workaround;
        // for simplicity, strip non-Latin1 and use literal strings.
        $s = iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $s);
        $s = str_replace(['\\','(',')',"\r"], ['\\\\','\\(','\\)',''], $s);
        return '(' . $s . ')';
    }

    // ── Encode text for PDF stream (safe Latin-1) ────────────────────────────
    private function esc(string $s): string {
        $s = iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', (string)$s);
        $s = str_replace(['\\','(',')',"\r"], ['\\\\','\\(','\\)',''], $s);
        return $s;
    }

    // ── Register a standard Type1 font ───────────────────────────────────────
    private function addFont(string $name, string $pdfName): int {
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

    // ── Start new page ────────────────────────────────────────────────────────
    public function newPage(): void {
        // Save previous page stream
        if ($this->cur !== '' && $this->pageId !== 0) {
            $this->streams[$this->pageId] = $this->cur;
        }
        $this->cur    = '';
        $this->pageId = $this->alloc();
        $this->pages[]= $this->pageId;
    }

    // ── Low-level stream commands ─────────────────────────────────────────────
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
        $op = $fill && $stroke ? 'B' : ($fill ? 'f' : 's');
        $this->cur .= sprintf("%.2f %.2f %.2f %.2f re %s\n", $x, $y, $w, $h, $op);
    }
    public function line(float $x1, float $y1, float $x2, float $y2): void {
        $this->cur .= sprintf("%.2f %.2f m %.2f %.2f l S\n", $x1,$y1,$x2,$y2);
    }

    // ── Text ──────────────────────────────────────────────────────────────────
    // y is from TOP in our API (we convert internally)
    private function yFlip(float $y): float { return $this->H - $y; }

    public function text(string $font, float $size, float $x, float $y, string $text, int $r=0, int $g=0, int $b=0): void {
        $py = $this->yFlip($y);
        $t  = $this->esc($text);
        $col= $this->rgb($r,$g,$b);
        $this->cur .=
            "BT\n"
            . "/$font $size Tf\n"
            . "$col rg\n"
            . sprintf("%.2f %.2f Td\n", $x, $py)
            . "($t) Tj\n"
            . "ET\n";
    }

    // Approximate text width (Helvetica/Times rough estimate)
    public function textWidth(string $text, float $size): float {
        // Average char width ≈ 0.5 × size for Helvetica
        return strlen(iconv('UTF-8','ISO-8859-1//TRANSLIT//IGNORE',$text)) * $size * 0.52;
    }

    // Wrapped text — returns new Y position
    public function textWrapped(string $font, float $size, float $x, float $y, float $maxW, string $text, int $r=0, int $g=0, int $b=0, float $lineH=0): float {
        if ($lineH === 0.0) $lineH = $size * 1.4;
        $words  = explode(' ', $text);
        $line   = '';
        $curY   = $y;
        $avgChr = $size * 0.52;
        foreach ($words as $word) {
            $test = $line === '' ? $word : "$line $word";
            if (strlen(iconv('UTF-8','ISO-8859-1//TRANSLIT//IGNORE',$test)) * $avgChr > $maxW && $line !== '') {
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

    // ── Build and return PDF bytes ────────────────────────────────────────────
    public function output(): string {
        // Save current page stream
        if ($this->cur !== '' && $this->pageId !== 0) {
            $this->streams[$this->pageId] = $this->cur;
        }

        // Font resources (register at least once)
        if (empty($this->fonts)) {
            $this->addFont('F1','Helvetica-Bold');
            $this->addFont('F2','Helvetica');
            $this->addFont('F3','Helvetica-Oblique');
        }
        $fontResStr = '/Font << ' . $this->fontRes . '>>';

        // Build page objects + content streams
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

        $pageCount = count($this->pages);

        // Pages dict (ID 2)
        $this->obj(2,
            "2 0 obj\n<< /Type /Pages /Kids [$pagesKids] /Count $pageCount >>\nendobj\n"
        );

        // Catalog (ID 1)
        $this->obj(1,
            "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
        );

        // Assemble body
        $body = "%PDF-1.4\n";
        $xref = [];
        // Sort by object ID
        ksort($this->objects);
        foreach ($this->objects as $id => $content) {
            $xref[$id] = strlen($body);
            $body .= $content;
        }

        // Cross-reference table
        $xrefOffset = strlen($body);
        $maxId      = max(array_keys($this->objects));
        $body .= "xref\n0 " . ($maxId + 1) . "\n";
        $body .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $maxId; $i++) {
            if (isset($xref[$i])) {
                $body .= sprintf("%010d 00000 n \n", $xref[$i]);
            } else {
                $body .= "0000000000 65535 f \n";
            }
        }

        $body .= "trailer\n<< /Size " . ($maxId + 1) . " /Root 1 0 R >>\n";
        $body .= "startxref\n$xrefOffset\n%%EOF\n";

        return $body;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
//  Main generator function
// ─────────────────────────────────────────────────────────────────────────────

/**
 * @param array $assessment  Row from bhc_assessments
 * @param array $catScores   ['slug' => float, ...]
 * @param array $allCategories  From bhc_load_categories()
 * @param array $recommendations  Array of rec rows
 * @param string $lang  'en' | 'fr'
 * @return string  Raw PDF bytes
 */
function bhc_generate_pdf(
    array $assessment,
    array $catScores,
    array $allCategories,
    array $recommendations,
    string $lang = 'en'
): string {

    $isFr  = $lang === 'fr';
    $total = (float)($assessment['total_score'] ?? 0);
    $band  = $assessment['score_band'] ?? bhc_score_band($total);
    $bLabel= bhc_band_label($band, $lang);
    $bHex  = bhc_band_color($band);       // e.g. '#38a169'
    [$bR, $bG, $bB] = _bhc_hex2rgb($bHex);

    $pdf = new BHC_PDF_Writer();

    // Register fonts
    $pdf->addFont('F1','Helvetica-Bold');
    $pdf->addFont('F2','Helvetica');
    $pdf->addFont('F3','Helvetica-Oblique');

    // ── PAGE 1 ──────────────────────────────────────────────────────────────
    $pdf->newPage();

    // Dark header band
    $pdf->setFill(13, 17, 23);       // #0d1117
    $pdf->rect(0, 0, 595, 170);      // top-of-page band (Y from bottom in PDF; we handle via yFlip)

    // 237biz brand bar (green accent strip)
    $pdf->setFill(0, 168, 120);      // #00A878
    $pdf->rect(0, 170, 595, 5);

    // Logo text "237biz" in header
    $pdf->text('F1', 26, 36, 46, '237biz', 255, 255, 255);

    // Tagline
    $t = $isFr ? 'La plateforme numerique des entreprises camerounaises'
                : 'The digital platform for Cameroonian businesses';
    $pdf->text('F2', 9, 36, 65, $t, 160, 170, 180);

    // Report title
    $title = $isFr ? 'BILAN DE SANTE ENTREPRISE' : 'BUSINESS HEALTH CHECK REPORT';
    $pdf->text('F1', 15, 36, 96, $title, 255, 255, 255);

    // Business name
    $bname = iconv('UTF-8','ISO-8859-1//TRANSLIT//IGNORE', $assessment['business_name'] ?? '');
    $pdf->text('F2', 11, 36, 118, $bname, 180, 190, 200);

    // Reference + date
    $ref  = $assessment['reference'] ?? '';
    $date = date('d/m/Y', strtotime($assessment['created_at'] ?? 'now'));
    $refLabel = ($isFr ? 'Ref: ' : 'Ref: ') . $ref . '   |   ' . ($isFr ? 'Date: ' : 'Date: ') . $date;
    $pdf->text('F2', 9, 36, 136, $refLabel, 140, 150, 160);

    // ── Score circle area ──────────────────────────────────────────────────
    // Draw a filled circle approximation using a square with radius label
    $cx = 480; $cy = 100; $r = 46;

    // Background circle (dark)
    $pdf->setFill(26, 31, 54);
    _bhc_circle($pdf, $cx, $cy, $r);

    // Score arc (simulated with a filled wedge — simplified: just a ring)
    // Draw band-coloured ring outline
    $pdf->setStroke($bR, $bG, $bB);
    $pdf->setLineWidth(8);
    _bhc_circle_stroke($pdf, $cx, $cy, $r - 6);
    $pdf->setLineWidth(1);

    // Score number
    $scoreStr = (string)round($total);
    $tw = strlen($scoreStr) * 13;
    $pdf->text('F1', 22, $cx - $tw/2, $cy - 10, $scoreStr, $bR, $bG, $bB);
    $pdf->text('F2', 9,  $cx - 10,    $cy + 8,  '/100',    180, 190, 200);

    // Band label pill below circle
    $pdf->text('F1', 10, $cx - strlen($bLabel)*3, $cy + 30, $bLabel, $bR, $bG, $bB);

    // ── Section: Category breakdown ────────────────────────────────────────
    $y = 198;
    $pdf->setFill(0, 168, 120);
    $pdf->rect(36, $y, 4, 14);
    $hdr = $isFr ? 'Performance par categorie' : 'Performance by Category';
    $pdf->text('F1', 12, 46, $y + 2, $hdr, 30, 40, 50);
    $y += 24;

    foreach ($allCategories as $slug => $cat) {
        $score  = (float)($catScores[$slug] ?? 0);
        $weight = (int)($cat['weight'] ?? 0);
        $pct    = $weight > 0 ? min(100, ($score / $weight) * 100) : 0;
        [$cr,$cg,$cb] = _bhc_pct_color($pct);

        $lbl = iconv('UTF-8','ISO-8859-1//TRANSLIT//IGNORE',
            $cat[$isFr ? 'label_fr' : 'label_en'] ?? $slug);

        // Label + score
        $pdf->text('F2', 9, 36,  $y, $lbl,  50, 60, 70);
        $valStr = round($score, 1) . '/' . $weight . 'pts';
        $pdf->text('F2', 9, 420, $y, $valStr, 100, 110, 120);

        // Bar background
        $y += 12;
        $pdf->setFill(220, 225, 230);
        $pdf->rect(36, $y, 380, 7);

        // Bar fill
        $fw = max(2, $pct / 100 * 380);
        $pdf->setFill($cr, $cg, $cb);
        $pdf->rect(36, $y, $fw, 7);

        $y += 16;

        if ($y > 760) { // page break guard
            break;
        }
    }

    // ── Section: Recommendations ───────────────────────────────────────────
    if ($recommendations && $y < 700) {
        $y += 10;
        $pdf->setFill(0, 168, 120);
        $pdf->rect(36, $y, 4, 14);
        $hdr2 = $isFr ? 'Recommandations prioritaires' : 'Priority Recommendations';
        $pdf->text('F1', 12, 46, $y + 2, $hdr2, 30, 40, 50);
        $y += 24;

        foreach (array_slice($recommendations, 0, 4) as $rec) {
            if ($y > 760) break;
            $catSlug  = $rec['category_slug'] ?? '';
            $catLabel = iconv('UTF-8','ISO-8859-1//TRANSLIT//IGNORE',
                $allCategories[$catSlug][$isFr ? 'label_fr' : 'label_en'] ?? $catSlug);
            $recTitle = iconv('UTF-8','ISO-8859-1//TRANSLIT//IGNORE',
                $rec[$isFr ? 'title_fr' : 'title_en'] ?? '');
            $recBody  = iconv('UTF-8','ISO-8859-1//TRANSLIT//IGNORE',
                $rec[$isFr ? 'body_fr' : 'body_en'] ?? '');

            // Rec card background
            $pdf->setFill(246, 248, 250);
            $pdf->rect(36, $y, 523, 50);

            // Category label (green)
            $pdf->text('F1', 8, 42, $y + 10, strtoupper($catLabel), 0, 150, 100);
            // Title
            $pdf->text('F1', 10, 42, $y + 22, $recTitle, 30, 40, 50);
            // Body (truncated)
            $bodyShort = substr($recBody, 0, 100);
            if (strlen($recBody) > 100) $bodyShort .= '...';
            $pdf->text('F2', 8, 42, $y + 35, $bodyShort, 80, 90, 100);

            $y += 58;
        }
    }

    // ── PAGE 2: Next steps + Footer ────────────────────────────────────────
    if ($y > 700 || $recommendations) {
        // If we ran out of space, add a second page for CTA
        $needPage2 = $y > 680;
    } else {
        $needPage2 = false;
    }

    if ($needPage2) {
        $pdf->newPage();
        $y = 60;

        // Repeat mini header
        $pdf->setFill(13, 17, 23);
        $pdf->rect(0, 0, 595, 44);
        $pdf->setFill(0, 168, 120);
        $pdf->rect(0, 44, 595, 3);
        $pdf->text('F1', 14, 36, 14, '237biz', 255, 255, 255);
        $bnameSh = iconv('UTF-8','ISO-8859-1//TRANSLIT//IGNORE', $assessment['business_name'] ?? '');
        $titleSh = $isFr ? 'Bilan de Sante — ' : 'Health Check — ';
        $pdf->text('F2', 9,  120, 18, $titleSh . $bnameSh, 160, 170, 180);
    }

    // ── CTA box ────────────────────────────────────────────────────────────
    $ctaY = $needPage2 ? 80 : $y + 20;
    if ($ctaY < 760) {
        $pdf->setFill(242, 247, 253);
        $pdf->rect(36, $ctaY, 523, 100);

        $ctaHdr = $isFr ? 'Passez a l\'action' : 'Take Action';
        $pdf->text('F1', 13, 46, $ctaY + 16, $ctaHdr, 20, 30, 50);

        if ($total >= 80) {
            $ctaMsg = $isFr
                ? 'Excellente performance ! Continuez et affinez votre strategie de croissance.'
                : 'Outstanding performance! Keep the momentum and refine your growth strategy.';
        } elseif ($total >= 60) {
            $ctaMsg = $isFr
                ? 'Bonne base ! Quelques ameliorations peuvent propulser votre entreprise.'
                : 'Good foundation! A few improvements can take your business further.';
        } elseif ($total >= 40) {
            $ctaMsg = $isFr
                ? 'Vous progressez. Agissez sur les domaines prioritaires pour accelerer.'
                : 'You\'re making progress. Act on priority areas to accelerate growth.';
        } else {
            $ctaMsg = $isFr
                ? 'Une attention immediate dans les domaines cles transformera vos resultats.'
                : 'Immediate attention in key areas will quickly transform your results.';
        }
        $ctaMsg = iconv('UTF-8','ISO-8859-1//TRANSLIT//IGNORE', $ctaMsg);
        $pdf->textWrapped('F2', 9, 46, $ctaY + 32, 500, $ctaMsg, 50, 60, 80);

        $ctaLink = 'Visit 237biz.net/contact to speak with a growth expert.';
        if ($isFr) $ctaLink = 'Visitez 237biz.net/contact pour parler avec un expert.';
        $pdf->text('F2', 9, 46, $ctaY + 72, $ctaLink, 0, 130, 100);
    }

    // ── Footer ─────────────────────────────────────────────────────────────
    // Separator line
    $pdf->setStroke(200, 210, 220);
    $pdf->setLineWidth(0.5);
    $pdf->line(36, 790, 559, 790);

    $footerLbl = $isFr
        ? '237biz — La plateforme numerique des entreprises camerounaises'
        : '237biz — The digital platform for Cameroonian businesses';
    $pdf->text('F2', 8, 36,  800, $footerLbl,         120, 130, 140);
    $pdf->text('F2', 8, 36,  812, 'https://237biz.net | privacy-policy', 150, 160, 170);
    $year = date('Y');
    $pdf->text('F2', 8, 450, 806, "(c) $year 237biz / MS IT Solutions", 150, 160, 170);

    return $pdf->output();
}

// ─────────────────────────────────────────────────────────────────────────────
//  Private helpers
// ─────────────────────────────────────────────────────────────────────────────

function _bhc_hex2rgb(string $hex): array {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    }
    return [hexdec(substr($hex,0,2)), hexdec(substr($hex,2,2)), hexdec(substr($hex,4,2))];
}

function _bhc_pct_color(float $pct): array {
    if ($pct >= 80) return [34, 197, 94];   // green
    if ($pct >= 60) return [59, 130, 246];  // blue
    if ($pct >= 40) return [245, 158, 11];  // amber
    return [239, 68, 68];                    // red
}

// Approximate a filled circle with a 36-segment polygon
function _bhc_circle(BHC_PDF_Writer $pdf, float $cx, float $cy, float $r): void {
    // We draw it via raw stream injection — but BHC_PDF_Writer doesn't expose that.
    // Use a square as background approximation (PDF circles need Bézier curves).
    // Draw a rounded rectangle approximation using 4 Bézier arcs.
    // For simplicity, draw a filled square with the same area.
    $side = $r * 2;
    $pdf->rect($cx - $r, $cy - $r, $side, $side);
}

function _bhc_circle_stroke(BHC_PDF_Writer $pdf, float $cx, float $cy, float $r): void {
    // Same approximation — draw a stroked square
    $side = $r * 2;
    $pdf->setFill(0, 0, 0); // transparent fill
    $pdf->rect($cx - $r, $cy - $r, $side, $side, false, true);
}
