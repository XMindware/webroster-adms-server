<?php

namespace Tests\Feature;

use App\Models\Agente;
use App\Models\Device;
use App\Models\EmpresaApiKey;
use App\Models\FacePhoto;
use App\Models\FaceTemplate;
use App\Models\FingerprintTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgenteBiometricsApiTest extends TestCase
{
    use RefreshDatabase;

    private function headers(string $key): array
    {
        return ['Authorization' => "Bearer $key"];
    }

    private function agente(int $id, int $empresa = 10, int $oficina = 1): Agente
    {
        return Agente::create([
            'idempresa' => $empresa, 'idoficina' => $oficina, 'idagente' => $id,
            'shortname' => "A$id", 'fullname' => "Agent $id",
        ]);
    }

    private function finger(string $sn, string $pin, int $fid, string $empresa = '10', int $valid = 1): void
    {
        FingerprintTemplate::create([
            'sn' => $sn, 'pin' => $pin, 'fid' => $fid, 'valid' => $valid, 'template' => 'x',
            'template_hash' => sha1("$sn$pin$fid"), 'idempresa' => $empresa, 'idoficina' => '1',
            'captured_at' => '2026-11-01 10:00:00',
        ]);
    }

    private function faceTemplate(string $sn, string $pin, string $empresa = '10'): void
    {
        FaceTemplate::create([
            'sn' => $sn, 'pin' => $pin, 'fid' => 0, 'bio_type' => 9, 'valid' => 1, 'template' => 'x',
            'template_hash' => sha1("$sn$pin"), 'idempresa' => $empresa, 'idoficina' => '1',
            'captured_at' => '2026-11-02 10:00:00',
        ]);
    }

    private function facePhoto(string $sn, string $pin, string $empresa = '10'): void
    {
        FacePhoto::create([
            'sn' => $sn, 'pin' => $pin, 'bio_type' => 9, 'content' => 'x',
            'content_hash' => sha1("$sn$pin"), 'idempresa' => $empresa, 'idoficina' => '1',
            'captured_at' => '2026-11-03 10:00:00',
        ]);
    }

    /**
     * 1: two fingers on SN-A, one repeated on SN-B, face template on SN-A
     * 2: face photo only
     * 3: invalid fingerprint only (counts as none)
     * 4: nothing locally, but empresa 99 has a fingerprint under the same PIN
     */
    private function seedBiometrics(): void
    {
        $this->agente(1);
        $this->agente(2);
        $this->agente(3, 10, 2);
        $this->agente(4);
        $this->agente(1, 99);

        $this->finger('SN-A', '1', 0);
        $this->finger('SN-A', '1', 6);
        $this->finger('SN-B', '1', 6);
        $this->faceTemplate('SN-A', '1');
        $this->facePhoto('SN-B', '2');
        $this->finger('SN-A', '3', 0, '10', 0);
        $this->finger('SN-X', '4', 0, '99');
    }

    public function test_requires_a_valid_key(): void
    {
        $this->getJson('/api/empresa/agentes')->assertStatus(401);
        $this->getJson('/api/empresa/agentes/1', $this->headers('wrk_nope'))->assertStatus(401);
    }

    public function test_lists_agents_with_biometric_flags(): void
    {
        [, $plain] = EmpresaApiKey::issue('10');
        $this->seedBiometrics();

        $response = $this->getJson('/api/empresa/agentes', $this->headers($plain))->assertOk()
            ->assertJsonPath('idempresa', '10')
            ->assertJsonPath('summary', ['total' => 4, 'with_fingerprint' => 1, 'with_face' => 2, 'without_biometrics' => 2])
            ->assertJsonPath('meta.total', 4);

        $rows = collect($response->json('data'))->keyBy('idagente');
        $this->assertCount(4, $rows);

        $this->assertTrue($rows[1]['has_fingerprint']);
        $this->assertTrue($rows[1]['has_face']);
        $this->assertSame(2, $rows[1]['fingerprint']['fingers']);
        $this->assertSame(2, $rows[1]['fingerprint']['terminals']);
        $this->assertSame(1, $rows[1]['face']['template_terminals']);
        $this->assertSame(0, $rows[1]['face']['photo_terminals']);
        $this->assertNotNull($rows[1]['fingerprint']['last_captured_at']);

        $this->assertFalse($rows[2]['has_fingerprint']);
        $this->assertTrue($rows[2]['has_face']);
        $this->assertSame(1, $rows[2]['face']['photo_terminals']);

        $this->assertFalse($rows[3]['has_fingerprint']);
        $this->assertFalse($rows[3]['has_face']);

        // Another empresa's fingerprint under the same PIN does not leak in.
        $this->assertFalse($rows[4]['has_fingerprint']);
    }

    public function test_filters(): void
    {
        [, $plain] = EmpresaApiKey::issue('10');
        $this->seedBiometrics();

        $ids = fn (string $qs) => collect($this->getJson("/api/empresa/agentes?$qs", $this->headers($plain))
            ->assertOk()->json('data'))->pluck('idagente')->sort()->values()->all();

        $this->assertSame([1], $ids('has_fingerprint=1'));
        $this->assertSame([2, 3, 4], $ids('has_fingerprint=0'));
        $this->assertSame([1, 2], $ids('has_face=1'));
        $this->assertSame([3, 4], $ids('has_face=0'));
        $this->assertSame([2], $ids('has_fingerprint=0&has_face=1'));
        $this->assertSame([3], $ids('idoficina=2'));

        $this->getJson('/api/empresa/agentes?idoficina=2', $this->headers($plain))
            ->assertJsonPath('summary.total', 1);
    }

    public function test_pagination(): void
    {
        [, $plain] = EmpresaApiKey::issue('10');
        $this->seedBiometrics();

        $this->getJson('/api/empresa/agentes?per_page=3&page=2', $this->headers($plain))->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('links.next', null);
    }

    public function test_never_returns_template_payloads(): void
    {
        [, $plain] = EmpresaApiKey::issue('10');
        $this->seedBiometrics();

        $body = $this->getJson('/api/empresa/agentes/1', $this->headers($plain))->getContent()
            . $this->getJson('/api/empresa/agentes', $this->headers($plain))->getContent();

        $this->assertStringNotContainsString('"template"', $body);
        $this->assertStringNotContainsString('"content"', $body);
    }

    public function test_show_lists_terminals_and_hides_other_empresas(): void
    {
        [, $plain] = EmpresaApiKey::issue('10');
        $this->seedBiometrics();
        Device::create(['serial_number' => 'SN-A', 'idempresa' => '10', 'idoficina' => 1, 'idreloj' => '1'])
            ->forceFill(['name' => 'Lobby'])->save();

        $response = $this->getJson('/api/empresa/agentes/1', $this->headers($plain))->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.idagente', 1)
            ->assertJsonPath('data.0.has_face', true);

        $terminals = collect($response->json('data.0.terminals'))->keyBy('serial_number');
        $this->assertSame(['SN-A', 'SN-B'], $terminals->keys()->all());
        $this->assertSame('Lobby', $terminals['SN-A']['name']);
        $this->assertSame([0, 6], $terminals['SN-A']['fingers']);
        $this->assertSame(1, $terminals['SN-A']['face_templates']);
        $this->assertSame([6], $terminals['SN-B']['fingers']);
        $this->assertNull($terminals['SN-B']['name']);

        // Agent exists only under empresa 99.
        $this->agente(50, 99);
        $this->getJson('/api/empresa/agentes/50', $this->headers($plain))->assertNotFound();
        $this->getJson('/api/empresa/agentes/12345', $this->headers($plain))->assertNotFound();
    }

    public function test_soft_deleted_agents_are_excluded(): void
    {
        [, $plain] = EmpresaApiKey::issue('10');
        $this->agente(1);
        $this->agente(2)->delete();

        $this->getJson('/api/empresa/agentes', $this->headers($plain))->assertOk()
            ->assertJsonPath('summary.total', 1)
            ->assertJsonCount(1, 'data');
        $this->getJson('/api/empresa/agentes/2', $this->headers($plain))->assertNotFound();
    }
}
