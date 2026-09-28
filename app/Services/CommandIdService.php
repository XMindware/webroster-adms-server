<?php

namespace App\Services;

use App\Models\Command;

/**
 * Allocates the CmdID carried by each queued command.
 *
 * A terminal acknowledges a command by echoing its id back, so ids are reused
 * cyclically rather than growing without bound.
 */
class CommandIdService
{
    /**
     * Highest id the protocol allows; ids wrap back to 1 past this.
     */
    private const MAX_COMMAND_ID = 10000;

    /**
     * Generate the next CmdID, cycling from 1..10000.
     *
     * @author XMindware
     * @link https://github.com/hallobayi/webroster-adms-server/blob/main/app/Services/CommandIdService.php
     */
    public function getNextCmdId(): int
    {
        // Continue from the id of the most recently queued command.
        $lastCommandId = (int) (Command::orderBy('id', 'desc')->value('command') ?? 0);

        $newCmdId = $lastCommandId + 1;

        if ($newCmdId > self::MAX_COMMAND_ID) {
            $newCmdId = 1;
        }

        // Never hand out an id that is still waiting to be acknowledged.
        while ($this->isPending($newCmdId)) {
            $newCmdId++;

            if ($newCmdId > self::MAX_COMMAND_ID) {
                $newCmdId = 1;
            }
        }

        return $newCmdId;
    }

    /**
     * Whether the given CmdID is still pending (not yet executed).
     *
     * @author mdestafadilah
     * @link https://github.com/hallobayi/webroster-adms-server/blob/main/app/Services/CommandIdService.php
     */
    protected function isPending(int $cmdId): bool
    {
        return Command::where('command', $cmdId)
            ->whereNull('executed_at')
            ->exists();
    }
}
