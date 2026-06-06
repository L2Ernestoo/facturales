<?php

namespace Lc\Fel\Tests\Unit;

use Carbon\Carbon;
use Lc\Fel\Data\FelBranchData;
use Lc\Fel\Data\FelCompanyData;
use Lc\Fel\Data\FelDocumentData;
use Lc\Fel\Data\FelItemData;
use Lc\Fel\Data\FelReceiverData;
use Lc\Fel\Services\Builders\GeneralDocumentBuilder;
use PHPUnit\Framework\TestCase;

class GeneralDocumentBuilderTest extends TestCase
{
    public function test_fact_document_calculates_taxed_totals(): void
    {
        $xml = (new GeneralDocumentBuilder('FACT'))->build($this->document('FACT'));

        $this->assertStringContainsString('<NITReceptor>CF</NITReceptor>', $xml);
        $this->assertStringContainsString('<Iva>12.00</Iva>', $xml);
        $this->assertStringContainsString('<Total>112.00</Total>', $xml);
    }

    public function test_fpeq_document_is_exempt(): void
    {
        $xml = (new GeneralDocumentBuilder('FPEQ'))->build($this->document('FPEQ'));

        $this->assertStringContainsString('<ImpExento>112.00</ImpExento>', $xml);
        $this->assertStringContainsString('<Iva>0.00</Iva>', $xml);
    }

    public function test_credit_note_uses_associated_document(): void
    {
        $xml = (new GeneralDocumentBuilder('NCRE'))->build($this->document('NCRE', [
            'associated_document' => ['serie' => 'A1', 'preimpreso' => '123'],
        ]));

        $this->assertStringContainsString('<DASerie>A1</DASerie>', $xml);
        $this->assertStringContainsString('<DAPreimpreso>123</DAPreimpreso>', $xml);
    }

    private function document(string $type, array $options = []): FelDocumentData
    {
        return new FelDocumentData(
            documentType: $type,
            reference: 'TEST-1',
            date: Carbon::parse('2026-06-06'),
            company: new FelCompanyData(1, 'Empresa Demo', '1234567', 'general', 'guatefacturas', 'test'),
            branch: new FelBranchData(1, 1, 1, 'Sucursal Demo', 'Ciudad', '1'),
            receiver: new FelReceiverData('CF', 'Consumidor Final', 'Ciudad'),
            items: [new FelItemData('1', 'Producto demo', 1, 112)],
            total: 112,
            sourceType: 'test',
            sourceId: 1,
            options: $options,
        );
    }
}
