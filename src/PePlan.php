<?php
/**
 * PE Plan reminder — gathers "what each engineer (PE) is going to site for
 * tomorrow" from the submitted reports (payload tomorrowSteps + nextStepStartDate,
 * exactly like admin/planner.php + calendar.php), groups it by PE, and renders a
 * clean PNG card of the day's plan.
 *
 * WhatsApp forbids new-line/tab characters inside template variables, so the
 * grouped multi-line layout the client wanted cannot be sent as text. Instead we
 * render it as an IMAGE and send it as the header of an approved image template —
 * it previews inline in the chat and can hold any layout.
 *
 * Pure/self-contained: DB read + GD draw only. No WhatsApp/Google here.
 */
class PePlan
{
    /** @var array pe_plan config block (fonts, brand) */
    private $cfg;

    public function __construct(array $peCfg = [])
    {
        $this->cfg = $peCfg;
    }

    /* ============================ data ============================ */

    /**
     * Plan for a given Y-m-d, grouped by PE. Latest report per project wins
     * (ascending id), same rule the admin Daily Plan uses.
     * @return array [ ['pe'=>string, 'sites'=>[ ['label'=>string,'steps'=>string[]] ] ], ... ]
     */
    public function planForDate(PDO $db, string $date): array
    {
        $normDate = fn($v) => (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($v))) ? trim($v) : '';

        // Key by project + planned-date (mirrors admin/calendar.php): a project can
        // have work scheduled on several days across reports, so each (project,date)
        // is its own entry. The latest report for the same project+date wins.
        $plans = [];   // projectKey|date => plan
        $sql = "SELECT id, project, developer, building, flat_no, client_type, engineer, created_at, payload_json
                FROM submissions ORDER BY id ASC";
        foreach ($db->query($sql) as $r) {
            $pl = json_decode((string)$r['payload_json'], true) ?: [];
            $steps = $pl['tomorrowSteps'] ?? null;
            if (is_string($steps)) $steps = json_decode($steps, true);
            if (!is_array($steps)) continue;
            $steps = array_values(array_filter(array_map(fn($x) => trim((string)$x), $steps), fn($x) => $x !== ''));
            if (!$steps) continue;

            $when = $normDate($pl['nextStepStartDate'] ?? '') ?: date('Y-m-d', strtotime($r['created_at'] . ' +1 day'));
            $plans[$this->projectKey($r) . '|' . $when] = [
                'date'  => $when,
                'label' => $this->projectLabel($r),
                'steps' => $steps,
                'pe'    => trim((string)$r['engineer']) ?: 'Unassigned',
            ];
        }

        // keep only the requested date, group by PE
        $byPe = [];
        foreach ($plans as $p) {
            if ($p['date'] !== $date) continue;
            $byPe[$p['pe']][] = ['label' => $p['label'], 'steps' => $p['steps']];
        }
        ksort($byPe, SORT_NATURAL | SORT_FLAG_CASE);

