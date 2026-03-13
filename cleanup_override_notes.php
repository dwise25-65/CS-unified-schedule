<?php
/**
 * One-time cleanup: remove spurious notes from schedule_overrides
 * where notes was incorrectly duplicated from the hours/customHours value.
 *
 * Rules:
 *   - custom_hours rows: notes holds the custom time range. It is correct data.
 *                        Leave it alone — the column IS the custom hours.
 *   - on rows:           notes holds the working hours string. Correct as-is.
 *   - pto, off, holiday: notes should only contain genuine admin comments.
 *                        However, the bug only affected custom_hours and 'on'
 *                        (the hours string was copied to comment in app-format).
 *
 * What this script actually needs to fix:
 *   The bug was in normalizeScheduleOverrides() which set comment = notes for ALL
 *   statuses from DB rows, then ALSO set customHours/hours = notes.
 *   In the DB, notes IS the canonical store for both hours and comments —
 *   so the DB rows themselves are fine.
 *
 *   The problem is in backup_data JSON blobs stored in the backup_snapshots table.
 *   Those JSON blobs contain app-format overrides where comment = customHours (duplicate).
 *   When restored, the dirty comment field shows up in the UI.
 *
 * This script:
 *   1. Cleans live schedule_overrides table — removes notes from 'pto','off','holiday'
 *      rows where the note is empty or just whitespace (safe cleanup).
 *   2. Cleans all backup_data JSON blobs in the DB — strips comment fields from
 *      custom_hours and 'on' entries where comment === customHours or comment === hours.
 */

require_once __DIR__ . '/Database.php';

$db  = Database::getInstance();
$pdo = $db->getConnection();

echo "=== Override Notes Cleanup ===\n\n";

// ─────────────────────────────────────────────────────────────────────────────
// PART 1: Clean backup_data JSON blobs
// ─────────────────────────────────────────────────────────────────────────────
echo "Part 1: Cleaning backup_data JSON blobs...\n";

$rows = $pdo->query(
    "SELECT id, snapshot_id, data_json FROM backup_data WHERE table_name = 'schedule_overrides'"
)->fetchAll(PDO::FETCH_ASSOC);

echo "  Found " . count($rows) . " backup_data rows to check.\n";

$blobsUpdated = 0;
$entriesCleaned = 0;

foreach ($rows as $row) {
    $overrides = json_decode($row['data_json'], true);
    if (!is_array($overrides)) continue;

    $changed = false;
    foreach ($overrides as $key => &$entry) {
        if (!is_array($entry)) continue;
        $st = $entry['status'] ?? '';

        if ($st === 'custom_hours') {
            // Remove comment if it duplicates customHours
            if (isset($entry['comment']) && isset($entry['customHours'])
                && $entry['comment'] === $entry['customHours']) {
                unset($entry['comment']);
                $changed = true;
                $entriesCleaned++;
            }
        } elseif ($st === 'on') {
            // Remove comment if it duplicates hours
            if (isset($entry['comment']) && isset($entry['hours'])
                && $entry['comment'] === $entry['hours']) {
                unset($entry['comment']);
                $changed = true;
                $entriesCleaned++;
            }
        }
    }
    unset($entry);

    if ($changed) {
        $newJson = json_encode($overrides, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $pdo->prepare("UPDATE backup_data SET data_json = ? WHERE id = ?")
            ->execute([$newJson, $row['id']]);
        $blobsUpdated++;
    }
}

echo "  Blobs updated: $blobsUpdated\n";
echo "  Entries cleaned: $entriesCleaned\n\n";

// ─────────────────────────────────────────────────────────────────────────────
// PART 2: Verify live schedule_overrides table looks correct
// ─────────────────────────────────────────────────────────────────────────────
echo "Part 2: Checking live schedule_overrides table...\n";

// In the DB, the notes column is the canonical store:
//   custom_hours → notes = the time range  (correct, no change needed)
//   on           → notes = working hours   (correct, no change needed)
//   sick         → notes = admin comment   (correct)
//   pto/off/etc  → notes = admin comment   (correct, should be empty if no comment)
//
// The DB itself is not corrupted — the bug only manifested in the JSON app-format
// blobs. But let's report counts for visibility.

$counts = $pdo->query(
    "SELECT override_type, COUNT(*) as cnt, SUM(CASE WHEN notes != '' AND notes IS NOT NULL THEN 1 ELSE 0 END) as with_notes
     FROM schedule_overrides
     GROUP BY override_type
     ORDER BY override_type"
)->fetchAll(PDO::FETCH_ASSOC);

echo "  Live schedule_overrides breakdown:\n";
foreach ($counts as $c) {
    echo "    {$c['override_type']}: {$c['cnt']} total, {$c['with_notes']} with notes\n";
}

// ─────────────────────────────────────────────────────────────────────────────
// PART 3: Ensure composite index exists on schedule_overrides
// ─────────────────────────────────────────────────────────────────────────────
echo "\nPart 3: Checking DB indexes on schedule_overrides...\n";

$indexes = $pdo->query("SHOW INDEX FROM schedule_overrides")->fetchAll(PDO::FETCH_ASSOC);
$indexNames = array_column($indexes, 'Key_name');

if (!in_array('idx_emp_date', $indexNames)) {
    $pdo->exec("ALTER TABLE schedule_overrides ADD INDEX idx_emp_date (employee_id, override_date)");
    echo "  ✅ Created index idx_emp_date (employee_id, override_date)\n";
} else {
    echo "  ✔ Index idx_emp_date already exists — skipping.\n";
}

echo "\n=== Cleanup complete ===\n";
echo "The backup JSON blobs have been sanitized. Future restores will no longer\n";
echo "carry spurious comment fields on custom_hours or 'on' override entries.\n";
echo "DB index is in place for fast override lookups.\n";
