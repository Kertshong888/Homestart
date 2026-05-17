<?php
// ============================================================
// match.php
// Volunteer-to-task matching algorithm.
//
// Input: task_postcode (required), plus optional refinements.
// Process:
//   1. Fetch all volunteers with their skills, transport, availability
//   2. For each volunteer + transport mode, call Google Maps Distance
//      Matrix API (or serve from cache) to get travel time in seconds
//   3. Score each volunteer out of 100:
//        Skills score      (max 50): based on how many skills they have
//        Availability score (max 30): based on total availability slots
//        Travel time score (max 20): based on fastest transport mode
//   4. Sort descending by total score and display the ranked list
//
// CACHING: Results are stored as JSON files in /cache/ for CACHE_TTL
// seconds (24h). The cache key is md5(origin_postcode + task_postcode + mode).
// This avoids hammering the Google API during repeated testing.
// ============================================================
session_start();
require_once 'dbconnection.php';
require_once 'audit.php';
require_once 'config.php';  // Loads GOOGLE_MAPS_API_KEY, CACHE_DIR, CACHE_TTL

// Staff-only page
if (empty($_SESSION['staff_id'])) {
    header('Location: staff_login.php');
    exit;
}

// Ensure cache directory exists and is writable.
if (!is_dir(CACHE_DIR)) {
    mkdir(CACHE_DIR, 0755, true);
}

// -----------------------------------------------------------
// TRANSPORT MODE MAPPING
// Maps our transport_name values to Google Maps API mode strings.
// -----------------------------------------------------------
$transport_mode_map = [
    'Walking'          => 'walking',
    'Cycling'          => 'bicycling',
    'Vehicle'          => 'driving',
    'Public Transport' => 'transit',
];

// -----------------------------------------------------------
// COLLECT INPUTS
// task_postcode is required. Optional: required_skill_ids, required_day.
// -----------------------------------------------------------
$task_postcode = strtoupper(trim($_POST['task_postcode'] ?? $_GET['task_postcode'] ?? ''));

// Optional additional criteria from a form on this page
$required_skill_ids = array_filter(
    array_map('intval', $_POST['required_skill_ids'] ?? []),
    fn($v) => $v > 0
);
$required_day = ($_POST['required_day'] ?? '') !== '' ? (int)$_POST['required_day'] : null;

// Validate postcode was provided
if (empty($task_postcode)) {
    header('Location: staff_dashboard.php');
    exit;
}

// -----------------------------------------------------------
// FETCH ALL VOLUNTEERS WITH THEIR PROFILE DATA
// One query with LEFT JOINs brings back all the data we need.
// GROUP_CONCAT aggregates skills and transports per volunteer.
// -----------------------------------------------------------
$vol_stmt = $pdo->query(
    "SELECT v.volunteer_id,
            v.volunteer_forename,
            v.volunteer_surname,
            v.postcode,
            GROUP_CONCAT(DISTINCT s.skill_name ORDER BY s.skill_name SEPARATOR ',') AS skill_names,
            COUNT(DISTINCT vs.skill_id)                                              AS skill_count,
            GROUP_CONCAT(DISTINCT t.transport_name ORDER BY t.transport_id SEPARATOR ',') AS transport_names,
            COUNT(DISTINCT a.availability_id)                                        AS avail_slot_count
     FROM volunteer v
     LEFT JOIN volunteer_skill     vs ON vs.volunteer_id  = v.volunteer_id
     LEFT JOIN skill               s  ON s.skill_id       = vs.skill_id
     LEFT JOIN volunteer_transport vt ON vt.volunteer_id  = v.volunteer_id
     LEFT JOIN transport           t  ON t.transport_id   = vt.transport_id
     LEFT JOIN availability        a  ON a.volunteer_id   = v.volunteer_id
     WHERE v.volunteer_forename IS NOT NULL
     GROUP BY v.volunteer_id, v.volunteer_forename, v.volunteer_surname, v.postcode"
);
$volunteers = $vol_stmt->fetchAll();

// -----------------------------------------------------------
// SCORING CONSTANTS (must sum to 100)
// -----------------------------------------------------------
const SCORE_SKILLS_MAX       = 50;
const SCORE_AVAILABILITY_MAX = 30;
const SCORE_TRAVEL_MAX       = 20;

