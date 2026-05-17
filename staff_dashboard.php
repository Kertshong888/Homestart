<?php
// ============================================================
// staff_dashboard.php — Staff portal (complete redesign)
//
// Layout: 3-column grid matching teammate's style2.css
//   LEFT   → Skills list + Search/filter form
//   CENTRE → Logo + scrollable results (search OR match)
//   RIGHT  → Match by postcode + Audit log link
//
// All results stay on this page — no redirects (per spec change).
// Search uses GET so results survive a reload.
// ============================================================
session_start();
require_once 'dbconnection.php';
require_once 'audit.php';
require_once 'config.php';

if (empty($_SESSION['staff_id'])) {
    header('Location: staff_login.php');
    exit;
}

$day_names = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];

// -----------------------------------------------------------
// TRANSPORT MODE MAP for Google Maps API
// -----------------------------------------------------------
$transport_mode_map = [
    'Walking'          => 'walking',
    'Cycling'          => 'bicycling',
    'Vehicle'          => 'driving',
    'Public Transport' => 'transit',
];

// -----------------------------------------------------------
// HELPER: cURL-based Google Maps Distance Matrix call with cache
// -----------------------------------------------------------
function get_travel_time_dash(string $origin, string $dest, string $mode): array
{
    $cache_key  = md5($origin . '_' . $dest . '_' . $mode);
    $cache_file = CACHE_DIR . $cache_key . '.json';

    if (file_exists($cache_file) && (time() - filemtime($cache_file)) < CACHE_TTL) {
        $c = json_decode(file_get_contents($cache_file), true);
        if (isset($c['duration_seconds'])) {
            return ['seconds' => (int)$c['duration_seconds'], 'error' => null];
        }
    }

    $url = 'https://maps.googleapis.com/maps/api/distancematrix/json'
         . '?origins='      . urlencode($origin)
         . '&destinations=' . urlencode($dest)
         . '&mode='         . urlencode($mode)
         . '&key='          . urlencode(GOOGLE_MAPS_API_KEY);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $response = curl_exec($ch);
    $err      = curl_error($ch);
    curl_close($ch);

    if (!$response || $err) {
        return ['seconds' => PHP_INT_MAX, 'error' => 'cURL: ' . $err];
    }

    $data = json_decode($response, true);
    if (($data['status'] ?? '') !== 'OK') {
        return ['seconds' => PHP_INT_MAX, 'error' => $data['status'] ?? 'Unknown'];
    }

    $el = $data['rows'][0]['elements'][0] ?? null;
    if (!$el || $el['status'] !== 'OK') {
        return ['seconds' => PHP_INT_MAX, 'error' => 'Element: ' . ($el['status'] ?? 'none')];
    }

    $secs = (int)$el['duration']['value'];
    file_put_contents($cache_file, json_encode(['duration_seconds' => $secs]));
    return ['seconds' => $secs, 'error' => null];
}

function travel_score_dash(int $s): int
{
    if ($s === PHP_INT_MAX) return 0;
    if ($s < 900)  return 20;
    if ($s < 1800) return 15;
    if ($s < 2700) return 10;
    if ($s < 3600) return 5;
    return 0;
}

// -----------------------------------------------------------
// FETCH LOOKUP DATA (always needed for the left panel)
// -----------------------------------------------------------
$all_skills     = $pdo->query('SELECT skill_id, skill_name FROM skill ORDER BY skill_name')->fetchAll();
$all_transports = $pdo->query('SELECT transport_id, transport_name FROM transport ORDER BY transport_id')->fetchAll();
$total_vols     = (int)$pdo->query('SELECT COUNT(*) FROM volunteer WHERE volunteer_forename IS NOT NULL')->fetchColumn();

// -----------------------------------------------------------
// DETERMINE MODE: search | match | (empty = welcome screen)
// -----------------------------------------------------------
$mode           = $_GET['mode'] ?? '';
$search_results = [];
$match_results  = [];
$task_postcode  = '';

