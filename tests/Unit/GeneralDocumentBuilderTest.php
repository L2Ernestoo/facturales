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

    public function test_ampersands_are_kept_and_escaped(): void
    {
        $xml = (new GeneralDocumentBuilder('FACT'))->build($this->document('FACT', [], [
            new FelItemData('1', 'Pollo & Papas', 1, 112),
        ], new FelReceiverData('CF', 'Pérez & Hijos', 'Zona <1>')));

        $parsed = simplexml_load_string($xml);
        $this->assertSame('Pollo & Papas', (string) $parsed->Detalles->Productos->Descripcion);
        $this->assertSame('Pérez & Hijos', (string) $parsed->Encabezado->Receptor->Nombre);
        $this->assertSame('Zona <1>', (string) $parsed->Encabezado->Receptor->Direccion);
    }

    public function test_line_discount_reduces_taxable_amount(): void
    {
        $xml = (new GeneralDocumentBuilder('FACT'))->build($this->document('FACT', [], [
            new FelItemData('1', 'Producto', 2, 56, '1', 12),
        ]));

        $line = simplexml_load_string($xml)->Detalles->Productos;
        $this->assertSame('112.00', (string) $line->ImpBruto);
        $this->assertSame('12.00', (string) $line->ImpDescuento);
        $this->assertSame('10.714286', (string) $line->PorcDesc);
        $this->assertSame('89.29', (string) $line->ImpNeto);
        $this->assertSame('10.71', (string) $line->ImpIva);
        $this->assertSame('100.00', (string) $line->ImpTotal);
        $this->assertStringContainsString('<Descuento>12.00</Descuento>', $xml);
        $this->assertStringContainsString('<Total>100.00</Total>', $xml);
    }

    public function test_line_discount_on_exempt_document(): void
    {
        $xml = (new GeneralDocumentBuilder('FPEQ'))->build($this->document('FPEQ', [], [
            new FelItemData('1', 'Producto', 1, 50, '1', 5),
        ]));

        $line = simplexml_load_string($xml)->Detalles->Productos;
        $this->assertSame('45.00', (string) $line->ImpExento);
        $this->assertSame('45.00', (string) $line->ImpTotal);
        $this->assertSame('0.00', (string) $line->ImpIva);
    }

    public function test_discount_greater_than_line_is_rejected(): void
    {
        $this->expectExceptionMessage('descuento');

        (new GeneralDocumentBuilder('FACT'))->build($this->document('FACT', [], [
            new FelItemData('1', 'Producto', 1, 10, '1', 11),
        ]));
    }

    public function test_fcam_due_date_is_based_on_document_date(): void
    {
        $xml = (new GeneralDocumentBuilder('FCAM'))->build($this->document('FCAM', ['credit_days' => 10]));

        $this->assertStringContainsString('<FechaVencimiento>20260616</FechaVencimiento>', $xml);
    }

    private function document(string $type, array $options = [], ?array $items = null, ?FelReceiverData $receiver = null): FelDocumentData
    {
        return new FelDocumentData(
            documentType: $type,
            reference: 'TEST-1',
            date: Carbon::parse('2026-06-06'),
            company: new FelCompanyData(1, 'Empresa Demo', '1234567', 'general', 'guatefacturas', 'test'),
            branch: new FelBranchData(1, 1, 1, 'Sucursal Demo', 'Ciudad', '1'),
            receiver: $receiver ?? new FelReceiverData('CF', 'Consumidor Final', 'Ciudad'),
            items: $items ?? [new FelItemData('1', 'Producto demo', 1, 112)],
            total: 112,
            sourceType: 'test',
            sourceId: 1,
            options: $options,
        );
    }
}
