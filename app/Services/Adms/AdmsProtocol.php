<?php

namespace App\Services\Adms;

/**
 * The ADMS wire vocabulary: how a command looks on the way to a terminal.
 *
 * Every command a terminal receives is framed the same way —
 *
 *     C:{cmdId}:{payload}
 *
 * — and the payload is one of a small, fixed set of instructions ("CHECK",
 * "DATA UPDATE USERINFO", "CONTROL DEVICE", …). Both halves used to be spelled
 * out by hand at each call site, in four different styles (string
 * interpolation, sprintf, concatenation), which is how "DATA DELETE USERINFO"
 * ended up written two slightly different ways and how the `C:{cmdId}:` prefix
 * came to be duplicated nine times.
 *
 * This class is deliberately static and dependency-free: it is the one place
 * that knows the protocol, it is trivial to unit test, and it cannot reach the
 * database by accident.
 *
 * Note on `CONTROL DEVICE`: the trailing hex word is a firmware-defined
 * device-control code, not something this server computes. 03000000 is the code
 * this fleet's terminals treat as a reboot, which is what the UI has always
 * labelled "restart".
 *
 * @see AdmsCommandService for queueing the result onto a device.
 */
final class AdmsProtocol
{
    /*
    |--------------------------------------------------------------------------
    | Command types
    |--------------------------------------------------------------------------
    |
    | Stored in device_commands.type so a queued command can be told apart from
    | its neighbours, and so a terminal's acknowledgement can be attributed to
    | the operation that produced it.
    |
    */

    /** Push a full user record to the terminal (create or update, in place). */
    public const TYPE_USERINFO_UPSERT = 'userinfo_upsert';

    /** Remove a user — and their biometric templates — from the terminal. */
    public const TYPE_USERINFO_DELETE = 'userinfo_delete';

    /** Remove one finger of one employee, leaving the user record alone. */
    public const TYPE_FINGERTMP_DELETE = 'fingertmp_delete';

    /** Reboot the terminal. */
    public const TYPE_DEVICE_RESTART = 'device_restart';

    /** Correct the terminal's clock. */
    public const TYPE_SET_DATETIME = 'set_datetime';

    /** Bulk pull: ask the terminal to re-upload what it holds. */
    public const TYPE_PULL_CHECK = 'pull_check';

    /** Targeted pull: one finger of one employee. */
    public const TYPE_PULL_FINGERTMP = 'pull_fingertmp';

    /** Ask for a user record. */
    public const TYPE_PULL_USERINFO = 'pull_userinfo';

    /** Distribute a stored template to a terminal. */
    public const TYPE_PUSH_FINGERTMP = 'push_fingertmp';

    /**
     * Frame a payload as a complete command line.
     *
     * This is the only place the "C:{cmdId}:" prefix is written.
     */
    public static function frame(int $cmdId, string $payload): string
    {
        return "C:{$cmdId}:{$payload}";
    }

    /*
    |--------------------------------------------------------------------------
    | Employee records
    |--------------------------------------------------------------------------
    */

    /**
     * Full user record. The terminal upserts on PIN, so this both creates and
     * updates — there is no separate "create user" instruction in ADMS.
     *
     * Field layout is fixed by the protocol; the empty ones (Passwd, Card) and
     * the defaults (Grp, TZ, Pri, Category) are what the terminals here expect.
     *
     * $pin and $name come from columns that are not NOT NULL, and an empty
     * field is a valid wire value here, so both are cast rather than required.
     */
    public static function updateUserinfo(string|int|null $pin, string|int|null $name): string
    {
        return "DATA UPDATE USERINFO PIN={$pin}\tName={$name}\tPasswd=\tCard="
            . "\tGrp=1\tTZ=0000000100000000\tPri=0\tCategory=0";
    }

    /**
     * Remove a user by PIN. The terminal drops their templates with them.
     */
    public static function deleteUserinfo(string|int|null $pin): string
    {
        return "DATA DELETE USERINFO PIN={$pin}";
    }

    /**
     * Ask for a user record. An empty PIN means "every user the terminal
     * holds", which is the form used by a bulk pull.
     */
    public static function queryUserinfo(string|int|null $pin = ''): string
    {
        return "DATA QUERY USERINFO PIN={$pin}";
    }

    /*
    |--------------------------------------------------------------------------
    | Biometrics
    |--------------------------------------------------------------------------
    */

    /** Ask the terminal to re-upload everything it holds. Best-effort. */
    public static function check(): string
    {
        return 'CHECK';
    }