// ============================================================
// SEARCH MODE — filter volunteers, show in centre panel
// ============================================================
if ($mode === 'search') {

    $skill_ids      = array_filter(array_map('intval', $_GET['skill_ids'] ?? []), fn($v) => $v > 0);
    $postcode_q     = trim($_GET['postcode'] ?? '');
    $day_q          = ($_GET['day'] ?? '') !== '' ? (int)$_GET['day'] : null;
    $start_q        = trim($_GET['start_time'] ?? '');
    $end_q          = trim($_GET['end_time'] ?? '');
    $transport_q    = ($_GET['transport_id'] ?? '') !== '' ? (int)$_GET['transport_id'] : null;

    $conditions = ['v.volunteer_forename IS NOT NULL'];
    $params     = [];

    if (!empty($skill_ids)) {
        $ph = implode(',', array_fill(0, count($skill_ids), '?'));
        $conditions[] = "v.volunteer_id IN (
            SELECT vs.volunteer_id FROM volunteer_skill vs
            WHERE vs.skill_id IN ($ph)
            GROUP BY vs.volunteer_id
            HAVING COUNT(DISTINCT vs.skill_id) = ?
        )";
        foreach ($skill_ids as $s) $params[] = $s;
        $params[] = count($skill_ids);
    }
    if ($postcode_q !== '') {
        $conditions[] = 'v.postcode LIKE ?';
        $params[]     = $postcode_q . '%';
    }
    if ($day_q !== null) {
        $conditions[] = 'v.volunteer_id IN (SELECT a.volunteer_id FROM availability a WHERE a.day = ?)';
        $params[]     = $day_q;
    }
    if ($start_q !== '') {
        $conditions[] = 'v.volunteer_id IN (SELECT a.volunteer_id FROM availability a WHERE a.start_time <= ?)';
        $params[]     = $start_q;
    }
    if ($end_q !== '') {
        $conditions[] = 'v.volunteer_id IN (SELECT a.volunteer_id FROM availability a WHERE a.end_time >= ?)';
        $params[]     = $end_q;
    }
    if ($transport_q !== null && $transport_q > 0) {
        $conditions[] = 'v.volunteer_id IN (SELECT vt.volunteer_id FROM volunteer_transport vt WHERE vt.transport_id = ?)';
        $params[]     = $transport_q;
    }

    $where = 'WHERE ' . implode(' AND ', $conditions);
    $sql = "SELECT v.volunteer_id, v.volunteer_forename, v.volunteer_surname, v.postcode,
                   GROUP_CONCAT(DISTINCT s.skill_name ORDER BY s.skill_name SEPARATOR ', ')       AS skills,
                   GROUP_CONCAT(DISTINCT t.transport_name ORDER BY t.transport_id SEPARATOR ', ') AS transports
            FROM volunteer v
            LEFT JOIN volunteer_skill     vs ON vs.volunteer_id = v.volunteer_id
            LEFT JOIN skill               s  ON s.skill_id      = vs.skill_id
            LEFT JOIN volunteer_transport vt ON vt.volunteer_id = v.volunteer_id
            LEFT JOIN transport           t  ON t.transport_id  = vt.transport_id
            $where
            GROUP BY v.volunteer_id, v.volunteer_forename, v.volunteer_surname, v.postcode
            ORDER BY v.volunteer_surname";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $search_results = $stmt->fetchAll();

    write_audit_log($pdo, null, 'staff_search', 'Results: ' . count($search_results));
}

