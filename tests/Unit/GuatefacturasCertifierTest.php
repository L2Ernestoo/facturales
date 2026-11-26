<?php

namespace Lc\Fel\Tests\Unit;

use Exception;
use Lc\Fel\Contracts\FelConfigRepositoryInterface;
use Lc\Fel\Services\Certifiers\GuatefacturasCertifier;
use Lc\Fel\Services\DocumentBuilderFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class GuatefacturasCertifierTest extends TestCase
{
    public function test_generate_response_parses_certifier_receiver_name(): void
    {
        $soapResponse = '<env:Envelope xmlns:env="http://schemas.xmlsoap.org/soap/envelope/"><env:Body><m:generaDocumentoResponse xmlns:m="http://dbguatefac/Guatefac.wsdl"><result>&lt;Resultado&gt;&lt;Serie&gt;BADD0C84&lt;/Serie&gt;&lt;Preimpreso&gt;2692563664&lt;/Preimpreso&gt;&lt;Nombre&gt;DOCUMENTO DE PRUEBAS MACAW GROUP, SOCIEDAD ANONIMA&lt;/Nombre&gt;&lt;Direccion&gt;Ciudad&lt;/Direccion&gt;&lt;Telefono&gt;&lt;/Telefono&gt;&lt;NumeroAutorizacion&gt;BADD0C84-A07D-42D0-96F1-5A7640EF9C50&lt;/NumeroAutorizacion&gt;&lt;Referencia&gt;ORD-5&lt;/Referencia&gt;&lt;/Resultado&gt;</result></m:generaDocumentoResponse></env:Body></env:Envelope>';

        $parsed = $this->invokeCertifier('parseGenerateSoapResponse', $soapResponse);

        $this->assertSame('BADD0C84', $parsed['serie']);
        $this->assertSame('2692563664', $parsed['preimpreso']);
        $this->assertSame('BADD0C84-A07D-42D0-96F1-5A7640EF9C50', $parsed['uuid']);
        $this->assertSame('DOCUMENTO DE PRUEBAS MACAW GROUP, SOCIEDAD ANONIMA', $parsed['nombre']);
        $this->assertSame('Ciudad', $parsed['direccion']);
        $this->assertSame('ORD-5', $parsed['referencia']);
    }

    #[DataProvider('incompleteIssuanceResults')]
    public function test_incomplete_issuance_confirmation_is_uncertain(string $result): void
    {
        $parsed = $this->invokeCertifier('parseGenerateSoapResponse', $this->soap($result));

        $this->assertTrue($parsed['uncertain']);
        $this->assertArrayNotHasKey('uuid', $parsed);
    }

    public static function incompleteIssuanceResults(): array
    {
        return [
            'empty' => [''],
            'blank authorization' => ['<Resultado><Serie>A1</Serie><Preimpreso>100</Preimpreso><NumeroAutorizacion> </NumeroAutorizacion></Resultado>'],
            'invalid authorization' => ['<Resultado><Serie>A1</Serie><Preimpreso>100</Preimpreso><NumeroAutorizacion>NOT-UUID</NumeroAutorizacion></Resultado>'],
            'missing series and number' => ['<Resultado><NumeroAutorizacion>BADD0C84-A07D-42D0-96F1-5A7640EF9C50</NumeroAutorizacion></Resultado>'],
        ];
    }

    public function test_generate_response_recognizes_the_documented_permission_rejection(): void
    {
        $reason = "Usuario no tiene derechos al\nEMISOR";
        $soap = $this->soap("<Resultado>{$reason}</Resultado>", 'generaDocumento', 'nested');

        $parsed = $this->invokeCertifier('parseGenerateSoapResponse', $soap);

        $this->assertSame(['error' => $reason], $parsed);
    }

    #[DataProvider('structuredAnnulmentFormats')]
    public function test_annulment_accepts_the_documented_structured_confirmation(string $format): void
    {
        $result = '<RESULTADO><ESTADO>ANULADO</ESTADO><EMISOR>101</EMISOR><COMPRADOR>202</COMPRADOR><SERIE>A1</SERIE><PREIMPRESO>100</PREIMPRESO></RESULTADO>';
        $parsed = $this->invokeCertifier('parseAnnulSoapResponse', $this->soap($result, 'anulaDocumento', $format));

        $this->assertSame(['success' => true, 'message' => 'ANULADO', 'serie' => 'A1', 'preimpreso' => '100'], $parsed);
    }

    public static function structuredAnnulmentFormats(): array
    {
        return [['escaped'], ['nested'], ['cdata']];
    }

    public function test_annulment_accepts_an_explicit_confirmation(): void
    {
        $result = '<Respuesta><RESULTADO>Documento anulado correctamente.</RESULTADO><SERIE>A1</SERIE><PREIMPRESO>100</PREIMPRESO></Respuesta>';
        $parsed = $this->invokeCertifier('parseAnnulSoapResponse', $this->soap($result, 'anulaDocumento'));

        $this->assertTrue($parsed['success']);
        $this->assertSame('A1', $parsed['serie']);
        $this->assertSame('100', $parsed['preimpreso']);
    }

    #[DataProvider('ambiguousAnnulmentResults')]
    public function test_annulment_requires_explicit_confirmation(string $result): void
    {
        $parsed = $this->invokeCertifier('parseAnnulSoapResponse', $this->soap($result, 'anulaDocumento'));

        $this->assertFalse($parsed['success']);
        $this->assertTrue($parsed['uncertain']);
    }

    public static function ambiguousAnnulmentResults(): array
    {
        return [
            'empty' => [''],
            'unknown XML' => ['<Respuesta><SERIE>A1</SERIE></Respuesta>'],
            'still processing' => ['Procesando'],
            'negative text with the success phrase' => ['Documento no ha sido anulado correctamente.'],
            'success status without preprinted number' => ['<RESULTADO><ESTADO>ANULADO</ESTADO><SERIE>A1</SERIE></RESULTADO>'],
            'unknown status with positive text' => ['<RESULTADO>OK<ESTADO>PENDIENTE</ESTADO><SERIE>A1</SERIE><PREIMPRESO>100</PREIMPRESO></RESULTADO>'],
            'success status with error' => ['<RESULTADO><ESTADO>ANULADO</ESTADO><ERROR>Solicitud rechazada</ERROR><SERIE>A1</SERIE><PREIMPRESO>100</PREIMPRESO></RESULTADO>'],
            'contradictory statuses' => ['<RESULTADO><ESTADO>ANULADO</ESTADO><ESTADO>PENDIENTE</ESTADO><SERIE>A1</SERIE><PREIMPRESO>100</PREIMPRESO></RESULTADO>'],
            'repeated series with different values' => ['<RESULTADO><ESTADO>ANULADO</ESTADO><SERIE>A1</SERIE><SERIE>B2</SERIE><PREIMPRESO>100</PREIMPRESO></RESULTADO>'],
        ];
    }

    public function test_explicit_rejection_is_distinct_from_an_uncertain_result(): void
    {
        $reason = 'ERROR: documento rechazado.';
        $parsed = $this->invokeCertifier('parseAnnulSoapResponse', $this->soap($reason, 'anulaDocumento'));

        $this->assertFalse($parsed['success']);
        $this->assertSame($reason, $parsed['error']);
        $this->assertArrayNotHasKey('uncertain', $parsed);
    }

    public function test_uses_only_the_requested_environment_url(): void
    {
        $urls = ['url_test' => 'https://test.example.test/soap', 'url_prod' => 'https://prod.example.test/soap'];

        $this->assertSame($urls['url_test'], $this->invokeCertifier('resolveServiceUrl', ['mode' => ' TEST ', ...$urls]));
        $this->assertSame($urls['url_prod'], $this->invokeCertifier('resolveServiceUrl', ['mode' => 'prod', ...$urls]));
    }

    #[DataProvider('invalidEndpointSettings')]
    public function test_invalid_or_missing_environment_url_is_rejected(array $config, string $message): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage($message);
        $this->invokeCertifier('resolveServiceUrl', $config);
    }

    public static function invalidEndpointSettings(): array
    {
        return [
            'test without test URL' => [['mode' => 'test', 'url_prod' => 'https://prod.example.test/soap'], 'url_test'],
            'prod without prod URL' => [['mode' => 'prod', 'url_test' => 'https://test.example.test/soap'], 'url_prod'],
            'unrecognized mode' => [['mode' => 'stage', 'url_prod' => 'https://prod.example.test/soap'], 'Modo de Guatefacturas inválido'],
            'invalid URL' => [['mode' => 'test', 'url_test' => 'file:///soap'], 'HTTP o HTTPS'],
        ];
    }

    public function test_redact_credentials_hides_only_the_password(): void
    {
        $soap = "<pUsuario>USR</pUsuario>\n<pPassword>s3cr&amp;t</pPassword>\n<pXml><![CDATA[<a/>]]></pXml>";

        $redacted = GuatefacturasCertifier::redactCredentials($soap);

        $this->assertStringNotContainsString('s3cr', $redacted);
        $this->assertStringContainsString('<pPassword>********</pPassword>', $redacted);
        $this->assertStringContainsString('<pUsuario>USR</pUsuario>', $redacted);
        $this->assertStringContainsString('<pXml><![CDATA[<a/>]]></pXml>', $redacted);
    }

    private function invokeCertifier(string $name, string|array $argument): array|string
    {
        $certifier = new GuatefacturasCertifier(
            new DocumentBuilderFactory(),
            $this->createMock(FelConfigRepositoryInterface::class),
        );

        return (new ReflectionMethod($certifier, $name))->invoke($certifier, $argument);
    }

    private function soap(string $content, string $operation = 'generaDocumento', string $format = 'escaped'): string
    {
        $result = match ($format) {
            'nested' => $content,
            'cdata' => "<![CDATA[{$content}]]>",
            'escaped' => htmlspecialchars($content, ENT_XML1 | ENT_QUOTES, 'UTF-8'),
        };

        return <<<XML
        <env:Envelope xmlns:env="http://schemas.xmlsoap.org/soap/envelope/">
            <env:Body><{$operation}Response><return>{$result}</return></{$operation}Response></env:Body>
        </env:Envelope>
        XML;
    }
}