// Travel time thresholds (seconds)
const TRAVEL_TIER_1 = 900;   // Under 15 min → 20 pts
const TRAVEL_TIER_2 = 1800;  // Under 30 min → 15 pts
const TRAVEL_TIER_3 = 2700;  // Under 45 min → 10 pts
const TRAVEL_TIER_4 = 3600;  // Under 60 min →  5 pts
                              // 60 min+      →  0 pts

// Max values used for normalising scores
// (5+ skills → full skills score; 5+ slots → full availability score)
const MAX_SKILLS_NORMALISER = 5;
const MAX_AVAIL_NORMALISER  = 5;

// -----------------------------------------------------------
// HELPER: get_travel_time($origin, $destination, $mode)
// Calls the Google Maps Distance Matrix API via cURL and caches the result.
// Returns an array: ['seconds' => int, 'error' => string|null]
//
// WHY cURL instead of file_get_contents:
//   XAMPP on Mac does not configure SSL certificates for file_get_contents,
//   so HTTPS requests silently fail. cURL has its own SSL handling and
//   works correctly out of the box on XAMPP.
// -----------------------------------------------------------
function get_travel_time(string $origin, string $destination, string $mode): array
{
    // Build a cache filename from a hash of the three inputs.
    $cache_key  = md5($origin . '_' . $destination . '_' . $mode);
    $cache_file = CACHE_DIR . $cache_key . '.json';

    // Serve from cache if it exists and is still fresh.
    if (file_exists($cache_file) && (time() - filemtime($cache_file)) < CACHE_TTL) {
        $cached = json_decode(file_get_contents($cache_file), true);
        if (isset($cached['duration_seconds'])) {
            return ['seconds' => (int)$cached['duration_seconds'], 'error' => null];
        }
    }

    // Build the API URL.
    $url = 'https://maps.googleapis.com/maps/api/distancematrix/json'
         . '?origins='      . urlencode($origin)
         . '&destinations=' . urlencode($destination)
         . '&mode='         . urlencode($mode)
         . '&key='          . urlencode(GOOGLE_MAPS_API_KEY);

    // Use cURL — reliable on XAMPP Mac where file_get_contents HTTPS fails.
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,   // return response as string
        CURLOPT_TIMEOUT        => 8,      // 8 second timeout
        CURLOPT_FOLLOWLOCATION => true,   // follow any redirects
        // SSL verification: set to true in production with a real cert bundle.
        // On XAMPP localhost, the CA bundle is often missing so we disable it.
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $response = curl_exec($ch);
    $curl_error = curl_error($ch);
    $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $curl_error) {
        return ['seconds' => PHP_INT_MAX, 'error' => 'cURL error: ' . $curl_error];
    }

    $data = json_decode($response, true);

    // Check top-level API status (e.g. REQUEST_DENIED means bad/missing key)
    if (isset($data['status']) && $data['status'] !== 'OK') {
        $msg = $data['status'];
        if (isset($data['error_message'])) {
            $msg .= ': ' . $data['error_message'];
        }
        return ['seconds' => PHP_INT_MAX, 'error' => 'API status: ' . $msg];
    }

    // Navigate the Distance Matrix response structure:
    // rows[0].elements[0].status should be 'OK'
    // rows[0].elements[0].duration.value = travel time in seconds
    $element = $data['rows'][0]['elements'][0] ?? null;

    if (!$element || $element['status'] !== 'OK') {
        $elem_status = $element['status'] ?? 'NO_ELEMENT';
        return ['seconds' => PHP_INT_MAX, 'error' => 'Element status: ' . $elem_status . ' (postcode may be unresolvable)'];
    }

    $duration_seconds = (int)$element['duration']['value'];

    // Cache the successful result.
    file_put_contents($cache_file, json_encode([
        'duration_seconds' => $duration_seconds,
        'cached_at'        => date('Y-m-d H:i:s'),
    ]));

    return ['seconds' => $duration_seconds, 'error' => null];
}

