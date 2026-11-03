<?php

namespace Lc\Fel\Tests\Unit;

use Carbon\Carbon;
use Lc\Fel\Contracts\DocumentBuilderInterface;
use Lc\Fel\Data\FelBranchData;
use Lc\Fel\Data\FelCompanyData;
use Lc\Fel\Data\FelDocumentData;
use Lc\Fel\Data\FelItemData;
use Lc\Fel\Data\FelReceiverData;
use Lc\Fel\Services\Builders\FacturaEspecialBuilder;
use Lc\Fel\Services\Builders\GeneralDocumentBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Los fixtures se generaron con v1.0.2. Documentos sin descuentos ni "&"
 * deben producir exactamente el mismo XML que ya se certifica en producción.
 */
class XmlRegressionTest extends TestCase
{
    protected function setUp(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-06 10:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
    }

    #[DataProvider('cases')]
    public function test_xml_matches_v1_0_2_output(string $fixture, DocumentBuilderInterface $builder, FelDocumentData $data): void
    {
        $this->assertSame(
            file_get_contents(__DIR__ . "/../Fixtures/xml/{$fixture}.xml"),
            $builder->build($data)
        );
    }

    public static function cases(): array
    {
        $items = [
            new FelItemData('A1', 'Producto uno', 2, 15.5),
            new FelItemData('B2', 'Servicio dos', 1.5, 33.333, '2'),
            new FelItemData('C3', str_repeat('x', 30), 3, 0.99),
        ];

        return [
            'fact' => ['fact', new GeneralDocumentBuilder('FACT'), self::document('FACT', $items)],
            'fact_cf' => ['fact_cf', new GeneralDocumentBuilder('FACT'), self::document('FACT', $items, [], 'c/f')],
            'fact_exempt' => ['fact_exempt', new GeneralDocumentBuilder('FACT'), self::document('FACT', $items, [
                'destino_venta' => 2,
                'datos_adicionales' => ['Mesa' => 5, '1x' => 'a'],
            ])],
            'fpeq' => ['fpeq', new GeneralDocumentBuilder('FPEQ'), self::document('FPEQ', $items)],
            'fcam' => ['fcam', new GeneralDocumentBuilder('FCAM'), self::document('FCAM', $items, ['credit_days' => 15])],
            'fcap_abonos' => ['fcap_abonos', new GeneralDocumentBuilder('FCAP'), self::document('FCAP', $items, [
                'abonos' => [['fecha_vencimiento' => '2026-07-01', 'monto_abono' => 10], ['monto_abono' => 5]],
            ])],
            'ncre' => ['ncre', new GeneralDocumentBuilder('NCRE'), self::document('NCRE', $items, [
                'associated_document' => ['serie' => 'A1', 'preimpreso' => '99'],
                'reversion' => 's',
            ])],
            'reci' => ['reci', new GeneralDocumentBuilder('RECI'), self::document('RECI', $items, ['tipo_venta' => 'S'])],
            'fesp' => ['fesp', new FacturaEspecialBuilder(), self::document('FESP', $items, ['porc_isr' => 0.07])],
        ];
    }

    private static function document(string $type, array $items, array $options = [], string $nit = '1234567K'): FelDocumentData
    {
        return new FelDocumentData(
            documentType: $type,
            reference: 'REF-1',
            date: Carbon::parse('2026-06-06 10:00:00'),
            company: new FelCompanyData(1, 'Demo', '1234567'),
            branch: new FelBranchData(1, 1, 1, 'Suc', 'Ciudad', '1'),
            receiver: new FelReceiverData($nit, 'Juan Perez', 'Zona 1'),
            items: $items,
            total: array_sum(array_map(fn (FelItemData $item) => $item->quantity * $item->unitPrice, $items)),
            sourceType: 'test',
            sourceId: 1,
            options: $options,
        );
    }
}
