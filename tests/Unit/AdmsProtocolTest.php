<?php

namespace Tests\Unit;

use App\Services\Adms\AdmsProtocol;
use PHPUnit\Framework\TestCase;

/**
 * Locks the ADMS wire format.
 *
 * These expectations are the literal bytes the server used to assemble by hand
 * at each call site, before that logic was centralised in AdmsProtocol. They
 * are written out in full, escapes and all, rather than compared against the
 * builder's own output — a test that recomputes the value it is checking would
 * happily agree with a typo.
 *
 * A terminal rejects a malformed command silently, so a stray space or a space
 * where the protocol wants a tab is not a cosmetic problem: the command just
 * never takes effect. If one of these fails, the fleet stops obeying.
 */
class AdmsProtocolTest extends TestCase
{
    public function test_a_payload_is_framed_with_the_command_id(): void
    {
        $this->assertSame('C:7:CHECK', AdmsProtocol::frame(7, 'CHECK'));
    }

    public function test_check_payload(): void
    {
        $this->assertSame('CHECK', AdmsProtocol::check());
    }

    /**
     * The bulk pull asks for every user the terminal holds, which is spelled
     * with a trailing empty PIN.
     */
    public function test_bulk_userinfo_query_leaves_the_pin_empty(): void
    {
        $this->assertSame('DATA QUERY USERINFO PIN=', AdmsProtocol::queryUserinfo());
        $this->assertSame('DATA QUERY USERINFO PIN=1234', AdmsProtocol::queryUserinfo('1234'));
    }

    public function test_targeted_fingerprint_query(): void
    {
        $this->assertSame(
            "DATA QUERY FINGERTMP PIN=1234\tFingerID=3",
            AdmsProtocol::queryFingerTmp('1234', 3)
        );
    }

    /**
     * Field order and the empty Passwd/Card slots are part of the protocol, not
     * decoration — the terminal reads them positionally.
     */
    public function test_userinfo_upsert_keeps_every_field_slot(): void
    {
        $this->assertSame(
            "DATA UPDATE USERINFO PIN=1234\tName=Budi Santoso\tPasswd=\tCard=\tGrp=1"
                . "\tTZ=0000000100000000\tPri=0\tCategory=0",
            AdmsProtocol::updateUserinfo('1234', 'Budi Santoso')
        );
    }

    /**
     * A roster row with no name must still produce a well-formed command with
     * an empty Name slot, not a TypeError.
     */
    public function test_userinfo_upsert_tolerates_a_missing_name(): void
    {
        $this->assertSame(
            "DATA UPDATE USERINFO PIN=1234\tName=\tPasswd=\tCard=\tGrp=1"
                . "\tTZ=0000000100000000\tPri=0\tCategory=0",
            AdmsProtocol::updateUserinfo('1234', null)
        );
    }

    public function test_userinfo_delete(): void
    {
        $this->assertSame('DATA DELETE USERINFO PIN=1234', AdmsProtocol::deleteUserinfo('1234'));
        $this->assertSame('DATA DELETE USERINFO PIN=', AdmsProtocol::deleteUserinfo(null));
    }

    public function test_restart_uses_the_fleet_device_control_code(): void
    {
        $this->assertSame('CONTROL DEVICE 03000000', AdmsProtocol::restartDevice());
    }

    public function test_set_datetime_takes_the_terminals_packed_timestamp(): void
    {
        $this->assertSame('SET OPTIONS DateTime=780000000', AdmsProtocol::setDateTime(780000000));
    }

    /**
     * Size and Valid each need a default when the stored metadata is missing,
     * and the two defaults are deliberately not the same.
     *
     * A missing Size falls back to the payload's own length: a template cannot
     * be zero bytes, so there is no meaningful zero to preserve here.
     *
     * A null Valid falls back to 1, but an explicit 0 is passed through
     * untouched — the flag belongs to the terminal, which reports it when it
     * uploads a template and expects it echoed back when one is distributed.
     * Reading 0 as "missing" would have the server declare a template valid
     * that a device said was not.
     */
    public function test_fingerprint_push_falls_back_on_missing_metadata(): void
    {
        // 'QUJDREVGRw==' is 12 bytes; Size must follow the payload when the row
        // has no size recorded.
        $this->assertSame(
            "DATA UPDATE FINGERTMP PIN=1234\tFID=0\tSize=12\tValid=1\tTMP=QUJDREVGRw==",
            AdmsProtocol::updateFingerTmp('1234', 0, null, null, 'QUJDREVGRw==')
        );

        $this->assertSame(
            "DATA UPDATE FINGERTMP PIN=1234\tFID=0\tSize=40\tValid=1\tTMP=QUJDREVGRw==",
            AdmsProtocol::updateFingerTmp('1234', 0, 40, 1, 'QUJDREVGRw==')
        );

        $this->assertSame(
            "DATA UPDATE FINGERTMP PIN=1234\tFID=0\tSize=40\tValid=0\tTMP=QUJDREVGRw==",
            AdmsProtocol::updateFingerTmp('1234', 0, 40, 0, 'QUJDREVGRw=='),
            'an explicit 0 is the terminal\'s own verdict and must survive'
        );
    }