// -----------------------------------------------------------
// HELPER: travel_score($seconds)
// Converts travel time in seconds to a score out of 20.
// -----------------------------------------------------------
function travel_score(int $seconds): int
{
    if ($seconds === PHP_INT_MAX) return 0;
    if ($seconds < TRAVEL_TIER_1) return SCORE_TRAVEL_MAX;       // < 15 min
    if ($seconds < TRAVEL_TIER_2) return (int)(SCORE_TRAVEL_MAX * 0.75);  // < 30 min → 15
    if ($seconds < TRAVEL_TIER_3) return (int)(SCORE_TRAVEL_MAX * 0.5);   // < 45 min → 10
    if ($seconds < TRAVEL_TIER_4) return (int)(SCORE_TRAVEL_MAX * 0.25);  // < 60 min →  5
    return 0;
}

// -----------------------------------------------------------
// SCORE EACH VOLUNTEER
// -----------------------------------------------------------
$ranked = [];

foreach ($volunteers as $vol) {
    $vol_postcode   = $vol['postcode'];
    $skill_count    = (int)$vol['skill_count'];
    $avail_count    = (int)$vol['avail_slot_count'];
    $transport_list = $vol['transport_names'] ? explode(',', $vol['transport_names']) : [];

    // --- SKILLS SCORE (max 50) ---
    // If specific skills required, score = (matching / required) * 50.
    // Otherwise, reward volunteers with more skills (up to 5 = full score).
    if (!empty($required_skill_ids) && !empty($vol['skill_names'])) {
        $vol_skill_names_arr = explode(',', $vol['skill_names']);
        // Fetch this volunteer's skill IDs for comparison
        $sid_stmt = $pdo->prepare(
            'SELECT skill_id FROM volunteer_skill WHERE volunteer_id = ?'
        );
        $sid_stmt->execute([$vol['volunteer_id']]);
        $vol_skill_ids = array_column($sid_stmt->fetchAll(), 'skill_id');
        $matches = count(array_intersect($required_skill_ids, $vol_skill_ids));
        $skills_score = (int)round(($matches / count($required_skill_ids)) * SCORE_SKILLS_MAX);
    } else {
        // Normalise: 5 or more skills = full 50 points
        $skills_score = (int)min(
            SCORE_SKILLS_MAX,
            ($skill_count / MAX_SKILLS_NORMALISER) * SCORE_SKILLS_MAX
        );
    }

    // --- AVAILABILITY SCORE (max 30) ---
    // If a required day was given, check if the volunteer is available that day.
    // Otherwise, normalise by slot count.
    if ($required_day !== null) {
        $day_stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM availability WHERE volunteer_id = ? AND day = ?'
        );
        $day_stmt->execute([$vol['volunteer_id'], $required_day]);
        $avail_score = $day_stmt->fetchColumn() > 0 ? SCORE_AVAILABILITY_MAX : 0;
    } else {
        $avail_score = (int)min(
            SCORE_AVAILABILITY_MAX,
            ($avail_count / MAX_AVAIL_NORMALISER) * SCORE_AVAILABILITY_MAX
        );
    }

    // --- TRAVEL TIME SCORE (max 20) ---
    // Call Google Maps for each of the volunteer's transport modes.
    // Take the FASTEST (lowest seconds) to determine the score.
    $best_travel_seconds = PHP_INT_MAX;
    $best_mode           = 'No transport recorded';
    $api_debug_entries   = [];   // collects per-mode results for the debug panel

    foreach ($transport_list as $transport_name) {
        $transport_name = trim($transport_name);
        if ($transport_name === '') continue;

        $gm_mode = $transport_mode_map[$transport_name] ?? null;
        if ($gm_mode === null) {
            $api_debug_entries[] = $transport_name . ' → no mapping found';
            continue;
        }

        $result  = get_travel_time($vol_postcode, $task_postcode, $gm_mode);
        $seconds = $result['seconds'];
        $err     = $result['error'];

        if ($err) {
            $api_debug_entries[] = $transport_name . ' (' . $gm_mode . ') → ERROR: ' . $err;
        } else {
            $api_debug_entries[] = $transport_name . ' (' . $gm_mode . ') → ' . $seconds . 's (' . round($seconds/60, 1) . ' min)';
        }

        if ($seconds < $best_travel_seconds) {
            $best_travel_seconds = $seconds;
            $best_mode           = $transport_name;
        }
    }

    $t_score     = travel_score($best_travel_seconds);
    $total_score = $skills_score + $avail_score + $t_score;

    $ranked[] = [
        'volunteer_id'      => $vol['volunteer_id'],
        'name'              => $vol['volunteer_forename'] . ' ' . $vol['volunteer_surname'],
        'postcode'          => $vol_postcode,
        'skills'            => $vol['skill_names'] ?? 'None',
        'transports'        => $vol['transport_names'] ?? 'None',
        'skills_score'      => $skills_score,
        'avail_score'       => $avail_score,
        'travel_score'      => $t_score,
        'total_score'       => $total_score,
        'best_mode'         => $best_mode,
        'travel_seconds'    => $best_travel_seconds === PHP_INT_MAX ? 'API error' : $best_travel_seconds,
        'api_debug'         => $api_debug_entries,
    ];
}

