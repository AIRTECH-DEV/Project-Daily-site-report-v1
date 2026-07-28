<?php
/**
 * ONE-TIME repair: merge General projects that were split across the two names
 * one order carries (its site name and the client's billing name).
 *
 *   php scripts/merge_project_aliases.php            # dry run — prints the plan
 *   php scripts/merge_project_aliases.php --apply    # writes
 *
 * Before the fix, whichever name the PE picked became the project's identity, so
 * "YASH BHAI" (Billing Customer Name) and "Yash Test project" (Site / Project
 * Name) — one order, ORD-260728-00041 — were two projects in the admin panel.
 *
 * This does three things, all idempotent (safe to re-run):
 *   1. backfills submissions.order_id from the Orders sheets,
 *   2. rewrites submissions.project to the canonical SITE name,
 *   3. re-keys projects/alerts/project_step_dates from the old name-based key to
 *      the new 'O|<order id>' one, MERGING the duplicates and keeping the manual
 *      state (locked lifecycle, commissioned/closed/app-pushed stamps).
 *
 * Run admin → Sync (or scripts/admin_sync.php) afterwards to rebuild the rollups.
 */
require __DIR__ . '/../src/Bootstrap.php';
Bootstrap::autoload();
require __DIR__ . '/../admin/inc/helpers.php';

$apply = in_array('--apply', $argv, true);
$app = Bootstrap::init();
$db = $app->db()->pdo();
$orders = new Orders($app->sheets, $app->cfg);

echo $apply ? "APPLY mode — writing changes.\n\n" : "DRY RUN — nothing is written. Re-run with --apply.\n\n";

/* ---------- 1: plan — resolve every General submission, keeping its OLD key ---------- */

$subs = $db->query(
    "SELECT id, site_type, client_type, project, order_id
       FROM submissions
      WHERE (client_type IS NULL OR client_type <> 'Developer')
        AND project IS NOT NULL AND project <> ''
      ORDER BY id ASC"
)->fetchAll(PDO::FETCH_ASSOC);

$writes = [];       // submission id => [project, order_id]
$remap  = [];       // old project_key => new project_key
$unresolved = [];

foreach ($subs as $s) {
    // The key this row produces TODAY. Captured before any rewrite — once the
    // billing-name label is replaced, nothing else remembers that key existed and
    // its project row (with any manual Commissioned/Closed state) would orphan.
    $oldKey = projectKey($s);

    $rec = $orders->resolve((string)$s['site_type'], (string)$s['project']);
    if (!$rec) {
        $unresolved[(string)$s['project']] = true;
        continue;
    }
    $project = $rec['canonical'] !== '' ? $rec['canonical'] : (string)$s['project'];
    $orderId = $rec['order_id']  !== '' ? $rec['order_id']  : (string)$s['order_id'];

    $newKey = projectKey(['client_type' => $s['client_type'], 'project' => $project, 'order_id' => $orderId]);
    if ($oldKey !== $newKey) {
        $remap[$oldKey] = $newKey;
    }
    if ($project !== (string)$s['project'] || $orderId !== (string)$s['order_id']) {
        $writes[(int)$s['id']] = [$project, $orderId];
        printf("  #%-5d %-38s -> %-38s  order %s\n", $s['id'], $s['project'], $project, $orderId ?: '(none)');
    }
}
echo "submissions to repoint: " . count($writes) . " of " . count($subs) . "\n";
if ($unresolved) {
    echo "not found in any Orders sheet (left as-is): " . implode(' | ', array_keys($unresolved)) . "\n";
}

// Sweep up name keys left anywhere else — rows an earlier run already rewrote, and
// alerts/step dates whose project no longer exists under that name. The key itself
// holds the (lowercased) name, which is all the Orders index needs; site type is
// unknown here, so try both.
$leftovers = [];
foreach (['projects', 'alerts', 'project_step_dates'] as $t) {
    try {
        foreach ($db->query("SELECT DISTINCT project_key FROM `$t` WHERE project_key LIKE 'G|%'") as $r) {
            $leftovers[$r['project_key']] = true;
        }
    } catch (Throwable $e) {
        // table not created on this install — nothing to sweep
    }
}
foreach (array_keys($leftovers) as $oldKey) {
    if (isset($remap[$oldKey])) { continue; }
    $name = substr($oldKey, 2);
    foreach (['Non-VRV', 'VRV'] as $st) {
        $oid = $orders->orderIdFor($st, $name);
        if ($oid !== '') {
            $remap[$oldKey] = 'O|' . strtolower($oid);
            break;
        }
    }
}

