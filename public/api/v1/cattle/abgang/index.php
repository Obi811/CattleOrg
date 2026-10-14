<?php
/**
 * POST /api/v1/cattle/abgang
 * -----------------------------------------------------------------------------
 * Meldet einen Abgang (Verkauf/Umstallung) oder eine Verendung eines Tieres.
 * Logik identisch zu public/cattle_abgang.php (Web):
 *   - setzt abgang ODER verendung (je nach art) auf das Datum
 *   - setzt status = 'Inaktiv'
 *   - speichert den optionalen Grund in cattle.abgang_grund
 *   - beendet den laufenden Stallaufenthalt (stall_history.end_date = datum)
 *
 * Erfordert cattle.write; im Demo-Betrieb gesperrt (403 DEMO_READONLY).
 *
 * Body (JSON):
 *   { "cattle_id": 123, "art": "abgang|verendung", "datum": "YYYY-MM-DD",
 *     "grund": "..." }
 *
 * Hinweis: Endpunkt zwei Ebenen unter v1 -> zusaetzliches "../" zur bootstrap.
 */

require_once __DIR__ . '/../../bootstrap_api.php';

apiRequireMethod(['POST']);

$apiUser = apiAuthenticate($db);
$farm    = apiSetFarmContext($db, $apiUser);
$farmId  = $farm['farm_id'];

apiRequirePermission($farm, 'cattle.write');
apiRequireWrite($farm);

$input    = jsonInput();
$cattleId = (int) ($input['cattle_id'] ?? 0);
$art      = (string) ($input['art'] ?? 'abgang');
$datum    = trim((string) ($input['datum'] ?? ''));
$grund    = trim((string) ($input['grund'] ?? ''));

if (!in_array($art, ['abgang', 'verendung'], true)) {
    jsonError('VALIDATION', 'Feld "art" muss "abgang" oder "verendung" sein.', 422);
}
if ($datum === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum)) {
    jsonError('VALIDATION', 'Feld "datum" (YYYY-MM-DD) ist erforderlich.', 422);
}

// Tier streng betriebsbezogen laden
$stmt = $db->prepare('SELECT * FROM cattle WHERE id = :id AND farm_id = :f');
$stmt->execute([':id' => $cattleId, ':f' => $farmId]);
$cattle = $stmt->fetch();
if (!$cattle) {
    jsonError('NOT_FOUND', 'Tier nicht gefunden.', 404);
}

$grundVal = $grund !== '' ? $grund : null;

if ($art === 'verendung') {
    $upd = $db->prepare(
        'UPDATE cattle SET verendung = :d, abgang_grund = :g, status = :s
         WHERE id = :id AND farm_id = :f'
    );
} else {
    $upd = $db->prepare(
        'UPDATE cattle SET abgang = :d, abgang_grund = :g, status = :s
         WHERE id = :id AND farm_id = :f'
    );
}
$upd->execute([':d' => $datum, ':g' => $grundVal, ':s' => 'Inaktiv', ':id' => $cattleId, ':f' => $farmId]);

// Offene Stallhistorie schliessen (laufenden Stallaufenthalt beenden)
$closeStall = $db->prepare(
    'UPDATE stall_history SET end_date = :d
     WHERE cattle_id = :id AND farm_id = :f AND end_date IS NULL'
);
$closeStall->execute([':d' => $datum, ':id' => $cattleId, ':f' => $farmId]);

// Aktualisiertes Tier zurueckgeben
$sel = $db->prepare('SELECT * FROM cattle WHERE id = :id AND farm_id = :f');
$sel->execute([':id' => $cattleId, ':f' => $farmId]);

jsonSuccess($sel->fetch());
