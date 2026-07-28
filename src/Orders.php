<?php
/**
 * Orders-sheet index — the single answer to "which project is this?".
 *
 * One order row carries TWO human names for the same job:
 *   Non-VRV: "Billing Customer Name" (client) + "Site / Project Name" (site)
 *   VRV:     "Project Name"          (site)   + "Billing Name"        (client)
 * The dropdown used to offer both, and whichever the PE picked became the job's
 * identity — so one site split in two: two Drive photo folders, two admin projects
 * with their own lifecycle, and a client phone/email lookup (keyed on the site
 * name) that missed whenever the billing name was picked.
 *
 * So: read the Orders tab once and map every name back to its order. The dropdown
 * now lists SITE NAMES ONLY, one per order — a PE cannot pick a client name, so a
 * job can only be filed under its site. Client names stay RESOLVABLE, because
 * submissions written before this fix hold them, "PMS - NonVRV" files its rows
 * under them, and the contact lookups still need to match on them.
 *
 * Two orders that share a site name (e.g. Manish Jain Residence x2) get their
 * Order ID appended, so the PE picks the one they mean instead of both collapsing
 * onto the first match. A name that still cannot identify one order resolves to
 * nothing rather than guessing.
 *
 * Never throws: an unreachable sheet degrades to "no index", and every caller
 * falls back to the name the PE picked — exactly the old behaviour.
 */
class Orders
{
    /** @var Sheets */ private $sheets;
    /** @var array */  private $cfg;
    /** @var array 'VRV'|'NONVRV' => ['orders'=>[], 'alias'=>[], 'names'=>[]] */
    private $cache = [];

    public function __construct(Sheets $sheets, array $cfg)
    {
        $this->sheets = $sheets;
        $this->cfg = $cfg;
    }

    /** Dropdown list: the SITE name of every order, one entry each. No client names. */
    public function names(string $siteType): array
    {
        return $this->index($siteType)['names'];
    }

    /**
     * The order a picked name belongs to, or null when it matches none.
     * @return array|null ['order_id','canonical','project','billing','label','aliases'=>[]]
     */
    public function resolve(string $siteType, string $name): ?array
    {
        $idx = $this->index($siteType);
        $i = $idx['alias'][Sheets::normalizeKey($name)] ?? null;
        return $i === null ? null : $idx['orders'][$i];
    }

    /** Order ID for a picked name, '' when unresolvable (ports getOrderIdForProject_). */
    public function orderIdFor(string $siteType, string $name): string
    {
        $o = $this->resolve($siteType, $name);
        return $o ? $o['order_id'] : '';
    }

    /**
     * The name a submission is filed under. The SITE name wins — the client's
     * billing name is a way to find the job, never the job's identity. Unresolved
     * picks keep exactly what the PE chose.
     */
    public function canonicalName(string $siteType, string $name): string
    {
        $o = $this->resolve($siteType, $name);
        return ($o && $o['canonical'] !== '') ? $o['canonical'] : trim($name);
    }

    /**
     * Every name a progress sheet may have filed this order under, best first.
     * Needed because the two PMS tabs disagree: "PMS - VRV" holds the site name in
     * its Project Name column, "PMS - NonVRV" holds the billing name.
     */
    public function matchNames(string $siteType, string $name): array
    {
        $o = $this->resolve($siteType, $name);
        $list = $o ? $o['aliases'] : [];
        array_unshift($list, trim($name));
        $out = [];
        foreach ($list as $n) {
            $n = trim((string)$n);
            if ($n !== '') { $out[Sheets::normalizeKey($n)] = $n; }
        }
        return array_values($out);
    }

    /**
     * 0-based Orders columns holding a name, split by kind:
     *   ['site' => [...], 'billing' => [...]]
     * Shared with the contact lookups (Mailer / Whatsapp / CommissionPush) so they
     * index a client's phone+email under BOTH names and stop missing when the PE
     * picked the billing one. "Billing Address" is a location, never a name.
     */
    public static function nameCols(array $headers): array
    {
        $site = []; $billing = [];
        foreach ($headers as $i => $h) {
            $hl = strtolower((string)$h);
            if (strpos($hl, 'select project name') !== false
                || (strpos($hl, 'project name') !== false && strpos($hl, 'executive') === false)) {
                $site[] = $i;
            } elseif (strpos($hl, 'billing') !== false && strpos($hl, 'address') === false) {
                $billing[] = $i;
            }
        }
        return ['site' => $site, 'billing' => $billing];
    }

    /**
     * 0-based Orders columns that hold a site address, best first: shipping /
     * site location, then a bare "Address", then billing as the last resort.
     * Callers must walk the list and take the first NON-EMPTY cell: the Non-VRV
     * sheet still carries a legacy "Address" column that is empty on every row
     * (the real one is "Full Shipping Address"), so picking the first header
     * that merely contains "address" yields a blank for every Non-VRV order.
     */
    public static function addressCols(array $headers): array
    {
        $shipping = []; $plain = []; $billing = [];
        foreach ($headers as $i => $h) {
            $hl = strtolower((string)$h);
            if (strpos($hl, 'address') === false || strpos($hl, 'email') !== false) {
                continue;                       // "Email Address" is not a location
            }
            if (strpos($hl, 'shipping') !== false || strpos($hl, 'location') !== false) {
                $shipping[] = $i;
            } elseif (strpos($hl, 'billing') !== false) {
                $billing[] = $i;
            } else {
                $plain[] = $i;
            }
        }
        return array_merge($shipping, $plain, $billing);
    }