    /** Ask for one finger of one employee. Precise, one command per finger. */
    public static function queryFingerTmp(string|int|null $pin, int $fid): string
    {
        return "DATA QUERY FINGERTMP PIN={$pin}\tFingerID={$fid}";
    }

    /**
     * Send a template down to a terminal.
     *
     * $size and $valid are nullable because a row can be missing its metadata,
     * so each needs a default — but the two defaults are deliberately not the
     * same, because the two zeroes are not the same kind of zero:
     *
     *  - Size=0 is meaningless: a template cannot be zero bytes long. A missing
     *    or zero size therefore falls back to the payload's own length, which
     *    is at least true.
     *
     *  - Valid=0 is meaningful. It is the terminal's flag, not ours: devices
     *    report it when they upload a template ("FP PIN=.. Valid=0"), and
     *    ZKTeco's own SDK echoes it back untouched when distributing —
     *    DevCmdUtil.getUpdateFpContent passes template.getValid() straight into
     *    the command. Rewriting 0 to 1 would make the server declare a template
     *    valid that a terminal said was not, and the only thing that can ever
     *    achieve is handing out a finger the device had already rejected. So
     *    this one is ??, not ?: — a null is filled in, a zero is passed on.
     *
     * Callers are expected to filter invalid rows themselves;
     * PushFingerprintsService does, via FingerprintTemplate::scopeValid().
     */
    public static function updateFingerTmp(
        string|int|null $pin,
        int $fid,
        ?int $size,
        ?int $valid,
        string $template
    ): string {
        return sprintf(
            "DATA UPDATE FINGERTMP PIN=%s\tFID=%d\tSize=%d\tValid=%d\tTMP=%s",
            $pin,
            $fid,
            $size ?: strlen($template),
            $valid ?? 1,
            $template
        );
    }

    /**
     * Remove one finger of one employee from a terminal.
     *
     * This is the precise counterpart of DATA DELETE USERINFO, which drops the
     * person and every template they have. Here the user record survives — name,
     * card, password, group all stay — and only the finger goes. Use it when a
     * finger was enrolled by mistake, or when one hand is re-enrolled and the
     * stale slot would otherwise keep matching.
     *
     * FID is the finger index, 0..9. There is no "all fingers" spelling in the
     * protocol: ZKTeco's SDK only defines the PIN+FID form, so removing every
     * finger means one command per index.
     *
     * Note the asymmetry with DATA DELETE USERINFO: this instruction is a
     * command of its own, whereas on the device side a template is also
     * considered gone when it is uploaded with Valid=0. We deliberately use the
     * documented delete rather than "push the same template back with Valid=0",
     * because only one of those two is specified behaviour.
     */
    public static function deleteFingerTmp(string|int|null $pin, int $fid): string
    {
        // Double quotes: the field separator has to be a real tab. In single
        // quotes \t is a backslash and a letter t, which the terminal reads as
        // one malformed field name — the test below is what caught that.
        return sprintf("DATA DELETE FINGERTMP PIN=%s\tFID=%d", $pin, $fid);
    }

    /*
    |--------------------------------------------------------------------------
    | Device control
    |--------------------------------------------------------------------------
    */

    /** Reboot the terminal. */
    public static function restartDevice(): string
    {
        return 'CONTROL DEVICE 03000000';
    }

    /**
     * Set the terminal's clock.
     *
     * $encoded is the terminal's own packed date/time format, not a string —
     * see encodeDateTime().
     */
    public static function setDateTime(int $encoded): string
    {
        return "SET OPTIONS DateTime={$encoded}";
    }

    /**
     * Pack a moment into the terminal's own DateTime format.
     *
     * Not a unix timestamp and not a formatted string: the terminal counts
     * (day-of-month - 1) seconds within a month, months within a year, years
     * from 2000. The caller passes the moment already expressed in the office's
     * timezone — this function does no timezone conversion of its own, on
     * purpose, because only the caller knows which zone applies.
     *
     * Lives here rather than in the controller because it is protocol, not
     * request handling: two call sites in iclockController had their own copy
     * of the arithmetic, and both the clock correction and the on-demand
     * "set the clock now" action need it to agree exactly.
     */
    public static function encodeDateTime(\DateTimeInterface $time): int
    {
        $year = (int) $time->format('Y');
        $month = (int) $time->format('n');
        $day = (int) $time->format('j');
        $hour = (int) $time->format('G');
        $minute = (int) $time->format('i');
        $second = (int) $time->format('s');

        return (($year - 2000) * 12 * 31 + (($month - 1) * 31) + $day - 1) * (24 * 60 * 60)
            + ($hour * 60 + $minute) * 60
            + $second;
    }
}