// ============================================================
// MATCH MODE — score and rank volunteers by task postcode
// ============================================================
if ($mode === 'match') {
    $task_postcode    = strtoupper(trim($_GET['task_postcode'] ?? ''));
    $req_skill_ids    = array_filter(array_map('intval', $_GET['req_skills'] ?? []), fn($v) => $v > 0);
    $req_day          = ($_GET['req_day'] ?? '') !== '' ? (int)$_GET['req_day'] : null;

    if ($task_postcode) {
        $vol_stmt = $pdo->query(
            "SELECT v.volunteer_id, v.volunteer_forename, v.volunteer_surname, v.postcode,
                    GROUP_CONCAT(DISTINCT s.skill_name  ORDER BY s.skill_name  SEPARATOR ',') AS skill_names,
                    COUNT(DISTINCT vs.skill_id)                                               AS skill_count,
                    GROUP_CONCAT(DISTINCT t.transport_name ORDER BY t.transport_id SEPARATOR ',') AS transport_names,
                    COUNT(DISTINCT a.availability_id)                                         AS avail_count
             FROM volunteer v
             LEFT JOIN volunteer_skill     vs ON vs.volunteer_id = v.volunteer_id
             LEFT JOIN skill               s  ON s.skill_id      = vs.skill_id
             LEFT JOIN volunteer_transport vt ON vt.volunteer_id = v.volunteer_id
             LEFT JOIN transport           t  ON t.transport_id  = vt.transport_id
             LEFT JOIN availability        a  ON a.volunteer_id  = v.volunteer_id
             WHERE v.volunteer_forename IS NOT NULL
             GROUP BY v.volunteer_id, v.volunteer_forename, v.volunteer_surname, v.postcode"
        );
        $volunteers = $vol_stmt->fetchAll();

        global $transport_mode_map;
        foreach ($volunteers as $vol) {
            // Skills score (50 pts)
            if (!empty($req_skill_ids)) {
                $sid_stmt = $pdo->prepare('SELECT skill_id FROM volunteer_skill WHERE volunteer_id = ?');
                $sid_stmt->execute([$vol['volunteer_id']]);
                $vol_sids = array_column($sid_stmt->fetchAll(), 'skill_id');
                $matches  = count(array_intersect($req_skill_ids, $vol_sids));
                $skills_score = (int)round(($matches / count($req_skill_ids)) * 50);
            } else {
                $skills_score = (int)min(50, ((int)$vol['skill_count'] / 5) * 50);
            }

            // Availability score (30 pts)
            if ($req_day !== null) {
                $ds = $pdo->prepare('SELECT COUNT(*) FROM availability WHERE volunteer_id = ? AND day = ?');
                $ds->execute([$vol['volunteer_id'], $req_day]);
                $avail_score = $ds->fetchColumn() > 0 ? 30 : 0;
            } else {
                $avail_score = (int)min(30, ((int)$vol['avail_count'] / 5) * 30);
            }

            // Travel score (20 pts)
            $best_secs = PHP_INT_MAX;
            $best_mode_name = 'None';
            $transport_list = $vol['transport_names'] ? explode(',', $vol['transport_names']) : [];
            foreach ($transport_list as $tn) {
                $tn     = trim($tn);
                $gm_mode = $transport_mode_map[$tn] ?? null;
                if (!$gm_mode) continue;
                $res = get_travel_time_dash($vol['postcode'], $task_postcode, $gm_mode);
                if ($res['seconds'] < $best_secs) {
                    $best_secs      = $res['seconds'];
                    $best_mode_name = $tn;
                }
            }
            $travel_score = travel_score_dash($best_secs);
            $total_score  = $skills_score + $avail_score + $travel_score;

            $match_results[] = [
                'name'         => htmlspecialchars($vol['volunteer_forename'] . ' ' . $vol['volunteer_surname']),
                'postcode'     => htmlspecialchars($vol['postcode']),
                'skills'       => htmlspecialchars($vol['skill_names'] ?? 'None'),
                'best_mode'    => htmlspecialchars($best_mode_name),
                'travel_secs'  => $best_secs === PHP_INT_MAX ? '—' : $best_secs . 's (' . round($best_secs/60,1) . ' min)',
                'skills_score' => $skills_score,
                'avail_score'  => $avail_score,
                'travel_score' => $travel_score,
                'total'        => $total_score,
                'css_class'    => $total_score >= 70 ? 'row-high' : ($total_score >= 40 ? 'row-mid' : 'row-low'),
            ];
        }
        usort($match_results, fn($a, $b) => $b['total'] <=> $a['total']);
        write_audit_log($pdo, null, 'match_search', 'Task postcode: ' . $task_postcode . ' | Results: ' . count($match_results));
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Dashboard – Home-Start</title>
    <link rel="stylesheet" href="style2.css">
    <style>
        /* Extra styles on top of teammate's style2.css */
        body { font-family: Verdana, Geneva, Tahoma, sans-serif; }

        /* Override grid rows: 3 rows — header, main, footer */
        .container {
            grid-template-rows: fit-content(100%) 1fr;
            height: calc(100dvh - 16px);
        }

        h3 { color: #552682; font-size: 0.95rem; margin: 0.6rem 0 0.4rem; border-bottom: 2px solid #ea580d; padding-bottom: 0.25rem; }
        label { font-size: 0.82rem; display:block; margin-top:0.5rem; color:#333; }
        input[type="text"], input[type="time"], select {
            width: 100%; padding: 0.3rem 0.5rem; border: 1px solid #ccc;
            border-radius: 4px; font-size: 0.82rem; margin-top: 0.2rem;
        }
        .btn { padding: 0.35rem 0.9rem; border: none; border-radius: 4px; cursor: pointer;
               font-size: 0.82rem; font-family: inherit; }
        .btn-purple { background: #552682; color: #fff; margin-top: 0.5rem; width: 100%; }
        .btn-orange { background: #ea580d; color: #fff; margin-top: 0.5rem; width: 100%; }
        .btn:hover { opacity: 0.85; }

        .skill-chip {
            display: inline-block; background: #552682; color: #fff;
            border-radius: 20px; padding: 0.15rem 0.6rem;
            font-size: 0.75rem; margin: 0.15rem;
        }

        /* Scrollable centre panel results */
        .result-card {
            background: #fff; border: 1px solid #d0bde8; border-radius: 6px;
            padding: 0.6rem 0.8rem; margin-bottom: 0.5rem; font-size: 0.82rem;
        }
        .result-card strong { color: #552682; }
        .result-card .vol-postcode { color: #888; font-size: 0.78rem; }
        .result-card .vol-skills { margin-top:0.2rem; }

        /* Match result rows */
        .match-card { border-radius:6px; padding:0.6rem 0.8rem; margin-bottom:0.5rem; font-size:0.82rem; }
        .row-high { background:#d4edda; border:1px solid #a8d5b5; }
        .row-mid  { background:#fff3cd; border:1px solid #f0d080; }
        .row-low  { background:#f8d7da; border:1px solid #e0a0a8; }
        .score-bar { display:flex; gap:0.4rem; margin-top:0.4rem; font-size:0.75rem; }
        .score-pill { background:rgba(0,0,0,0.12); border-radius:10px; padding:0.1rem 0.5rem; }

        .rank-badge {
            display:inline-block; width:22px; height:22px; border-radius:50%;
            background:#552682; color:#fff; text-align:center; line-height:22px;
            font-size:0.75rem; font-weight:bold; margin-right:0.4rem;
        }
        .rank-badge.gold   { background:#d4a000; }
        .rank-badge.silver { background:#888; }
        .rank-badge.bronze { background:#a0522d; }

        /* Right panel links */
        .audit-link {
            display: block; margin-top: 1rem; text-align: center;
            background: #552682; color: #fff; padding: 0.4rem 0.8rem;
            border-radius: 5px; text-decoration: none; font-size: 0.82rem;
        }
        .audit-link:hover { opacity: 0.85; }

        /* Welcome screen in centre */
        .centre-welcome {
            display: flex; align-items: center; justify-content: center;
            height: 100%; flex-direction: column; color: #aaa; font-size: 0.88rem;
        }
        .centre-welcome svg { margin-bottom: 0.8rem; opacity: 0.3; }

        /* Logout nav */
        .staff-nav {
            font-size: 0.78rem; color: #666; margin-bottom: 0.3rem;
            display: flex; justify-content: space-between; align-items: center;
        }
        .staff-nav a { color: #552682; text-decoration: none; }
    </style>
</head>
<body>

<div class="container">

    <!-- ===================================================
         ROW 1: Header row (3 cells)
         =================================================== -->

    <!-- Top-left: panel heading -->
    <div style="align-content:center;">
        <p style="font-size:1rem;font-weight:bold;color:#552682;text-align:center;margin:0;">
            Volunteer Filter
        </p>
        <p style="font-size:0.75rem;color:#888;text-align:center;margin:0;">
            <?= (int)$total_vols ?> volunteers registered
        </p>
    </div>

    <!-- Top-centre: Logo -->
    <div style="text-align:center; align-content:center;">
        <img src="Home-Start-Logo.png" alt="Home-Start" style="width:18%;display:inline-block;margin:0;">
    </div>

    <!-- Top-right: heading -->
    <div style="align-content:center;">
        <p style="font-size:1rem;font-weight:bold;color:#552682;text-align:center;margin:0;">
            Task Matching
        </p>
        <p style="font-size:0.75rem;color:#888;text-align:center;margin:0;">
            Logged in: <?= htmlspecialchars($_SESSION['staff_username'] ?? '') ?>
            &nbsp;|&nbsp; <a href="staff_logout.php" style="color:#ea580d;font-size:0.75rem;">Logout</a>
        </p>
    </div>

    <!-- ===================================================
         ROW 2: Main content row
         =================================================== -->

    <!-- LEFT: Skills list + Search filters -->
    <div style="display:flex; flex-direction:column; overflow-y:auto;">

        <!-- Skills list (spec change: just list skills, no gap detection) -->
        <h3>Skills Available</h3>
        <div style="margin-bottom:0.5rem;">
            <?php foreach ($all_skills as $skill): ?>
                <span class="skill-chip"><?= htmlspecialchars($skill['skill_name']) ?></span>
            <?php endforeach; ?>
        </div>

        <hr style="border:none;border-top:1px solid #d0bde8;margin:0.6rem 0;">

        <!-- Search / filter form — submits GET to same page -->
        <form method="GET" action="staff_dashboard.php">
            <input type="hidden" name="mode" value="search">

            <h3>Search Volunteers</h3>

            <label>Filter by Skills:</label>
            <div style="max-height:100px;overflow-y:auto;background:#fff;border:1px solid #ccc;border-radius:4px;padding:0.3rem;margin-top:0.2rem;">
                <?php foreach ($all_skills as $skill): ?>
                    <label style="display:flex;align-items:center;gap:0.3rem;margin:0.1rem 0;font-size:0.8rem;">
                        <input type="checkbox" name="skill_ids[]"
                               value="<?= (int)$skill['skill_id'] ?>"
                               style="width:auto;accent-color:#552682;"
                               <?= in_array($skill['skill_id'], array_map('intval', $_GET['skill_ids'] ?? [])) ? 'checked' : '' ?>>
                        <?= htmlspecialchars($skill['skill_name']) ?>
                    </label>
                <?php endforeach; ?>
            </div>

            <label>Postcode (starts with):</label>
            <input type="text" name="postcode"
                   value="<?= htmlspecialchars($_GET['postcode'] ?? '') ?>"
                   placeholder="e.g. BN1">

            <label>Available on day:</label>
            <select name="day">
                <option value="">— Any day —</option>
                <?php foreach ($day_names as $i => $dn): ?>
                    <option value="<?= $i ?>" <?= (($_GET['day'] ?? '') == $i) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($dn) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <!-- Spec change 5: simple day+time submission box for availability -->
            <label>Available from:</label>
            <input type="time" name="start_time" value="<?= htmlspecialchars($_GET['start_time'] ?? '') ?>">

            <label>Available until:</label>
            <input type="time" name="end_time" value="<?= htmlspecialchars($_GET['end_time'] ?? '') ?>">

            <label>Transport mode:</label>
            <select name="transport_id">
                <option value="">— Any —</option>
                <?php foreach ($all_transports as $t): ?>
                    <option value="<?= (int)$t['transport_id'] ?>"
                            <?= (($_GET['transport_id'] ?? '') == $t['transport_id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($t['transport_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="btn btn-purple">Search Volunteers</button>
        </form>

    </div>

    <!-- CENTRE: Scrollable results box -->
    <div class="scroll" style="border-width:0.3vmin; display:flex; flex-direction:column;">

        <?php if ($mode === 'search'): ?>
            <!-- SEARCH RESULTS -->
            <p style="font-size:0.82rem;color:#666;margin-bottom:0.5rem;">
                <strong><?= count($search_results) ?></strong> volunteer(s) found
                <?php if (!empty($_GET['postcode'])): ?>
                    in postcode <strong><?= htmlspecialchars($_GET['postcode']) ?></strong>
                <?php endif; ?>
            </p>

            <?php if (empty($search_results)): ?>
                <div class="centre-welcome">
                    <p>No volunteers match these filters.</p>
                </div>
            <?php else: ?>
                <?php foreach ($search_results as $r): ?>
                <div class="result-card">
                    <strong><?= htmlspecialchars($r['volunteer_forename'] . ' ' . $r['volunteer_surname']) ?></strong>
                    <span class="vol-postcode">&nbsp;(<?= htmlspecialchars($r['postcode']) ?>)</span>
                    <div class="vol-skills">
                        <?php
                        $skills_arr = $r['skills'] ? explode(', ', $r['skills']) : [];
                        foreach ($skills_arr as $sk):
                        ?>
                            <span class="skill-chip"><?= htmlspecialchars(trim($sk)) ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($r['transports']): ?>
                    <div style="font-size:0.75rem;color:#666;margin-top:0.2rem;">
                        Transport: <?= htmlspecialchars($r['transports']) ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>

        <?php elseif ($mode === 'match'): ?>
            <!-- MATCH RESULTS -->
            <p style="font-size:0.82rem;color:#666;margin-bottom:0.5rem;">
                Best volunteers for task at <strong><?= htmlspecialchars($task_postcode) ?></strong>
                — <strong><?= count($match_results) ?></strong> ranked
            </p>
            <p style="font-size:0.75rem;color:#aaa;margin-bottom:0.6rem;">
                Skills 50pts &nbsp;|&nbsp; Availability 30pts &nbsp;|&nbsp; Travel time 20pts
            </p>

            <?php foreach ($match_results as $rank => $vol): ?>
            <div class="match-card <?= $vol['css_class'] ?>">
                <div>
                    <?php
                    $badge_class = $rank === 0 ? 'gold' : ($rank === 1 ? 'silver' : ($rank === 2 ? 'bronze' : ''));
                    ?>
                    <span class="rank-badge <?= $badge_class ?>"><?= $rank + 1 ?></span>
                    <?= $vol['name'] ?>
                    <span style="float:right;font-weight:bold;"><?= $vol['total'] ?>/100</span>
                </div>
                <div style="font-size:0.75rem;color:#666;"><?= $vol['postcode'] ?></div>
                <div style="font-size:0.75rem;margin-top:0.2rem;"><?= $vol['skills'] ?></div>
                <div class="score-bar">
                    <span class="score-pill">Skills <?= $vol['skills_score'] ?>/50</span>
                    <span class="score-pill">Avail <?= $vol['avail_score'] ?>/30</span>
                    <span class="score-pill">Travel <?= $vol['travel_score'] ?>/20</span>
                    <span class="score-pill" style="color:#555;"><?= $vol['best_mode'] ?> · <?= $vol['travel_secs'] ?></span>
                </div>
            </div>
            <?php endforeach; ?>

            <?php if (GOOGLE_MAPS_API_KEY === 'YOUR_API_KEY_HERE'): ?>
            <div style="background:#fff3cd;padding:0.5rem;border-radius:4px;font-size:0.78rem;margin-top:0.5rem;">
                ⚠ Google Maps API key not configured. Travel scores are 0.
                Add your key to <code>config.php</code>.
            </div>
            <?php endif; ?>

        <?php else: ?>
            <!-- DEFAULT: welcome message -->
            <div class="centre-welcome">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
                </svg>
                <p>Use the <strong>Search</strong> panel on the left<br>or enter a postcode on the right to match.</p>
            </div>
        <?php endif; ?>

    </div>

    <!-- RIGHT: Match by postcode + Audit log -->
    <div style="display:flex; flex-direction:column;">

        <!-- Match form — results appear in centre panel -->
        <form method="GET" action="staff_dashboard.php">
            <input type="hidden" name="mode" value="match">

            <h3>Match to Task</h3>

            <label>Task postcode:</label>
            <input type="text" name="task_postcode"
                   value="<?= htmlspecialchars($_GET['task_postcode'] ?? '') ?>"
                   placeholder="e.g. BN1 1AA" required>

            <!-- Optional refinement -->
            <label>Require skill (optional):</label>
            <div style="max-height:80px;overflow-y:auto;background:#fff;border:1px solid #ccc;border-radius:4px;padding:0.3rem;margin-top:0.2rem;">
                <?php foreach ($all_skills as $skill): ?>
                    <label style="display:flex;align-items:center;gap:0.3rem;margin:0.1rem 0;font-size:0.78rem;">
                        <input type="checkbox" name="req_skills[]"
                               value="<?= (int)$skill['skill_id'] ?>"
                               style="width:auto;accent-color:#ea580d;"
                               <?= in_array($skill['skill_id'], array_map('intval', $_GET['req_skills'] ?? [])) ? 'checked' : '' ?>>
                        <?= htmlspecialchars($skill['skill_name']) ?>
                    </label>
                <?php endforeach; ?>
            </div>

            <label>Required day (optional):</label>
            <select name="req_day">
                <option value="">— Any —</option>
                <?php foreach ($day_names as $i => $dn): ?>
                    <option value="<?= $i ?>" <?= (($_GET['req_day'] ?? '') == $i) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($dn) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="btn btn-orange">Find Best Volunteer</button>
        </form>

        <!-- Audit log hyperlink (spec change 6) -->
        <a href="audit_log_view.php" class="audit-link">View Audit Log &rarr;</a>

        <!-- Colour legend for match scores -->
        <div style="margin-top:1rem; font-size:0.72rem; line-height:1.8;">
            <div style="display:flex;gap:0.4rem;align-items:center;">
                <span style="width:12px;height:12px;background:#d4edda;border:1px solid #a8d5b5;display:inline-block;"></span> 70+ strong match
            </div>
            <div style="display:flex;gap:0.4rem;align-items:center;">
                <span style="width:12px;height:12px;background:#fff3cd;border:1px solid #f0d080;display:inline-block;"></span> 40–69 possible
            </div>
            <div style="display:flex;gap:0.4rem;align-items:center;">
                <span style="width:12px;height:12px;background:#f8d7da;border:1px solid #e0a0a8;display:inline-block;"></span> below 40 weak
            </div>
        </div>
    </div>

</div><!-- /.container -->
</body>
</html>
