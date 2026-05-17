<?php
// ============================================================
// search.php
// Receives POST filter parameters from staff_dashboard.php and
// returns matching volunteers.
//
// KEY INTERVIEW POINT — "no string concatenation in SQL":
// The WHERE clause structure (the ? placeholders and AND keywords)
// is built by OUR code — no user input ever touches the SQL string.
// User-supplied VALUES are always bound as PDO parameters.
// This means even if a user sends "1 OR 1=1" as a skill_id,
// intval() makes it 1 (or 0) and it's bound as a parameter, not
// spliced into the SQL. SQL injection is impossible.
// ============================================================
session_start();
require_once 'dbconnection.php';
require_once 'audit.php';

// Staff-only page
if (empty($_SESSION['staff_id'])) {
    header('Location: staff_login.php');
    exit;
}

// Only process if this is a POST from the dashboard
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: staff_dashboard.php');
    exit;
}

// -----------------------------------------------------------
// COLLECT FILTER PARAMETERS
// -----------------------------------------------------------

// Skill IDs — cast to int, remove zeros
$skill_ids = array_filter(
    array_map('intval', $_POST['skill_ids'] ?? []),
    fn($v) => $v > 0
);

// Postcode search string — sanitise but do not cast (it's a string)
$postcode_filter = trim($_POST['postcode'] ?? '');

// Day filter — integer 0-6 or null
$day_raw    = $_POST['day'] ?? '';
$day_filter = ($day_raw !== '') ? (int)$day_raw : null;

// Time filters
$start_filter = trim($_POST['start_time'] ?? '');
$end_filter   = trim($_POST['end_time']   ?? '');

// Transport ID — single value
$transport_raw = $_POST['transport_id'] ?? '';
$transport_filter = ($transport_raw !== '') ? (int)$transport_raw : null;

// -----------------------------------------------------------
// DYNAMIC WHERE CLAUSE BUILDER
//
// We build two parallel arrays:
//   $conditions — SQL fragments with ? placeholders (no user data)
//   $params     — corresponding bound values (user data goes here only)
//
// implode(' AND ', $conditions) joins the fragments safely.
// This is the correct way to build dynamic queries with PDO.
// -----------------------------------------------------------
$conditions = [];
$params     = [];

// --- Skills filter ---
// If skills were selected, the volunteer must have ALL of them.
// We use a subquery with COUNT to require each skill.
if (!empty($skill_ids)) {
    // The number of ? marks equals the number of selected skills.
    // array_fill(0, count($skill_ids), '?') builds ['?','?',...].
    $placeholders = implode(',', array_fill(0, count($skill_ids), '?'));
    $conditions[] = "v.volunteer_id IN (
                         SELECT vs.volunteer_id
                         FROM volunteer_skill vs
                         WHERE vs.skill_id IN ($placeholders)
                         GROUP BY vs.volunteer_id
                         HAVING COUNT(DISTINCT vs.skill_id) = ?
                     )";
    // Push all skill IDs as params, then the count as the HAVING value
    foreach ($skill_ids as $sid) {
        $params[] = $sid;
    }
    $params[] = count($skill_ids);
}

// --- Postcode filter (partial match using LIKE) ---
// We use ? as the placeholder; the % wildcard is attached to
// the PHP variable before binding — never inside the SQL string.
if ($postcode_filter !== '') {
    $conditions[] = 'v.postcode LIKE ?';
    $params[]     = $postcode_filter . '%';  // starts-with match
}

// --- Day filter ---
if ($day_filter !== null && $day_filter >= 0 && $day_filter <= 6) {
    $conditions[] = 'v.volunteer_id IN (
                         SELECT a.volunteer_id FROM availability a WHERE a.day = ?
                     )';
    $params[] = $day_filter;
}

// --- Time range filter (requires day to also be set for meaningful results) ---
if ($start_filter !== '') {
    $conditions[] = 'v.volunteer_id IN (
                         SELECT a.volunteer_id FROM availability a WHERE a.start_time <= ?
                     )';
    $params[] = $start_filter;
}
if ($end_filter !== '') {
    $conditions[] = 'v.volunteer_id IN (
                         SELECT a.volunteer_id FROM availability a WHERE a.end_time >= ?
                     )';
    $params[] = $end_filter;
}

// --- Transport mode filter ---
if ($transport_filter !== null && $transport_filter > 0) {
    $conditions[] = 'v.volunteer_id IN (
                         SELECT vt.volunteer_id FROM volunteer_transport vt WHERE vt.transport_id = ?
                     )';
    $params[] = $transport_filter;
}

// --- Always exclude volunteers with no profile yet ---
$conditions[] = 'v.volunteer_forename IS NOT NULL';

// Build the complete SQL. The WHERE keyword is only added if
// there is at least one condition.
$where_clause = !empty($conditions)
    ? 'WHERE ' . implode(' AND ', $conditions)
    : '';

$sql = "SELECT v.volunteer_id,
               v.volunteer_forename,
               v.volunteer_surname,
               v.postcode,
               GROUP_CONCAT(DISTINCT s.skill_name ORDER BY s.skill_name SEPARATOR ', ')  AS skills,
               GROUP_CONCAT(DISTINCT t.transport_name ORDER BY t.transport_id SEPARATOR ', ') AS transports
        FROM volunteer v
        LEFT JOIN volunteer_skill vs  ON vs.volunteer_id  = v.volunteer_id
        LEFT JOIN skill s             ON s.skill_id       = vs.skill_id
        LEFT JOIN volunteer_transport vt ON vt.volunteer_id = v.volunteer_id
        LEFT JOIN transport t         ON t.transport_id   = vt.transport_id
        $where_clause
        GROUP BY v.volunteer_id, v.volunteer_forename, v.volunteer_surname, v.postcode
        ORDER BY v.volunteer_surname, v.volunteer_forename";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$results = $stmt->fetchAll();

write_audit_log(
    $pdo,
    null,
    'staff_search',
    'Staff searched volunteers. Filters: skills=' . implode(',', $skill_ids)
    . ' postcode=' . $postcode_filter
    . ' day=' . $day_raw
    . ' transport=' . $transport_raw
    . ' results=' . count($results)
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Search Results – Home-Start</title>
    <style>
        table { border-collapse: collapse; }
        th, td { padding: 6px 12px; border: 1px solid #ccc; }
    </style>
</head>
<body>
    <h1>Volunteer Search Results</h1>
    <p><a href="staff_dashboard.php">← Back to Dashboard</a></p>

    <p><strong><?= count($results) ?> volunteer(s) found.</strong></p>

    <?php if (!empty($results)): ?>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Postcode</th>
                <th>Skills</th>
                <th>Transport</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($results as $row): ?>
            <tr>
                <td><?= htmlspecialchars($row['volunteer_id']) ?></td>
                <td>
                    <?= htmlspecialchars($row['volunteer_forename']) ?>
                    <?= htmlspecialchars($row['volunteer_surname']) ?>
                </td>
                <td><?= htmlspecialchars($row['postcode']) ?></td>
                <td><?= htmlspecialchars($row['skills'] ?? 'None') ?></td>
                <td><?= htmlspecialchars($row['transports'] ?? 'None') ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
        <p>No volunteers match the selected filters.</p>
    <?php endif; ?>
</body>
</html>
