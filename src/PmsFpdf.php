<?php
require_once __DIR__ . '/../vendor/fpdf/fpdf.php';

/**
 * FPDF subclass that stamps the Vakharia letterhead (header banner + Daikin
 * footer + red rule) on every page, matching the old Google-Doc template.
 * Units are points, A4, to reuse the same geometry code.js used.
 */
class PmsFpdf extends FPDF
{
    public $headerImg;
    public $footerImg;

    public function Header()
    {
        // Full-width letterhead banner (logo + address). 2165x340 => ratio 6.37.
        if ($this->headerImg && is_file($this->headerImg)) {
            $w = 511;
            $this->Image($this->headerImg, 42, 24, $w, 0, 'PNG');
        }
    }

    public function Footer()
    {
        // Thin red rule + centered Daikin Experience Centre logo.
        $this->SetDrawColor(208, 49, 45);
        $this->SetLineWidth(1.4);
        $this->Line(42, 800, 553, 800);
        if ($this->footerImg && is_file($this->footerImg)) {
            $w = 92; // 414x146 => h ~32.5
            // Left-aligned with the content margin (x=42) instead of centered.
            $this->Image($this->footerImg, 42, 806, $w, 0, 'PNG');
        }
        // Reset for body.
        $this->SetLineWidth(0.2);
    }

    /** Lines a MultiCell of width $w would need for $txt (FPDF NbLines port). */
    public function NbLines($w, $txt)
    {
        $cw = $this->CurrentFont['cw'];
        if ($w == 0) {
            $w = $this->w - $this->rMargin - $this->x;
        }
        $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $s = str_replace("\r", '', (string)$txt);
        $nb = strlen($s);
        if ($nb > 0 && $s[$nb - 1] == "\n") {
            $nb--;
        }
        $sep = -1; $i = 0; $j = 0; $l = 0; $nl = 1;
        while ($i < $nb) {
            $c = $s[$i];
            if ($c == "\n") { $i++; $sep = -1; $j = $i; $l = 0; $nl++; continue; }
            if ($c == ' ') { $sep = $i; }
            $l += isset($cw[$c]) ? $cw[$c] : 0;
            if ($l > $wmax) {
                if ($sep == -1) { if ($i == $j) { $i++; } }
                else { $i = $sep + 1; }
                $sep = -1; $j = $i; $l = 0; $nl++;
            } else {
                $i++;
            }
        }
        return $nl;
    }
}