    /** First non-empty cell among $cols, '' when the row has none. */
    public static function firstFilled(array $row, array $cols): string
    {
        foreach ($cols as $i) {
            $v = trim((string)($row[$i] ?? ''));
            if ($v !== '') { return $v; }
        }
        return '';
    }

    /* ---------------- index ---------------- */

    private function index(string $siteType): array
    {
        $isVRV = ($siteType === 'VRV');
        $key = $isVRV ? 'VRV' : 'NONVRV';
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        $empty = ['orders' => [], 'alias' => [], 'names' => []];

        try {
            $ssId = $isVRV ? $this->cfg['vrv_orders_sheet_id'] : $this->cfg['nonvrv_orders_sheet_id'];
            $gid  = $isVRV ? $this->cfg['vrv_orders_gid'] : $this->cfg['nonvrv_orders_gid'];
            $title = $this->sheets->titleForGid($ssId, (int)$gid);
            if ($title === null) {
                return $this->cache[$key] = $empty;
            }
            $rows = $this->sheets->getTab($ssId, $title);
        } catch (Throwable $e) {
            return $this->cache[$key] = $empty;   // a sheet hiccup must never block a submit
        }
        if (count($rows) < 2) {
            return $this->cache[$key] = $empty;
        }

        $headers = $rows[0];
        $cols = self::nameCols($headers);
        if (!$cols['site'] && !$cols['billing']) {
            return $this->cache[$key] = $empty;
        }
        $orderCol = -1;
        foreach ($headers as $i => $h) {
            if (Sheets::isOrderIdHeader((string)$h)) { $orderCol = $i; break; }
        }

        $pick = function (array $row, array $cells): string {
            foreach ($cells as $c) {
                $v = trim(preg_replace('/\s+/', ' ', (string)($row[$c] ?? '')));
                if ($v !== '') { return $v; }
            }
            return '';
        };

        $orders = [];
        for ($r = 1; $r < count($rows); $r++) {
            $site = $pick($rows[$r], $cols['site']);
            $bill = $pick($rows[$r], $cols['billing']);
            if ($site === '' && $bill === '') {
                continue;
            }
            $orders[] = [
                'order_id'  => $orderCol >= 0 ? trim((string)($rows[$r][$orderCol] ?? '')) : '',
                'canonical' => $site !== '' ? $site : $bill,
                'project'   => $site,
                'billing'   => $bill,
                'row'       => $r + 1,
            ];
        }
        if (!$orders) {
            return $this->cache[$key] = $empty;
        }

        // A site name shared by two orders identifies neither — tag each with its
        // Order ID so the PE picks the one they mean.
        $canonUse = [];
        foreach ($orders as $o) {
            $canonUse[Sheets::normalizeKey($o['canonical'])] = ($canonUse[Sheets::normalizeKey($o['canonical'])] ?? 0) + 1;
        }
        foreach ($orders as $i => $o) {
            $label = $o['canonical'];
            if (($canonUse[Sheets::normalizeKey($label)] ?? 0) > 1) {
                $label .= ' (' . ($o['order_id'] !== '' ? $o['order_id'] : 'row ' . $o['row']) . ')';
            }
            $orders[$i]['label'] = $label;
            // Names a progress sheet may file this order under, site name first.
            $orders[$i]['aliases'] = array_values(array_unique(array_filter(
                [$o['canonical'], $o['project'], $o['billing'], $label],
                fn($v) => trim((string)$v) !== ''
            )));
        }

        // A name is usable only when exactly one order answers to it — but a SITE
        // name outranks a billing name, so "Bajaj Auto Limited" (one order's site,
        // another's client) still resolves to the site it names.
        $siteHits = []; $billHits = [];
        foreach ($orders as $i => $o) {
            foreach ([$o['label'], $o['canonical'], $o['project']] as $n) {
                $k = Sheets::normalizeKey($n);
                if ($k !== '') { $siteHits[$k][$i] = true; }
            }
            $k = Sheets::normalizeKey($o['billing']);
            if ($k !== '') { $billHits[$k][$i] = true; }
        }
        $alias = [];
        foreach ($siteHits as $k => $owners) {
            if (count($owners) === 1) { $alias[$k] = array_key_first($owners); }
        }
        foreach ($billHits as $k => $owners) {
            // only where no site name claims it, and only one client answers to it
            if (!isset($siteHits[$k]) && count($owners) === 1) { $alias[$k] = array_key_first($owners); }
        }

        // SITE NAMES ONLY — one entry per order. Client billing names stay
        // resolvable (below) so old submissions and the progress sheets still
        // match, but a PE never picks one, so a job can only ever be filed under
        // its site name.
        $names = [];
        foreach ($orders as $i => $o) {
            $n = trim((string)$o['label']);
            if ($n === '' || ($alias[Sheets::normalizeKey($n)] ?? -1) !== $i) { continue; }
            $names[$n] = true;
        }
        $names = array_keys($names);
        sort($names, SORT_FLAG_CASE | SORT_STRING);

        return $this->cache[$key] = ['orders' => $orders, 'alias' => $alias, 'names' => $names];
    }
}
