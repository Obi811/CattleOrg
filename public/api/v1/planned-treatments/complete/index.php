<?php
/**
 * POST /api/v1/planned-treatments/complete?id=123
 * -----------------------------------------------------------------------------
 * Markiert eine geplante Behandlung als erledigt und erzeugt daraus eine echte
 * Behandlung (treatments). Body optional: { "treatment_date": "YYYY-MM-DD" }
 * (Standard: heute).
 *
 * Erfordert treatments.write; im Demo-Betrieb gesperrt (403 DEMO_READONLY).
 * Endpunkt zwei Ebenen unter v1 -> zusaetzliches "../" zur bootstrap.
 */

require_once __DIR__ . '/../../bootstrap_api.php';
require_once __DIR__ . '/../../../../../includes/planned_treatments.php';

apiRequireMethod(['POST']);

$apiUser = apiAuthenticate($db);
$farm    = apiSetFarmContext($db, $apiUser);
$farmId  = $farm['farm_id'];

apiRequirePermission($farm, 'treatments.write');
apiRequireWrite($farm);

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    jsonError('VALIDATION', 'Query-Parameter id ist erforderlich.', 422);
}

$input = jsonInput();
$date  = isset($input['treatment_date']) ? trim((string) $input['treatment_date']) : null;

$error = null;
$treatmentId = completePlannedTreatment($db, $farmId, $id, $date, (int) $apiUser['user_id'], $error);
if ($treatmentId === null) {
    jsonError('CONFLICT', $error ?? 'Erledigen fehlgeschlagen.', 409);
}

$sel = $db->prepare('SELECT * FROM planned_treatments WHERE id = :id AND farm_id = :f');
$sel->execute([':id' => $id, ':f' => $farmId]);
$tr = $db->prepare('SELECT * FROM treatments WHERE id = :id AND farm_id = :f');
$tr->execute([':id' => $treatmentId, ':f' => $farmId]);

jsonSuccess([
    'planned_treatment' => $sel->fetch(),
    'treatment'         => $tr->fetch(),
], null, 201);
