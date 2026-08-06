<?php
require_once __DIR__ . '/PmsFpdf.php';

/** Neutral report chrome. Keeps letterhead, removes red footer treatment. */
class PreCommissioningFpdf extends PmsFpdf
{
    public function Footer()
    {
        $this->SetDrawColor(198, 207, 216);
        $this->SetLineWidth(0.6);
        $this->Line(42, 800, 553, 800);
        if ($this->footerImg && is_file($this->footerImg)) {
            $this->Image($this->footerImg, 42, 806, 82, 0, 'PNG');
        }
        $this->SetY(-31);
        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(112, 125, 139);
        $this->Cell(0, 10, 'Pre-Commissioning Report  |  Page ' . $this->PageNo(), 0, 0, 'R');
    }
}

/** Builds attached pre-commissioning PDF from browser form data. */
class PreCommissioningPdf
{
    private string $assets;
    public function __construct(string $assets) { $this->assets = rtrim($assets, '/\\'); }

    public function build(array $r, string $out): string
    {
        $pdf = new PreCommissioningFpdf('P', 'pt', 'A4');
        $pdf->headerImg = $this->assets . '/letterhead_header.png';
        $pdf->footerImg = $this->assets . '/footer_daikin.png';
        $pdf->SetMargins(42, 130, 42);
        $pdf->SetAutoPageBreak(true, 78);
        $pdf->AddPage();
        $this->title($pdf, 'PRE-COMMISSIONING REPORT');
        $this->pairs($pdf, [
            ['Date of Commissioning', $this->dateText($r['date'] ?? '')], ['System / Capacity', $r['system'] ?? ''],
            ['Customer Name', $r['customer'] ?? ''], ['Contact Person', $r['contact'] ?? ''],
            ['Address / Project', $r['address'] ?? ''], ['Email', $r['email'] ?? ''],
            ['Service Engineer', $r['engineer'] ?? ''],
        ]);
        $pdf->Ln(18);
        $this->section($pdf, 'COMMISSIONING EQUIPMENT SCHEDULE');
        $pdf->Ln(5);
        // Total = 511pt, exactly matching all full-width blocks between the 42pt margins.
        $widths = [28, 105, 82, 118, 82, 96];
        $aligns = ['C', 'L', 'L', 'L', 'L', 'C'];
        $this->row($pdf, ['#', 'Model No.', 'Serial No.', 'System Name', 'Invoice No.', 'Date'], $widths, true, $aligns);
        foreach ((array)($r['units'] ?? []) as $i => $u) {
            $this->row($pdf, [$i + 1, $u['model'] ?? '', $u['serial'] ?? '', $u['systemName'] ?? '', $u['invoice'] ?? '', $this->dateText($u['invoiceDate'] ?? '')], $widths, false, $aligns);
        }
        $pdf->Ln(14);
        $this->pairs($pdf, [['Refrigerant amount', $r['refrigerant'] ?? ''], ['Findings', $r['findings'] ?? '']]);
        $pdf->Ln(46);
        $this->signatureRow($pdf, ['Service Engineer - Dealer', 'Service Engineer - Daikin']);
        $pdf->Ln(44);
        $this->signatureRow($pdf, ['Customer - Signature & Stamp', 'Consultant - Signature']);

        $pdf->AddPage();
        $this->title($pdf, 'PRESSURE TESTING REPORT');
        $pw = [62, 48, 78, 62, 67, 82, 112];
        $this->row($pdf, ['Reading', 'Type', 'Date', 'Time', 'Temp. C', 'Pressure (PSI)', 'Remarks'], $pw, true, [], 21);
        foreach ((array)($r['pressure'] ?? []) as $p) {
            $this->row($pdf, [$p['reading'] ?? '', $p['type'] ?? '', $this->dateText($p['date'] ?? ''), $p['time'] ?? '', $p['temp'] ?? '', $p['psi'] ?? '', $p['remarks'] ?? ''], $pw, false, [], 21);
        }
        $pdf->Ln(15);
        $this->section($pdf, 'ADDITIONAL REFRIGERANT GAS QUANTITY');
        $pdf->Ln(5);
        $this->pairs($pdf, [['System / Capacity', $r['gasSystem'] ?? ($r['system'] ?? '')]]);
        $pdf->Ln(10);
        $odus = !empty($r['odus']) && is_array($r['odus'])
            ? $r['odus']
            : [['model' => $r['oduModel'] ?? '', 'serial' => $r['oduSerial'] ?? '']];
        $ow = [35, 238, 238];
        $this->row($pdf, ['#', 'ODU Model', 'ODU Serial No.'], $ow, true, ['C', 'L', 'L'], 21);
        foreach ($odus as $i => $odu) {
            $this->row($pdf, [$i + 1, $odu['model'] ?? '', $odu['serial'] ?? ''], $ow, false, ['C', 'L', 'L'], 21);
        }
        $pdf->Ln(10);
        $gw = [128, 128, 128, 127];
        $this->row($pdf, ['Liquid Pipe Size (mm)', 'Length (Rmt)', 'Factor (kg/m)', 'Quantity (kg)'], $gw, true, [], 21);
        $calculatedLength = 0.0; $calculatedQuantity = 0.0;
        foreach ((array)($r['pipes'] ?? []) as $p) {
            $calculatedLength += (float)($p['length'] ?? 0);
            $calculatedQuantity += (float)($p['quantity'] ?? 0);
            $this->row($pdf, [$p['size'] ?? '', $p['length'] ?? '', $p['factor'] ?? '', number_format((float)($p['quantity'] ?? 0), 2)], $gw, false, [], 21);
        }
        $totalLength = (float)($r['totalLength'] ?? $calculatedLength);
        $pipeSubtotal = (float)($r['pipeSubtotal'] ?? $calculatedQuantity);
        $this->row($pdf, ['Total pipe length', number_format($totalLength, 2) . ' Rmt', 'Pipe quantity subtotal', number_format($pipeSubtotal, 2) . ' Kg'], $gw, true, [], 21);
        $this->row($pdf, ['', '', 'Table A addition', number_format((float)($r['tableA'] ?? 0), 2)], $gw, true, [], 21);
        $this->row($pdf, ['', '', 'Total additional', number_format((float)($r['totalGas'] ?? 0), 2) . ' Kg'], $gw, true, [], 21);
        $pdf->Ln(12);
        $this->instructionBox($pdf, [
            'Pipe quantity = Length (Rmt) x Factor (kg/m).',
            'Diversity / Connection Ratio = (Total IDU capacity / (ODU HP x 25)) x 100.',
            'Total additional refrigerant = Pipe quantity subtotal + Table A addition.',
        ]);
        $pdf->Output('F', $out);
        return $out;
    }

