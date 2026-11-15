<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Agente;
use App\Models\Attendance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * S6: employee names come from WebRoster and devices, so they must be
 * escaped wherever they are rendered.
 */
class StoredXssTest extends TestCase
{
    use RefreshDatabase;

    private const PAYLOAD = '<script>alert("xss")</script>';

    public function test_employee_name_is_escaped_on_the_attendance_page(): void
    {
        Agente::create([
            'idempresa' => 1, 'idoficina' => 2, 'idagente' => 4242,
            'shortname' => 'evil', 'fullname' => self::PAYLOAD,
        ]);
        $this->attendance([
            'employee_id' => 4242, 'timestamp' => now(), 'sn' => 'SN-XSS',
            'idempresa' => 1, 'idoficina' => 2,
        ]);

        $response = $this->actingAs(User::factory()->create())->get('/attendance');

        $response->assertOk();
        $response->assertDontSee(self::PAYLOAD, false);
        $response->assertSee(e(self::PAYLOAD), false);
    }

    public function test_unknown_employee_still_shows_placeholder(): void
    {
        $this->attendance([
            'employee_id' => 999, 'timestamp' => now(), 'sn' => 'SN-NONE',
            'idempresa' => 1, 'idoficina' => 2,
        ]);

        $this->actingAs(User::factory()->create())->get('/attendance')
            ->assertOk()
            ->assertSee('<em>Unknown</em>', false);
    }

    private function attendance(array $attributes): Attendance
    {
        $attendance = new Attendance;
        $attendance->forceFill($attributes + ['table' => 'ATTLOG', 'stamp' => '1'])->save();

        return $attendance;
    }

    public function test_views_have_no_raw_echo_outside_the_reviewed_allowlist(): void
    {
        // Raw {!! !!} output is banned for user or device data (CLAUDE.md).
        // Add an entry here only after review, with the reason.
        $allowed = [];

        $offenders = [];
        foreach (File::allFiles(resource_path('views')) as $file) {
            foreach (explode("\n", $file->getContents()) as $n => $line) {
                if (str_contains($line, '{!!') && ! in_array($file->getRelativePathname(), $allowed, true)) {
                    $offenders[] = $file->getRelativePathname().':'.($n + 1);
                }
            }
        }

        $this->assertSame([], $offenders, 'Unescaped Blade output found: '.implode(', ', $offenders));
    }
}
