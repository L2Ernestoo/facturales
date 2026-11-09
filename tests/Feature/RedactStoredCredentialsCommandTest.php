<?php

namespace Lc\Fel\Tests\Feature;

use Lc\Fel\Models\FelDocument;

class RedactStoredCredentialsCommandTest extends FeatureTestCase
{
    public function test_command_removes_passwords_from_stored_envelopes(): void
    {
        $company = $this->createCompany();
        $legacy = FelDocument::create([
            'fel_company_id' => $company->id,
            'source_type' => 'test',
            'source_id' => '1',
            'document_type' => 'FACT',
            'soap_request' => '<pUsuario>USR</pUsuario><pPassword>SECRET-PASS</pPassword>',
        ]);

        $this->artisan('fel:redact-credentials', ['--dry-run' => true])
            ->expectsOutput('Documentos con password visible: 1')
            ->assertSuccessful();
        $this->assertStringContainsString('SECRET-PASS', $legacy->fresh()->soap_request);

        $this->artisan('fel:redact-credentials')
            ->expectsOutput('Documentos limpiados: 1')
            ->assertSuccessful();

        $this->assertSame('<pUsuario>USR</pUsuario><pPassword>********</pPassword>', $legacy->fresh()->soap_request);
        $this->artisan('fel:redact-credentials', ['--dry-run' => true])
            ->expectsOutput('Documentos con password visible: 0');
    }
}
