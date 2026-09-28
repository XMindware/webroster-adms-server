<?php

namespace App\Services;

use App\Models\Device;
use App\Models\FingerprintTemplate;
use Illuminate\Support\Facades\Log;

/**
 * Move an office's enrolment onto a different terminal.
 *
 * The routine case is a replacement unit: a terminal dies, a new one arrives
 * with a different serial number, and the people standing in front of it should
 * not have to re-enrol their fingers.
 *
 * ADMS has no single "migrate" instruction. Migration is the two operations the
 * server already knows how to do, applied in order:
 *
 *   1. push the office roster to the target   (DATA UPDATE USERINFO)
 *   2. push the stored templates to the target (DATA UPDATE FINGERTMP)
 *
 * Both are queued, not executed — the target picks them up on its own polling
 * cycle, so nothing here blocks waiting for a terminal.
 *
 * Two deliberate boundaries:
 *
 *  - The source terminal is left untouched. Clearing it is destructive, and in
 *    practice the old unit is often still standing there during a handover.
 *    Removing its users is a separate, explicit action.
 *
 *  - Templates come from the whole office, not only from the source. That is
 *    how PushFingerprintsService already works — it picks the newest capture
 *    per (pin, finger) across every terminal in the office — and for a
 *    replacement unit that is the behaviour you want: a third terminal's
 *    enrolments come along too. The source is named because it is what the
 *    operator is thinking about, and because it has to share the office for any
 *    of this to be meaningful.
 *
 * @author XMindware
 */
class DeviceMigrationService
{
    /**
     * Queue the migration onto $target.
     *
     * @return array{
     *     failed: bool,
     *     reason: string|null,
     *     employees: int,
     *     source_templates: int,
     *     candidates: int,
     *     templates: int,
     *     skipped: int
     * }
     */
    public function migrate(Device $source, Device $target): array
    {
        $blank = [
            'failed' => false,
            'reason' => null,
            'employees' => 0,
            'source_templates' => 0,
            'candidates' => 0,
            'templates' => 0,
            'skipped' => 0,
        ];

        if ($source->is($target)) {
            return array_merge($blank, ['failed' => true, 'reason' => 'same_device']);
        }

        // Both the roster query and the template query are scoped by office, so
        // a cross-office migration would silently queue the wrong people.
        if ((string) $source->idempresa !== (string) $target->idempresa
            || (string) $source->idoficina !== (string) $target->idoficina) {
            return array_merge($blank, ['failed' => true, 'reason' => 'different_office']);
        }

        $sourceTemplates = FingerprintTemplate::query()
            ->where('sn', $source->serial_number)
            ->valid()
            ->count();

        // Exceptions are allowed to propagate: the controller reports an honest
        // error rather than a "0 employees" that could equally mean an empty
        // roster. (Device::populate() swallows them, which is why this calls the
        // service rather than the model helper.)
        $employees = (new PopulateEmployeesService($target))->run();

        $push = app(PushFingerprintsService::class)->toDevice($target);

        $result = [
            'failed' => false,
            'reason' => null,
            'employees' => $employees,
            'source_templates' => $sourceTemplates,
            'candidates' => $push['candidates'],
            'templates' => $push['commands'],
            'skipped' => $push['skipped'],
        ];

        Log::info('DeviceMigrationService: migration queued', array_merge($result, [
            'source_id' => $source->id,
            'source_sn' => $source->serial_number,
            'target_id' => $target->id,
            'target_sn' => $target->serial_number,
        ]));

        return $result;
    }
}