echo "\nproject key remap (" . count($remap) . "):\n";
foreach ($remap as $old => $new) {
    echo "  $old  ->  $new\n";
}

if (!$apply) {
    echo "\nDry run complete. Re-run with --apply, then run the admin Sync.\n";
    exit(0);
}

$db->beginTransaction();
try {
    /* ---------- 2: repoint the submissions ---------- */
    $upd = $db->prepare("UPDATE submissions SET project = ?, order_id = ? WHERE id = ?");
    foreach ($writes as $id => [$project, $orderId]) {
        $upd->execute([$project, $orderId, $id]);
    }

    /* ---------- 3: re-key the master tables onto the order id ---------- */
    // projects: merge onto the new key, preserving the strongest manual state.
    $sel  = $db->prepare("SELECT * FROM projects WHERE project_key = ?");
    $ins  = $db->prepare("UPDATE projects SET project_key = ? WHERE project_key = ?");
    $del  = $db->prepare("DELETE FROM projects WHERE project_key = ?");
    $keep = $db->prepare(
        "UPDATE projects
            SET lifecycle = ?, lifecycle_locked = ?, commissioned_at = ?,
                closed_at = ?, closed_by = ?, app_pushed_at = ?
          WHERE project_key = ?"
    );

    foreach ($remap as $old => $new) {
        $sel->execute([$old]);
        $from = $sel->fetch(PDO::FETCH_ASSOC);
        if (!$from) { continue; }

        $sel->execute([$new]);
        $to = $sel->fetch(PDO::FETCH_ASSOC);

        if (!$to) {
            $ins->execute([$new, $old]);          // no collision — just rename
            continue;
        }
        // Both keys exist: keep the manual state from whichever row has it.
        $locked = max((int)$from['lifecycle_locked'], (int)$to['lifecycle_locked']);
        $lifecycle = !empty($from['lifecycle_locked']) ? $from['lifecycle'] : $to['lifecycle'];
        $keep->execute([
            $lifecycle,
            $locked,
            $to['commissioned_at'] ?: $from['commissioned_at'],
            $to['closed_at']       ?: $from['closed_at'],
            $to['closed_by']       ?: $from['closed_by'],
            $to['app_pushed_at']   ?: $from['app_pushed_at'],
            $new,
        ]);
        $del->execute([$old]);
    }

    // Alerts carry the key twice: in project_key and inside the unique dedupe_key.
    // Move the dedupe_key best-effort (IGNORE: the merged-into project may already
    // hold that alert), then move project_key unconditionally so no row is left
    // pointing at a project that no longer exists. An alert whose dedupe_key could
    // not move is stale by definition — the next Sync auto-resolves it.
    $uaKey = $db->prepare("UPDATE IGNORE alerts SET dedupe_key = REPLACE(dedupe_key, ?, ?) WHERE project_key = ?");
    $uaPk  = $db->prepare("UPDATE alerts SET project_key = ? WHERE project_key = ?");
    // Step dates are rebuilt by perf_sync — move what fits, drop what collides.
    $us = $db->prepare("UPDATE IGNORE project_step_dates SET project_key = ? WHERE project_key = ?");
    $ds = $db->prepare("DELETE FROM project_step_dates WHERE project_key = ?");
    foreach ($remap as $old => $new) {
        $uaKey->execute([$old, $new, $old]);
        $uaPk->execute([$new, $old]);
        $us->execute([$new, $old]);
        $ds->execute([$old]);
    }

    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) { $db->rollBack(); }
    fwrite(STDERR, "FAILED: " . $e->getMessage() . "\n");
    exit(1);
}

echo "\nDone. Now run the admin Sync (admin → Dashboard → Sync, or scripts/admin_sync.php)\n"
   . "to rebuild the project rollups on the merged keys.\n";