// Sort descending by total_score (highest first).
usort($ranked, fn($a, $b) => $b['total_score'] <=> $a['total_score']);

write_audit_log(
    $pdo,
    null,
    'match_search',
    'Staff ran volunteer matching for task postcode: ' . $task_postcode
    . ' | Results: ' . count($ranked)
);

// Fetch lookup data for the optional refinement form on this page
$all_skills = $pdo->query('SELECT skill_id, skill_name FROM skill ORDER BY skill_name')->fetchAll();
$day_names  = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Volunteer Matching – Home-Start</title>
    <style>
        table { border-collapse: collapse; }
        th, td { padding: 6px 12px; border: 1px solid #ccc; }
        .score-high { background-color: #d4edda; }
        .score-mid  { background-color: #fff3cd; }
        .score-low  { background-color: #f8d7da; }
    </style>
</head>
<body>
    <h1>Volunteer Matching Results</h1>
    <p><a href="staff_dashboard.php">← Back to Dashboard</a></p>

    <p><strong>Task location:</strong> <?= htmlspecialchars($task_postcode) ?></p>

    <!-- Optional refinement form -->
    <details>
        <summary>Refine matching criteria</summary>
        <form method="POST" action="match.php">
            <input type="hidden" name="task_postcode" value="<?= htmlspecialchars($task_postcode) ?>">

            <fieldset>
                <legend>Required Skills (optional)</legend>
                <?php foreach ($all_skills as $skill): ?>
                    <label>
                        <input type="checkbox"
                               name="required_skill_ids[]"
                               value="<?= (int)$skill['skill_id'] ?>"
                               <?= in_array($skill['skill_id'], $required_skill_ids) ? 'checked' : '' ?>>
                        <?= htmlspecialchars($skill['skill_name']) ?>
                    </label><br>
                <?php endforeach; ?>
            </fieldset>

            <br>
            <label for="req_day">Required availability day (optional):</label><br>
            <select id="req_day" name="required_day">
                <option value="">-- Any day --</option>
                <?php foreach ($day_names as $i => $name): ?>
                    <option value="<?= $i ?>"
                        <?= $required_day === $i ? 'selected' : '' ?>>
                        <?= htmlspecialchars($name) ?>
                    </option>
                <?php endforeach; ?>
            </select><br><br>

            <button type="submit">Re-run Match</button>
        </form>
    </details>

    <br>

    <p><strong><?= count($ranked) ?> volunteer(s) ranked.</strong></p>
    <p><em>Scoring: Skills 50pts | Availability 30pts | Travel time 20pts</em></p>

    <?php if (!empty($ranked)): ?>
    <table>
        <thead>
            <tr>
                <th>Rank</th>
                <th>Volunteer</th>
                <th>Postcode</th>
                <th>Skills</th>
                <th>Best Transport</th>
                <th>Travel (sec)</th>
                <th>Skills Score</th>
                <th>Avail Score</th>
                <th>Travel Score</th>
                <th>TOTAL</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($ranked as $rank => $vol): ?>
            <?php
                $css_class = $vol['total_score'] >= 70 ? 'score-high'
                           : ($vol['total_score'] >= 40 ? 'score-mid' : 'score-low');
            ?>
            <tr class="<?= $css_class ?>">
                <td><?= $rank + 1 ?></td>
                <td><?= htmlspecialchars($vol['name']) ?></td>
                <td><?= htmlspecialchars($vol['postcode']) ?></td>
                <td><?= htmlspecialchars($vol['skills']) ?></td>
                <td><?= htmlspecialchars($vol['best_mode']) ?></td>
                <td>
                    <?php if (is_int($vol['travel_seconds'])): ?>
                        <?= (int)$vol['travel_seconds'] ?>s
                        (<?= round($vol['travel_seconds'] / 60, 1) ?> min)
                    <?php else: ?>
                        <?= htmlspecialchars($vol['travel_seconds']) ?>
                    <?php endif; ?>
                </td>
                <td><?= (int)$vol['skills_score'] ?> / <?= SCORE_SKILLS_MAX ?></td>
                <td><?= (int)$vol['avail_score']  ?> / <?= SCORE_AVAILABILITY_MAX ?></td>
                <td><?= (int)$vol['travel_score'] ?> / <?= SCORE_TRAVEL_MAX ?></td>
                <td><strong><?= (int)$vol['total_score'] ?></strong> / 100</td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <br>
    <p>
        <strong>Colour key:</strong>
        <span style="background:#d4edda;padding:2px 8px;">Green = 70+ (strong match)</span>
        <span style="background:#fff3cd;padding:2px 8px;">Yellow = 40–69 (possible)</span>
        <span style="background:#f8d7da;padding:2px 8px;">Red = below 40 (weak match)</span>
    </p>
    <?php else: ?>
        <p>No volunteers with completed profiles found.</p>
    <?php endif; ?>

    <?php if (GOOGLE_MAPS_API_KEY === 'YOUR_API_KEY_HERE'): ?>
    <div style="background:#fff3cd;padding:10px;margin-top:20px;">
        <strong>⚠ Google Maps API key not configured.</strong>
        Travel scores are 0 for all volunteers.
        Add your key to <code>config.php</code>.
    </div>
    <?php endif; ?>

    <!-- ------------------------------------------------
         DEBUG PANEL — shows exactly what the API returned for each
         volunteer and transport mode. Remove this section once
         everything is working correctly.
         ------------------------------------------------ -->
    <details style="margin-top:30px; border:1px solid #ccc; padding:10px;">
        <summary><strong>🔧 API Debug Panel</strong> (click to expand — remove before submission)</summary>
        <p>
            <strong>cURL enabled:</strong> <?= function_exists('curl_init') ? '<span style="color:green">YES</span>' : '<span style="color:red">NO — enable php_curl in XAMPP php.ini</span>' ?><br>
            <strong>API Key set:</strong> <?= GOOGLE_MAPS_API_KEY !== 'YOUR_API_KEY_HERE' ? '<span style="color:green">YES</span>' : '<span style="color:red">NO</span>' ?><br>
            <strong>Cache directory:</strong> <?= htmlspecialchars(CACHE_DIR) ?>
            (<?= is_writable(CACHE_DIR) ? '<span style="color:green">writable</span>' : '<span style="color:red">NOT writable — run: mkdir cache && chmod 755 cache</span>' ?>)
        </p>

        <?php foreach ($ranked as $vol): ?>
        <div style="border-top:1px solid #eee; padding:8px 0;">
            <strong><?= htmlspecialchars($vol['name']) ?></strong>
            (<?= htmlspecialchars($vol['postcode']) ?>)
            — Transport in DB: <em><?= htmlspecialchars($vol['transports']) ?></em>
            <ul style="margin:4px 0;">
                <?php if (empty($vol['api_debug'])): ?>
                    <li style="color:orange;">No transport modes found in database for this volunteer</li>
                <?php else: ?>
                    <?php foreach ($vol['api_debug'] as $entry): ?>
                        <li style="<?= strpos($entry, 'ERROR') !== false ? 'color:red' : 'color:green' ?>">
                            <?= htmlspecialchars($entry) ?>
                        </li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </div>
        <?php endforeach; ?>

        <hr>
        <p style="font-size:0.85em; color:#666;">
            Common errors:<br>
            <strong>REQUEST_DENIED</strong> → Distance Matrix API not enabled in Google Cloud Console<br>
            <strong>cURL error: SSL</strong> → Already handled (SSL verification disabled for localhost)<br>
            <strong>Element status: NOT_FOUND</strong> → Postcode format not recognised by Google Maps (try a full UK postcode like "BN1 1AA")<br>
            <strong>No transport recorded</strong> → Volunteer has no rows in volunteer_transport table — check DB
        </p>
    </details>
</body>
</html>