    public function test_fingerprint_push_frames_like_every_other_command(): void
    {
        $this->assertSame(
            "C:99:DATA UPDATE FINGERTMP PIN=1\tFID=2\tSize=4\tValid=1\tTMP=QQ==",
            AdmsProtocol::frame(99, AdmsProtocol::updateFingerTmp(1, 2, 4, 1, 'QQ=='))
        );
    }

    /**
     * Removing one finger names the finger, not the person — the user record
     * survives, which is the whole difference from DATA DELETE USERINFO.
     *
     * The string is ZKTeco's SDK constant DEV_CMD_DATA_DELETE_FINGER
     * ("DATA DELETE FINGERTMP PIN={0}\tFID={1}") written out literally, so a
     * typo here cannot agree with itself.
     */
    public function test_fingerprint_delete_wire_format(): void
    {
        $this->assertSame(
            "DATA DELETE FINGERTMP PIN=1234\tFID=3",
            AdmsProtocol::deleteFingerTmp('1234', 3)
        );

        $this->assertSame(
            "C:7:DATA DELETE FINGERTMP PIN=1234\tFID=0",
            AdmsProtocol::frame(7, AdmsProtocol::deleteFingerTmp('1234', 0))
        );
    }

    /**
     * The packed DateTime the terminal expects is not a unix timestamp and not
     * a formatted string: it counts (day - 1) seconds within a month, months
     * within a year, years from 2000.
     *
     * These expectations are the arithmetic that used to sit inline in
     * iclockController, evaluated independently. A clock that is off by a month
     * or a day is worse than one that is off by a minute — punches land on the
     * wrong date and every discrepancy figure the monitor shows is wrong with
     * them.
     */
    public function test_datetime_encoding_matches_the_terminals_format(): void
    {
        $this->assertSame(0, AdmsProtocol::encodeDateTime(new \DateTime('2000-01-01 00:00:00')));
        $this->assertSame(859203456, AdmsProtocol::encodeDateTime(new \DateTime('2026-09-25 11:37:36')));
        $this->assertSame(776563199, AdmsProtocol::encodeDateTime(new \DateTime('2024-02-29 23:59:59')));
    }

    /**
     * The caller passes the moment already expressed in the office timezone, so
     * the same instant in two zones must pack differently — otherwise the
     * server would silently order a terminal to the wrong hour.
     */
    public function test_datetime_encoding_reflects_the_moment_it_is_given(): void
    {
        $utc = new \DateTime('2026-09-25 04:00:00', new \DateTimeZone('UTC'));
        $jakarta = (clone $utc)->setTimezone(new \DateTimeZone('Asia/Jakarta'));

        $this->assertSame(859176000, AdmsProtocol::encodeDateTime($utc));
        $this->assertSame(
            AdmsProtocol::encodeDateTime($jakarta),
            859176000 + 7 * 3600,
            'Jakarta is UTC+7, so the packed value must be seven hours further on'
        );
    }

    /**
     * Every type constant must stay distinct: the pull/push pipelines dedupe on
     * type, so two operations sharing one value would make them cancel each
     * other out.
     */
    public function test_command_types_are_distinct(): void
    {
        $types = [
            AdmsProtocol::TYPE_USERINFO_UPSERT,
            AdmsProtocol::TYPE_USERINFO_DELETE,
            AdmsProtocol::TYPE_DEVICE_RESTART,
            AdmsProtocol::TYPE_SET_DATETIME,
            AdmsProtocol::TYPE_PULL_CHECK,
            AdmsProtocol::TYPE_PULL_FINGERTMP,
            AdmsProtocol::TYPE_PULL_USERINFO,
            AdmsProtocol::TYPE_PUSH_FINGERTMP,
        ];

        $this->assertSame($types, array_values(array_unique($types)));
    }
}