    private function text($v): string
    {
        $s = trim((string)$v);
        return function_exists('iconv') ? (iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $s) ?: $s) : $s;
    }
    private function dateText($value): string
    {
        $value = trim((string)$value);
        $d = DateTime::createFromFormat('Y-m-d', $value);
        return $d ? $d->format('d-m-Y') : $value;
    }
    private function title(PmsFpdf $pdf, string $title): void
    {
        $pdf->SetFont('Arial', 'B', 17); $pdf->SetTextColor(31, 49, 68);
        $pdf->Cell(0, 26, $title, 0, 1, 'C');
        $pdf->SetDrawColor(76, 103, 128); $pdf->SetLineWidth(0.8);
        $pdf->Line(42, $pdf->GetY() + 3, 553, $pdf->GetY() + 3);
        $pdf->SetDrawColor(202, 211, 220); $pdf->SetLineWidth(0.35); $pdf->Ln(15);
    }
    private function section(PmsFpdf $pdf, string $title): void
    {
        $x = $pdf->GetX(); $y = $pdf->GetY();
        $pdf->SetFillColor(237, 242, 246); $pdf->Rect($x, $y, 511, 27, 'F');
        $pdf->SetFillColor(76, 103, 128); $pdf->Rect($x, $y, 4, 27, 'F');
        $pdf->SetTextColor(31, 49, 68); $pdf->SetFont('Arial', 'B', 10);
        $pdf->SetXY($x + 12, $y + 7); $pdf->Cell(495, 13, $title, 0, 0, 'L');
        $pdf->SetXY($x, $y + 27);
    }
    private function pairs(PmsFpdf $pdf, array $pairs): void
    {
        foreach ($pairs as $p) {
            $label = $this->text($p[0]); $value = $this->text($p[1]);
            $pdf->SetFont('Arial', '', 9);
            $height = max(24, $pdf->NbLines(386, $value) * 12);
            $x = $pdf->GetX(); $y = $pdf->GetY();
            $pdf->SetDrawColor(207, 215, 223); $pdf->SetLineWidth(0.35);
            $pdf->SetFillColor(245, 247, 249); $pdf->Rect($x, $y, 125, $height, 'DF');
            $pdf->Rect($x + 125, $y, 386, $height);
            $pdf->SetTextColor(54, 68, 82); $pdf->SetFont('Arial', 'B', 9); $pdf->SetXY($x + 7, $y + 6); $pdf->Cell(114, 12, $label);
            $pdf->SetTextColor(31, 41, 51); $pdf->SetFont('Arial', '', 9); $pdf->SetXY($x + 132, $y + 6); $pdf->MultiCell(375, 12, $value, 0, 'L');
            $pdf->SetXY($x, $y + $height);
        }
    }
    private function row(PmsFpdf $pdf, array $cells, array $widths, bool $head = false, array $aligns = [], float $rowHeight = 0): void
    {
        $pdf->SetFont('Arial', $head ? 'B' : '', $head ? 8 : 8.5);
        $pdf->SetDrawColor(198, 207, 216); $pdf->SetLineWidth(0.35);
        $pdf->SetFillColor(237, 242, 246);
        $pdf->SetTextColor($head ? 45 : 39, $head ? 61 : 49, $head ? 76 : 59);
        $height = $rowHeight > 0 ? $rowHeight : ($head ? 25 : 24);
        foreach ($cells as $i => $cell) {
            $align = $aligns[$i] ?? 'L';
            $pdf->Cell($widths[$i], $height, $this->text($cell), 1, $i === count($cells) - 1 ? 1 : 0, $align, $head);
        }
    }
    private function signatureRow(PmsFpdf $pdf, array $labels): void
    {
        $x = $pdf->GetX(); $y = $pdf->GetY(); $gap = 28; $w = (511 - $gap) / 2;
        $pdf->SetDrawColor(145, 157, 169); $pdf->SetLineWidth(0.5);
        $pdf->Line($x, $y, $x + $w, $y);
        $pdf->Line($x + $w + $gap, $y, $x + 511, $y);
        $pdf->SetTextColor(91, 105, 119); $pdf->SetFont('Arial', '', 8.5);
        $pdf->SetXY($x, $y + 5); $pdf->Cell($w, 12, $this->text($labels[0] ?? ''), 0, 0, 'L');
        $pdf->SetXY($x + $w + $gap, $y + 5); $pdf->Cell($w, 12, $this->text($labels[1] ?? ''), 0, 0, 'L');
        $pdf->SetXY($x, $y + 17);
    }
    private function instructionBox(PmsFpdf $pdf, array $lines): void
    {
        $x = $pdf->GetX(); $y = $pdf->GetY(); $h = 18 + (count($lines) * 12);
        $pdf->SetFillColor(247, 249, 251); $pdf->SetDrawColor(207, 215, 223);
        $pdf->Rect($x, $y, 511, $h, 'DF');
        $pdf->SetTextColor(54, 68, 82); $pdf->SetFont('Arial', 'B', 8.5);
        $pdf->SetXY($x + 8, $y + 6); $pdf->Cell(495, 10, 'CALCULATION GUIDE');
        $pdf->SetFont('Arial', '', 8); $pdf->SetTextColor(70, 82, 94);
        foreach ($lines as $i => $line) {
            $pdf->SetXY($x + 8, $y + 18 + ($i * 12));
            $pdf->Cell(495, 10, $this->text($line));
        }
        $pdf->SetXY($x, $y + $h);
    }
}
