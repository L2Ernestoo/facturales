<?php

namespace Lc\Fel\Tests\Unit;

use Lc\Fel\Contracts\FelConfigRepositoryInterface;
use Lc\Fel\Services\Certifiers\GuatefacturasCertifier;
use Lc\Fel\Services\DocumentBuilderFactory;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class GuatefacturasCertifierTest extends TestCase
{
    public function test_generate_response_parses_certifier_receiver_name(): void
    {
        $soapResponse = '<env:Envelope xmlns:env="http://schemas.xmlsoap.org/soap/envelope/"><env:Body><m:generaDocumentoResponse xmlns:m="http://dbguatefac/Guatefac.wsdl"><result>&lt;Resultado&gt;&lt;Serie&gt;BADD0C84&lt;/Serie&gt;&lt;Preimpreso&gt;2692563664&lt;/Preimpreso&gt;&lt;Nombre&gt;DOCUMENTO DE PRUEBAS MACAW GROUP, SOCIEDAD ANONIMA&lt;/Nombre&gt;&lt;Direccion&gt;Ciudad&lt;/Direccion&gt;&lt;Telefono&gt;&lt;/Telefono&gt;&lt;NumeroAutorizacion&gt;BADD0C84-A07D-42D0-96F1-5A7640EF9C50&lt;/NumeroAutorizacion&gt;&lt;Referencia&gt;ORD-5&lt;/Referencia&gt;&lt;/Resultado&gt;</result></m:generaDocumentoResponse></env:Body></env:Envelope>';

        $certifier = new GuatefacturasCertifier(
            new DocumentBuilderFactory(),
            $this->createMock(FelConfigRepositoryInterface::class),
        );
        $method = new ReflectionMethod($certifier, 'parseGenerateSoapResponse');
        $method->setAccessible(true);

        $parsed = $method->invoke($certifier, $soapResponse);

        $this->assertSame('BADD0C84', $parsed['serie']);
        $this->assertSame('2692563664', $parsed['preimpreso']);
        $this->assertSame('BADD0C84-A07D-42D0-96F1-5A7640EF9C50', $parsed['uuid']);
        $this->assertSame('DOCUMENTO DE PRUEBAS MACAW GROUP, SOCIEDAD ANONIMA', $parsed['nombre']);
        $this->assertSame('Ciudad', $parsed['direccion']);
        $this->assertSame('ORD-5', $parsed['referencia']);
    }
}
