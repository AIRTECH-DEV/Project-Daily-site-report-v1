<?php
require_once __DIR__ . '/PmsFpdf.php';

/**
 * Builds the site-report PDF, reproducing code.js buildDocAndExportPDF():
 * SITE REPORT title + date, project name, red rule, TODAY'S ACTIVITY box,
 * PROJECT DETAILS key/value table (with the same skip list + value colouring),
 * page break, DRAWING CHANGE PHOTO, then the SITE PHOTOS grid.
 *
 * Photos are embedded straight from the submission bytes (no Drive round-trip),
 * so the PDF is complete even before the Drive upload runs.
 */
class Pdf
{
    /* palette (matches code.js hex) */
    private $RED    = [208, 49, 45];
    private $DARK   = [26, 26, 26];
    private $GRAY   = [119, 119, 119];
    private $LGRAY  = [170, 170, 170];
    private $AMBER  = [200, 134, 10];
    private $GREEN  = [26, 122, 74];
    private $BGRAY  = [245, 245, 245];
    private $BORDER = [230, 230, 230];
    private $SOFT   = [255, 247, 247];
    private $FAINT  = [250, 250, 250];

    private $assetsDir;
    private $tmpFiles = [];

    public function __construct(string $assetsDir)
    {
        $this->assetsDir = rtrim($assetsDir, '/\\');
    }

    /**
     * @param array $ctx [
     *   project_name, timestamp (string), headers[], rowValues[],
     *   photos => [ ['bytes'=>bin,'mime'=>str], ... ],
     *   drawing => ['bytes'=>bin,'mime'=>str] | null,
     *   out_path => absolute pdf path
     * ]
     * @return string out_path
     */
    public function build(array $ctx): string
    {
        $pdf = new PmsFpdf('P', 'pt', 'A4');
        $pdf->headerImg = $this->assetsDir . '/letterhead_header.png';
        $pdf->footerImg = $this->assetsDir . '/footer_daikin.png';
        $pdf->SetMargins(42, 140, 42);
        $pdf->SetAutoPageBreak(true, 82);
        $pdf->AliasNbPages();
        $pdf->AddPage();

        $data = $this->assoc($ctx['headers'], $ctx['rowValues']);

        $this->titleBlock($pdf, $ctx['project_name'], $ctx['timestamp']);
        $this->redRule($pdf);
        $pdf->Ln(8);

        $this->sectionHeader($pdf, "TODAY'S ACTIVITY");
        $activity = $this->fmt($data["Today's Activity"] ?? '');
        if ($activity === 'N/A') { $activity = 'N/A'; }
        $this->activityBox($pdf, $activity);

        $pdf->Ln(8);
        $this->sectionHeader($pdf, 'PROJECT DETAILS');
        $this->detailTable($pdf, $ctx['headers'], $ctx['rowValues']);

        // Page 1 = activity + details only.
        $pdf->AddPage();

        if (!empty($ctx['drawing']['bytes'])) {
            $this->sectionHeader($pdf, 'DRAWING CHANGE PHOTO');
            $this->imageFit($pdf, $ctx['drawing'], 320, 220);
            $pdf->Ln(10);
        }

        $this->sectionHeader($pdf, 'SITE PHOTOS');
        $photos = $ctx['photos'] ?? [];
        if ($photos) {
            $this->photoGrid($pdf, $photos);
        } else {
            $pdf->SetFont('Arial', '', 11);
            $this->setText($pdf, $this->LGRAY);
            $pdf->Cell(0, 16, 'No photos attached.', 0, 1);
        }

        $pdf->Output('F', $ctx['out_path']);
        $this->cleanupTmp();
        return $ctx['out_path'];
    }

    /* ---------------- sections ---------------- */

    private function titleBlock(PmsFpdf $pdf, string $projectName, string $timestamp): void
    {
        $y0 = $pdf->GetY();
        // Left: "SITE REPORT"
        $pdf->SetXY(42, $y0);
        $pdf->SetFont('Arial', 'B', 22);
        $this->setText($pdf, $this->DARK);
        $pdf->Cell($pdf->GetStringWidth('SITE '), 26, 'SITE ', 0, 0);
        $this->setText($pdf, $this->RED);
        $pdf->Cell(120, 26, 'REPORT', 0, 0);
        // Right: formatted date/time
        $pdf->SetXY(363, $y0 + 6);
        $pdf->SetFont('Courier', 'B', 11);
        $this->setText($pdf, $this->AMBER);
        $pdf->Cell(190, 14, $this->prettyDate($timestamp), 0, 1, 'R');

        $pdf->SetXY(42, $y0 + 30);
        $pdf->SetFont('Arial', 'B', 10);
        $this->setText($pdf, $this->GRAY);
        $pdf->Cell(0, 12, $this->ascii($projectName !== '' ? $projectName : 'General_Reports'), 0, 1);
    }

