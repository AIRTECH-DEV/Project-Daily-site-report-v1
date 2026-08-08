<?php
/**
 * Client-safe projection of Project 360.
 *
 * The public page NEVER re-uses an admin query. Everything a client sees is
 * built here, field by field, from a whitelist:
 *
 *   shown  — project label, lifecycle, current step, progress, target end, next
 *            plan date, step timeline (status/planned/actual/completed), status
 *            change log, amendment/drawing/measurement flags, photos, documents.
 *   hidden — submitter identity, internal remarks, risks/alerts, pipeline health,
 *            client-delivery events, workforce & contractor names, other
 *            projects, order ids of other units, anything not listed above.
 *
 * Hold notes follow the rule the business asked for: a hold that sits with the
 * CLIENT is spelled out (they must act on it); a hold that sits with VAPL is
 * shown as a neutral "with our team" line — the internal detail stays internal.
 *
 * Requires admin/inc/helpers.php (pure functions: projectKey, canonicalSteps,
 * expandVisits, attachmentsForFlat, …).
 */
class ShareData
{
    /* ---------------- scope ---------------- */

    /**
     * Every project row the link may show, in display order.
     * A link can only ever widen to what its scope_key says — never to a key
     * supplied in the URL.
     */
    public static function scopeProjects(PDO $db, array $link): array
    {
        $scope = (string)$link['scope'];
        $key   = (string)$link['scope_key'];

        if ($scope === 'project') {
            // project_key first, project_id as the durable fallback: an order id
            // resolved after the link was minted rewrites the key (G|name -> O|id).
            $st = $db->prepare("SELECT * FROM projects WHERE project_key = ? LIMIT 1");
            $st->execute([$key]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row && !empty($link['project_id'])) {
                $st = $db->prepare("SELECT * FROM projects WHERE id = ? LIMIT 1");
                $st->execute([(int)$link['project_id']]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
            }
            return $row ? [$row] : [];
        }

        if ($scope === 'building') {
            // key = B|<developer>|<building>
            $parts = explode('|', $key, 3);
            $st = $db->prepare(
                "SELECT * FROM projects
                  WHERE client_type = 'Developer'
                    AND LOWER(TRIM(COALESCE(developer,''))) = ?
                    AND LOWER(TRIM(COALESCE(building,'')))  = ?"
            );
            $st->execute([strtolower(trim($parts[1] ?? '')), strtolower(trim($parts[2] ?? ''))]);
        } else {
            // developer: key = V|<developer>
            $st = $db->prepare(
                "SELECT * FROM projects
                  WHERE client_type = 'Developer'
                    AND LOWER(TRIM(COALESCE(developer,''))) = ?"
            );
            $st->execute([strtolower(trim(substr($key, 2)))]);
        }
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        usort($rows, function ($a, $b) {
            $c = strnatcasecmp((string)$a['building'], (string)$b['building']);
            return $c !== 0 ? $c : strnatcasecmp((string)$a['flat_no'], (string)$b['flat_no']);
        });
        return $rows;
    }

    /** The project a ?u=<key> request may open — null when it is outside the link. */
    public static function pickProject(PDO $db, array $link, string $wantKey): ?array
    {
        $rows = self::scopeProjects($db, $link);
        if (!$rows) {
            return null;
        }
        if ($wantKey !== '') {
            foreach ($rows as $r) {
                if ((string)$r['project_key'] === $wantKey) {
                    return $r;
                }
            }
            return null;                      // asked for something outside the scope
        }
        return $rows[0];
    }

    /* ---------------- one project ---------------- */

    /** The visits of one project, narrowed in SQL, then keyed exactly. */
    public static function visits(PDO $db, array $pr): array
    {
        $key = (string)$pr['project_key'];
        if ((string)$pr['client_type'] === 'Developer') {
            $st = $db->prepare(
                "SELECT * FROM submissions
                  WHERE client_type = 'Developer'
                    AND COALESCE(developer,'') = ?
                    AND COALESCE(building,'')  = ?
                  ORDER BY id ASC"
            );
            $st->execute([(string)$pr['developer'], (string)$pr['building']]);
        } elseif (trim((string)$pr['order_id']) !== '') {
            $st = $db->prepare(
                "SELECT * FROM submissions
                  WHERE COALESCE(client_type,'') <> 'Developer'
                    AND LOWER(TRIM(COALESCE(order_id,''))) = ?
                  ORDER BY id ASC"
            );
            $st->execute([strtolower(trim((string)$pr['order_id']))]);
        } else {
            $st = $db->prepare(
                "SELECT * FROM submissions
                  WHERE COALESCE(client_type,'') <> 'Developer'
                    AND COALESCE(order_id,'') = ''
                    AND COALESCE(project,'')  = ?
                  ORDER BY id ASC"
            );
            $st->execute([(string)$pr['project_name']]);
        }
        // A developer visit can report many flats at once; expand it so only THIS
        // flat's slice is read (and so a neighbour's report never leaks in).
        return array_values(array_filter(expandVisits($st->fetchAll(PDO::FETCH_ASSOC)), fn($r) => projectKey($r) === $key));
    }

    /**
     * The whole client view of one project.
     * @param array $opts ['photos'=>1,'pe_names'=>0,'hold_detail'=>'client_only'|'none'|'all']
     */
    public static function projectView(PDO $db, array $pr, array $opts): array
    {
        $visits    = self::visits($db, $pr);
        $showPe    = !empty($opts['pe_names']);
        $holdMode  = (string)($opts['hold_detail'] ?? 'client_only');
        $showPhoto = !array_key_exists('photos', $opts) || !empty($opts['photos']);

        $steps = [];
        foreach (canonicalSteps((string)$pr['site_type']) as $i => $nm) {
            $steps[stepKey($nm)] = [
                'name' => $nm, 'order' => $i, 'status' => '', 'actualStart' => '',
                'doneOn' => '', 'planned' => '', 'pe' => '', 'note' => '',
            ];
        }
        $changes = [];
        $prev    = [];

        foreach ($visits as $v) {
            $pl   = json_decode((string)$v['payload_json'], true) ?: [];
            $date = date('Y-m-d', strtotime((string)$v['created_at']));
            $pe   = $showPe ? trim((string)$v['engineer']) : '';

            $tSteps = $pl['tomorrowSteps'] ?? [];
            if (is_string($tSteps)) {
                $tSteps = json_decode($tSteps, true) ?: [];
            }
            $nsd = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($pl['nextStepStartDate'] ?? '')) ? $pl['nextStepStartDate'] : '';
            if (is_array($tSteps) && $nsd !== '') {
                foreach ($tSteps as $ts) {
                    $k = stepKey((string)$ts);
                    if (isset($steps[$k]) && $steps[$k]['planned'] === '') {
                        $steps[$k]['planned'] = $nsd;
                    }
                }
            }

            foreach (($pl['stepStatuses'] ?? []) as $e) {
                if (!is_array($e)) continue;
                $reported = trim((string)($e['step'] ?? ''));
                if ($reported === '') continue;

                foreach (canonicalAliases($reported) as $nm) {
                    $k = stepKey($nm);
                    if (!isset($steps[$k])) {
                        $steps[$k] = ['name'=>$nm,'order'=>999,'status'=>'','actualStart'=>'','doneOn'=>'','planned'=>'','pe'=>'','note'=>''];
                    }
                    $stt = ucfirst(strtolower(trim((string)($e['status'] ?? ''))));
                    if ($stt === '') continue;

                    if ($steps[$k]['actualStart'] === '') $steps[$k]['actualStart'] = $date;
                    $steps[$k]['status'] = $stt;
                    if ($stt === 'Done' && $steps[$k]['doneOn'] === '') {
                        $steps[$k]['doneOn'] = $date;
                        $steps[$k]['pe'] = $pe;
                    }

                    $note = '';
                    if ($stt === 'Hold') {
                        $note = self::holdNote(
                            (string)($e['holdReason'] ?? ''),
                            (string)($e['holdReasonDetail'] ?? ''),
                            $holdMode
                        );
                        $steps[$k]['note'] = $note;
                    } else {
                        $steps[$k]['note'] = '';       // resolved: drop the old hold line
                    }

                    if (($prev[$k] ?? '') !== $stt) {
                        $changes[] = [
                            'date' => $v['created_at'], 'step' => $nm, 'to' => $stt,
                            'pe' => $pe, 'note' => $note,
                        ];
                        $prev[$k] = $stt;
                    }
                }
            }
        }
        uasort($steps, fn($a, $b) => $a['order'] <=> $b['order']);
        $changes = array_reverse($changes);

        // ---- amendments / drawings / measurements (flags only, no internal why) ----
        $flags = [];
        foreach ($visits as $v) {
            foreach ([['amendment','Amendment'],['drawing_change','Drawing change'],['measurement','Measurement']] as [$col, $lbl]) {
                if (strcasecmp((string)$v[$col], 'Yes') === 0) {
                    $flags[] = ['date' => $v['created_at'], 'label' => $lbl];
                }
            }
        }

        // ---- attachments, scoped to this unit ----
        $subIds = array_values(array_unique(array_map(fn($r) => (int)$r['id'], $visits)));
        $atts = [];
        if ($subIds) {
            $in = implode(',', array_fill(0, count($subIds), '?'));
            $st = $db->prepare("SELECT id, submission_id, kind, file_name, mime_type, drive_file_id, created_at
                                  FROM attachments WHERE submission_id IN ($in) ORDER BY id DESC");
            $st->execute($subIds);
            $atts = $st->fetchAll(PDO::FETCH_ASSOC);
        }
        if ((string)$pr['client_type'] === 'Developer') {
            // "Flat<TAG>_" prefixes are the only per-flat marker files carry — without
            // this filter a flat-scoped link would hand out a neighbour's photos.
            $atts = attachmentsForFlat($atts, (string)$pr['flat_no']);
        }
        $photos = $showPhoto
            ? array_values(array_filter($atts, fn($a) => $a['kind'] === 'site_photo'))
            : [];
        $docs = array_values(array_filter(
            $atts,
            fn($a) => $a['kind'] !== 'site_photo' && !empty($a['drive_file_id'])
        ));

        $done  = (int)$pr['steps_done'];
        $total = (int)$pr['steps_total'];
        $pct   = $total > 0 ? min(100, (int)round($done * 100 / $total)) : 0;

        return [
            'project'   => $pr,
            'pct'       => $pct,
            'steps'     => $steps,
            'changes'   => $changes,
            'flags'     => $flags,
            'photos'    => $photos,
            'docs'      => $docs,
            'visits'    => count($visits),
            'last_at'   => (string)$pr['last_report_at'],
            'show_pe'   => $showPe,
        ];
    }

    /**
     * What a client is told about a hold.
     *  - stuck on the CLIENT  -> full reason + detail (they have to act on it)
     *  - stuck on VAPL/others -> neutral line, internal detail withheld
     */
    public static function holdNote(string $reason, string $detail, string $mode = 'client_only'): string
    {
        $party  = holdParty($reason);
        $detail = trim($detail);
        $isClient = stripos($party, 'client') !== false;

        if ($mode === 'none') {
            return $isClient ? 'Awaiting input from your side' : 'On hold with our team';
        }
        if ($mode === 'all') {
            return trim(($party !== '' ? "On hold — $party" : 'On hold') . ($detail !== '' ? ": $detail" : ''));
        }
        // client_only (default)
        if ($isClient) {
            return trim('Awaiting input from your side' . ($detail !== '' ? ': ' . $detail : ''));
        }
        return 'On hold with our team — being followed up';
    }

    /* ---------------- file access ---------------- */

    /**
     * The attachment row an ?f=<id> request may fetch, or null.
     * Re-derives the whole scope server-side: an id belonging to another project
     * (or another flat of the same building) resolves to null, not to a file.
     */
    public static function attachmentInScope(PDO $db, array $link, int $attId, array $opts): ?array
    {
        if ($attId <= 0) {
            return null;
        }
        $st = $db->prepare("SELECT * FROM attachments WHERE id = ? LIMIT 1");
        $st->execute([$attId]);
        $att = $st->fetch(PDO::FETCH_ASSOC);
        if (!$att || empty($att['drive_file_id'])) {
            return null;
        }
        if ($att['kind'] === 'site_photo' && array_key_exists('photos', $opts) && empty($opts['photos'])) {
            return null;                       // photos switched off for this link
        }

        $st = $db->prepare("SELECT * FROM submissions WHERE id = ? LIMIT 1");
        $st->execute([(int)$att['submission_id']]);
        $sub = $st->fetch(PDO::FETCH_ASSOC);
        if (!$sub) {
            return null;
        }

        foreach (self::scopeProjects($db, $link) as $pr) {
            foreach (expandVisit($sub) as $row) {
                if (projectKey($row) !== (string)$pr['project_key']) {
                    continue;
                }
                if ((string)$pr['client_type'] === 'Developer'
                    && !attachmentsForFlat([$att], (string)$pr['flat_no'])) {
                    continue;                  // right building, wrong flat
                }
                return $att;
            }
        }
        return null;
    }

    /** Friendly, non-leaky download name. */
    public static function fileName(array $att, array $pr): string
    {
        $base = trim((string)$att['file_name']);
        if ($base === '') {
            $base = ucfirst((string)$att['kind']) . '.pdf';
        }
        // strip the internal "Flat1801_" prefix — it means nothing to the client
        $base = preg_replace('/^Flat[A-Za-z0-9]+_/', '', $base);
        return $base;
    }
}
