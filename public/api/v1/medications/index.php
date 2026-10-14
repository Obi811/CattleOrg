<?php
/**
 * GET /api/v1/medications
 * -----------------------------------------------------------------------------
 * Liefert den GLOBALEN Medikamentenkatalog. Erfordert nur ein gueltiges Token
 * (kein Betriebskontext noetig, da global). Lesend fuer alle authentifizierten.
 */

require_once __DIR__ . '/../bootstrap_api.php';

apiRequireMethod(['GET']);

apiAuthenticate($db); // Token erforderlich

$meds = $db->query('SELECT id, name, active_substance, dosage, wz_milk, wz_fleisch FROM medications ORDER BY name')->fetchAll();

jsonSuccess($meds, ['total' => count($meds)]);