    private function redRule(PmsFpdf $pdf): void
    {
        $y = $pdf->GetY() + 2;
        $pdf->SetFillColor(...$this->RED);
        $pdf->Rect(42, $y, 511, 2.2, 'F');
        $pdf->SetY($y + 2.2);
    }

    private function sectionHeader(PmsFpdf $pdf, string $text): void
    {
        $pdf->Ln(10);
        $pdf->SetFont('Arial', 'B', 9);
        $this->setText($pdf, $this->RED);
        $pdf->Cell(0, 11, $this->ascii($text), 0, 1);
        $pdf->Ln(3);
    }

    private function activityBox(PmsFpdf $pdf, string $text): void
    {
        $pdf->SetFont('Arial', '', 11);
        $pdf->SetDrawColor(...$this->BORDER);
        $pdf->SetFillColor(...$this->SOFT);
        $this->setText($pdf, $this->DARK);
        $x = 42; $y = $pdf->GetY();
        $lines = $pdf->NbLines(511, $this->ascii($text));
        $h = max(24, $lines * 14 + 12);
        if ($y + $h > $pdf->GetPageHeight() - 82) { $pdf->AddPage(); $y = $pdf->GetY(); }
        $pdf->Rect($x, $y, 511, $h, 'DF');
        $pdf->SetXY($x + 10, $y + 6);
        $pdf->MultiCell(491, 14, $this->ascii($text), 0, 'L');
        $pdf->SetY($y + $h);
    }

    private function detailTable(PmsFpdf $pdf, array $headers, array $rowValues): void
    {
        $rows = [];
        foreach ($headers as $i => $h) {
            if ($this->skipHeader((string)$h)) { continue; }
            $val = $this->fmt($rowValues[$i] ?? '');
            if (trim($val) === '') { $val = 'N/A'; }
            $rows[] = [strtoupper((string)$h), $val];
        }
        $kw = 180; $vw = 331; $pad = 5; $idx = 0;
        foreach ($rows as [$key, $val]) {
            $key = $this->ascii($key);
            $val = $this->ascii($val);
            $pdf->SetFont('Arial', 'B', 8);
            $kLines = $pdf->NbLines($kw, $key);
            $pdf->SetFont('Arial', '', 10);
            $vLines = $pdf->NbLines($vw, $val);
            $h = max($kLines * 10, $vLines * 13, 16) + 2 * $pad;

            $y = $pdf->GetY();
            if ($y + $h > $pdf->GetPageHeight() - 82) { $pdf->AddPage(); $y = $pdf->GetY(); }
            $x = 42;

            // key cell
            $pdf->SetFillColor(...$this->BGRAY);
            $pdf->SetDrawColor(...$this->BORDER);
            $pdf->Rect($x, $y, $kw, $h, 'DF');
            $pdf->SetXY($x + $pad, $y + $pad);
            $pdf->SetFont('Arial', 'B', 8);
            $this->setText($pdf, $this->GRAY);
            $pdf->MultiCell($kw - 2 * $pad, 10, $key, 0, 'L');

            // value cell
            $fill = ($idx % 2 === 0) ? [255, 255, 255] : $this->FAINT;
            $pdf->SetFillColor(...$fill);
            $pdf->Rect($x + $kw, $y, $vw, $h, 'DF');
            $pdf->SetXY($x + $kw + $pad, $y + $pad);
            $pdf->SetFont('Arial', '', 10);
            $this->setText($pdf, $this->valueColor($val));
            if ($this->isFlag($val)) { $pdf->SetFont('Arial', 'B', 10); }
            $pdf->MultiCell($vw - 2 * $pad, 13, $val, 0, 'L');

            $pdf->SetY($y + $h);
            $idx++;
        }
    }

    private function photoGrid(PmsFpdf $pdf, array $photos): void
    {
        $pdf->Ln(4);
        $col = 250; $gap = 11; $cellH = 182;
        for ($i = 0; $i < count($photos); $i += 2) {
            $y = $pdf->GetY();
            if ($y + $cellH > $pdf->GetPageHeight() - 82) { $pdf->AddPage(); $y = $pdf->GetY(); }
            $this->photoCell($pdf, $photos[$i], 42, $y, $col, $cellH);
            if (isset($photos[$i + 1])) {
                $this->photoCell($pdf, $photos[$i + 1], 42 + $col + $gap, $y, $col, $cellH);
            }
            $pdf->SetY($y + $cellH + 8);
        }
    }