        $groups = [];
        foreach ($byPe as $pe => $sites) {
            usort($sites, fn($a, $b) => strcasecmp($a['label'], $b['label']));
            $groups[] = ['pe' => $pe, 'sites' => $sites];
        }
        return $groups;
    }

    /** Count sites across all groups. */
    public function siteCount(array $groups): int
    {
        $n = 0;
        foreach ($groups as $g) $n += count($g['sites']);
        return $n;
    }

    private function projectLabel(array $r): string
    {
        if (($r['client_type'] ?? '') === 'Developer') {
            $bits = array_filter([$r['developer'] ?? '', $r['building'] ?? '', $r['flat_no'] ?? '']);
            return $bits ? implode(' › ', $bits) : '(developer report)';
        }
        return trim((string)($r['project'] ?? '')) ?: '(no project)';
    }

    private function projectKey(array $r): string
    {
        if (($r['client_type'] ?? '') === 'Developer') {
            return 'D|' . strtolower(trim(($r['developer'] ?? '') . '|' . ($r['building'] ?? '') . '|' . ($r['flat_no'] ?? '')));
        }
        return 'G|' . strtolower(trim((string)($r['project'] ?? '')));
    }

    /* ============================ image ============================ */

    /** Regular / semibold / bold TTF paths (config-overridable, Segoe UI default). */
    private function fonts(): array
    {
        $f = $this->cfg['fonts'] ?? [];
        $reg  = $f['regular']  ?? 'C:/Windows/Fonts/segoeui.ttf';
        $semi = $f['semibold'] ?? 'C:/Windows/Fonts/seguisb.ttf';
        $bold = $f['bold']     ?? 'C:/Windows/Fonts/segoeuib.ttf';

        // Cross-platform fallbacks so text renders on Linux (GCP) too, where the
        // Windows paths above don't exist. Liberation Sans is Arial-metric; DejaVu
        // and Noto are the usual Ubuntu defaults. Ordered regular / bold candidates.
        $linuxReg = [
            '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/noto/NotoSans-Regular.ttf',
            'C:/Windows/Fonts/arial.ttf',
        ];
        $linuxBold = [
            '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/noto/NotoSans-Bold.ttf',
            'C:/Windows/Fonts/arialbd.ttf',
        ];
        // fall back to Arial, then to whatever exists on this OS, so render never dies
        $pick = function ($p, array $alts) {
            if (is_file($p)) return $p;
            foreach ($alts as $x) if (is_file($x)) return $x;
            return $p;
        };
        return [
            'regular'  => $pick($reg,  $linuxReg),
            'semibold' => $pick($semi, $linuxBold),
            'bold'     => $pick($bold, $linuxBold),
        ];
    }

    /**
     * Renders the plan card to PNG bytes.
     * @param string $date  Y-m-d the plan is for (tomorrow)
     * @param array  $groups planForDate() output
     */
    public function renderPng(string $date, array $groups): string
    {
        $font = $this->fonts();
        $W    = 1000;
        $padX = 56;
        $contentW = $W - $padX * 2;

        // ---- layout constants
        $headerH   = 150;
        $peGap     = 30;   // space above each PE block
        $peH       = 46;   // PE name row height
        $siteGap   = 20;   // space above each site
        $siteLH    = 34;   // site label line height
        $stepLH    = 30;   // steps line height
        $footerH   = 90;

        // ---- measure body height (wrap first) ------------------------------
        $blocks = [];
        foreach ($groups as $g) {
            $sites = [];
            foreach ($g['sites'] as $s) {
                $labelLines = $this->wrap($font['semibold'], 21, $contentW - 60, $s['label']);
                $stepsText  = implode('  •  ', $s['steps']);
                $stepLines  = $this->wrap($font['regular'], 18, $contentW - 60, $stepsText);
                $sites[] = ['labelLines' => $labelLines, 'stepLines' => $stepLines];
            }
            $blocks[] = ['pe' => $g['pe'], 'sites' => $sites];
        }

        $bodyH = 24;
        if (!$blocks) {
            $bodyH += 80;
        }
        foreach ($blocks as $b) {
            $bodyH += $peGap + $peH;
            foreach ($b['sites'] as $s) {
                $bodyH += $siteGap
                        + count($s['labelLines']) * $siteLH
                        + count($s['stepLines'])  * $stepLH;
            }
        }
        $H = $headerH + $bodyH + $footerH;

        // ---- canvas --------------------------------------------------------
        $im = imagecreatetruecolor($W, $H);
        imagesavealpha($im, true);
        $c = [
            'bg'       => imagecolorallocate($im, 247, 249, 252),
            'card'     => imagecolorallocate($im, 255, 255, 255),
            'brand'    => imagecolorallocate($im, 31, 95, 208),   // header band
            'brand2'   => imagecolorallocate($im, 24, 78, 173),
            'white'    => imagecolorallocate($im, 255, 255, 255),
            'whiteDim' => imagecolorallocate($im, 213, 228, 250),
            'ink'      => imagecolorallocate($im, 24, 34, 51),
            'ink2'     => imagecolorallocate($im, 71, 85, 105),
            'muted'    => imagecolorallocate($im, 120, 134, 153),
            'line'     => imagecolorallocate($im, 226, 232, 240),
            'accent'   => imagecolorallocate($im, 31, 95, 208),
            'avatar'   => imagecolorallocate($im, 233, 240, 253),
            'avatarInk'=> imagecolorallocate($im, 31, 95, 208),
            'chipBg'   => imagecolorallocate($im, 240, 244, 249),
        ];
        imagefill($im, 0, 0, $c['bg']);

        // header band
        imagefilledrectangle($im, 0, 0, $W, $headerH, $c['brand']);
        imagefilledrectangle($im, 0, $headerH - 6, $W, $headerH, $c['brand2']);

        $dt   = strtotime($date);
        $dstr = date('l, d M Y', $dt);
        $this->text($im, $font['bold'], 36, $padX, 62, $c['white'], 'Tomorrow\'s Site Plan');
        $this->text($im, $font['regular'], 22, $padX, 104, $c['whiteDim'], $dstr);

        $peN   = count($groups);
        $siteN = $this->siteCount($groups);
        $summary = $peN . ' engineer' . ($peN === 1 ? '' : 's') . '  ·  ' . $siteN . ' site' . ($siteN === 1 ? '' : 's');
        $sw = $this->textWidth($font['semibold'], 20, $summary);
        $this->text($im, $font['semibold'], 20, $W - $padX - $sw, 92, $c['white'], $summary);

        // ---- body ----------------------------------------------------------
        $y = $headerH + 24;

        if (!$blocks) {
            $y += 50;
            $msg = 'No site visits planned for this day.';
            $mw = $this->textWidth($font['regular'], 22, $msg);
            $this->text($im, $font['regular'], 22, ($W - $mw) / 2, $y, $c['muted'], $msg);
        }

        $discR = 21;                // avatar radius
        $nameX = $padX + $discR * 2 + 16;
        foreach ($blocks as $b) {
            $y += $peGap;
            // PE header row: avatar disc + initial + name, all centered on one line
            $init = strtoupper(mb_substr($b['pe'] === 'Unassigned' ? '?' : $b['pe'], 0, 1));
            $rowH = 44;
            $cx = $padX + $discR;
            $cy = $y + $rowH / 2;
            imagefilledellipse($im, (int)$cx, (int)$cy, $discR * 2, $discR * 2, $c['avatar']);
            $this->textV($im, $font['bold'], 20, $cx, $cy, $c['avatarInk'], $init, true);   // centered in disc
            $this->textV($im, $font['bold'], 25, $nameX, $cy, $c['ink'], $b['pe']);          // v-centered on disc
            $y += $rowH;

            foreach ($b['sites'] as $s) {
                $y += $siteGap;
                $blockH = count($s['labelLines']) * $siteLH + count($s['stepLines']) * $stepLH;
                // accent bar aligned to the site text block
                imagefilledrectangle($im, $nameX, (int)($y + 1), $nameX + 3, (int)($y + $blockH - 6), $c['accent']);

                $tx = $nameX + 18;
                foreach ($s['labelLines'] as $ln) {
                    $this->text($im, $font['semibold'], 21, $tx, $y, $c['ink'], $ln);
                    $y += $siteLH;
                }
                foreach ($s['stepLines'] as $ln) {
                    $this->text($im, $font['regular'], 18, $tx, $y + 2, $c['ink2'], $ln);
                    $y += $stepLH;
                }
            }
        }

        // ---- footer --------------------------------------------------------
        $fy = $H - $footerH;
        imagefilledrectangle($im, 0, $fy, $W, $fy + 2, $c['line']);
        $this->text($im, $font['semibold'], 19, $padX, $fy + 40, $c['ink2'], 'Vakharia Airtech · PMS');
        $gen = 'Auto-generated ' . date('d M Y, H:i');
        $gw  = $this->textWidth($font['regular'], 16, $gen);
        $this->text($im, $font['regular'], 16, $W - $padX - $gw, $fy + 39, $c['muted'], $gen);

        ob_start();
        imagepng($im);
        $bytes = (string)ob_get_clean();
        imagedestroy($im);
        return $bytes;
    }

    /* ---- GD text helpers (baseline-corrected so y = top of the text) ---- */

    private function text($im, string $font, float $size, float $x, float $y, int $color, string $s): void
    {
        // imagettftext y is the baseline; shift down by the ascender so callers pass a top-ish y.
        $bbox = imagettfbbox($size, 0, $font, 'Ag');
        $ascent = -$bbox[7];
        imagettftext($im, $size, 0, (int)round($x), (int)round($y + $ascent), $color, $font, $s);
    }

    /**
     * Draws text vertically centered on $cy (using the string's own ink box, so a
     * single letter sits dead-centre in its disc). $centerX also centres it on $x.
     */
    private function textV($im, string $font, float $size, float $x, float $cy, int $color, string $s, bool $centerX = false): void
    {
        if ($s === '') return;
        $b = imagettfbbox($size, 0, $font, $s);
        $baseline = $cy - ($b[7] + $b[1]) / 2;      // centre of the glyph ink box on cy
        $tx = $centerX ? $x - ($b[0] + $b[2]) / 2 : $x;
        imagettftext($im, $size, 0, (int)round($tx), (int)round($baseline), $color, $font, $s);
    }

    private function textWidth(string $font, float $size, string $s): float
    {
        $b = imagettfbbox($size, 0, $font, $s);
        return abs($b[2] - $b[0]);
    }

    /** Greedy word-wrap to a pixel width. Falls back to hard-splitting a long word. */
    private function wrap(string $font, float $size, float $maxW, string $text): array
    {
        $text = trim($text);
        if ($text === '') return [''];
        $words = preg_split('/\s+/', $text);
        $lines = [];
        $cur = '';
        foreach ($words as $w) {
            $try = $cur === '' ? $w : $cur . ' ' . $w;
            if ($this->textWidth($font, $size, $try) <= $maxW) {
                $cur = $try;
                continue;
            }
            if ($cur !== '') { $lines[] = $cur; $cur = ''; }
            // single word wider than the line -> hard-split
            if ($this->textWidth($font, $size, $w) > $maxW) {
                $chunk = '';
                foreach (preg_split('//u', $w, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
                    if ($this->textWidth($font, $size, $chunk . $ch) > $maxW && $chunk !== '') {
                        $lines[] = $chunk; $chunk = $ch;
                    } else {
                        $chunk .= $ch;
                    }
                }
                $cur = $chunk;
            } else {
                $cur = $w;
            }
        }
        if ($cur !== '') $lines[] = $cur;
        return $lines ?: [''];
    }
}
