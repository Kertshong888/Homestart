<?php
// ============================================================
// audit.php
// Reusable helper for writing to the audit_log table.
// Include this file in any PHP script that needs to log events:
//   require_once 'audit.php';
//
// Then call:
//   write_audit_log($pdo, 'VOL001', 'profile_submit', 'Profile saved');
//   write_audit_log($pdo, null, 'staff_login', 'Staff admin logged in');
// ============================================================

/**
 * Writes one row to the audit_log table.
 *
 * @param PDO         $pdo       Active database connection
 * @param string|null $actor_id  volunteer_id of who caused the event, or null for staff/system
 * @param string      $event_type Short label, e.g. 'profile_submit', 'login_failed'
 * @param string      $detail    Human-readable description of what happened
 */
function write_audit_log(PDO $pdo, ?string $actor_id, string $event_type, string $detail): void
{
    // $_SERVER['REMOTE_ADDR'] is the client IP address.
    // It may be a proxy IP in production; for XAMPP/localhost it will be 127.0.0.1.
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    // Prepared statement: values are bound as parameters, never concatenated.
    // The INSERT is deliberately simple so it rarely fails.
    $stmt = $pdo->prepare(
        'INSERT INTO audit_log (actor_volunteer_id, event_type, event_detail, ip_address)
         VALUES (?, ?, ?, ?)'
    );

    // We wrap in try/catch so a logging failure never breaks the main request.
    // Audit logging is important but should not cause the user-facing page to crash.
    try {
        $stmt->execute([$actor_id, $event_type, $detail, $ip]);
    } catch (PDOException $e) {
        // Silently fail so audit issues don't block the user journey.
        // In production you would write this to a server error log.
        error_log('Audit log write failed: ' . $e->getMessage());
    }
}