    private function photoCell(PmsFpdf $pdf, array $photo, float $x, float $y, float $w, float $h): void
    {
        $pdf->SetFillColor(250, 250, 250);
        $pdf->SetDrawColor(...$this->BORDER);
        $pdf->Rect($x, $y, $w, $h, 'DF');
        $tmp = $this->tmpImage($photo);
        if ($tmp === null) { return; }
        [$iw, $ih, $type] = $tmp['dims'];
        $maxW = $w - 14; $maxH = $h - 14;
        $scale = min($maxW / $iw, $maxH / $ih, 1);
        $dw = $iw * $scale; $dh = $ih * $scale;
        $ix = $x + ($w - $dw) / 2; $iy = $y + ($h - $dh) / 2;
        $pdf->Image($tmp['path'], $ix, $iy, $dw, $dh, $type);
    }

    private function imageFit(PmsFpdf $pdf, array $photo, float $maxW, float $maxH): void
    {
        $tmp = $this->tmpImage($photo);
        if ($tmp === null) { return; }
        [$iw, $ih, $type] = $tmp['dims'];
        $scale = min($maxW / $iw, $maxH / $ih, 1);
        $pdf->Image($tmp['path'], $pdf->GetX(), $pdf->GetY(), $iw * $scale, $ih * $scale, $type);
        $pdf->SetY($pdf->GetY() + $ih * $scale);
    }

    /* ---------------- helpers ---------------- */

    private function assoc(array $headers, array $values): array
    {
        $out = [];
        foreach ($headers as $i => $h) {
            $out[$h] = $values[$i] ?? '';
        }
        return $out;
    }

    private function skipHeader(string $header): bool
    {
        $lower = strtolower($header);
        $patterns = [
            'email address', 'mail status', 'whatsapp status', 'pdf id', 'timestamp',
            "today's activity", 'if yes: upload the measurement report',
            'upload site photo', 'site photos', 'if yes :upload photo here',
            'if yes: upload photo here',
        ];
        foreach ($patterns as $p) {
            if (strpos($lower, $p) !== false) { return true; }
        }
        return false;
    }

    private function fmt($value): string
    {
        if ($value === null || $value === '' ) { return 'N/A'; }
        return (string)$value;
    }

    private function isFlag(string $val): bool
    {
        $v = strtolower(trim($val));
        return in_array($v, ['no', 'done', 'yes'], true);
    }

    private function valueColor(string $val): array
    {
        $v = strtolower(trim($val));
        if ($v === 'no' || $v === 'done') { return $this->GREEN; }
        if ($v === 'yes') { return $this->AMBER; }
        if ($v === 'n/a' || $v === '') { return $this->LGRAY; }
        return $this->DARK;
    }

    private function prettyDate(string $ts): string
    {
        if ($ts === '') { return ''; }
        $t = strtotime($ts);
        if ($t === false) { return $ts; }
        return date('j M Y | H:i', $t);
    }

    /** FPDF core fonts are latin-1; downgrade UTF-8 so text isn't garbled. */
    private function ascii(string $s): string
    {
        $conv = @iconv('UTF-8', 'windows-1252//TRANSLIT', $s);
        return $conv !== false ? $conv : $s;
    }

    private function setText(PmsFpdf $pdf, array $rgb): void
    {
        $pdf->SetTextColor($rgb[0], $rgb[1], $rgb[2]);
    }

    /** Writes photo bytes to a temp file FPDF can read; caches dims + type. */
    private function tmpImage(array $photo): ?array
    {
        $bytes = $photo['bytes'] ?? '';
        if ($bytes === '') { return null; }
        $info = @getimagesizefromstring($bytes);
        if ($info === false) { return null; }
        $mime = $info['mime'];
        $type = (strpos($mime, 'png') !== false) ? 'PNG'
              : ((strpos($mime, 'gif') !== false) ? 'GIF' : 'JPG');
        $ext = strtolower($type);
        $path = sys_get_temp_dir() . '/pmsimg_' . bin2hex(random_bytes(6)) . '.' . $ext;
        file_put_contents($path, $bytes);
        $this->tmpFiles[] = $path;
        return ['path' => $path, 'dims' => [$info[0], $info[1], $type]];
    }

    private function cleanupTmp(): void
    {
        foreach ($this->tmpFiles as $f) {
            @unlink($f);
        }
        $this->tmpFiles = [];
    }
}
