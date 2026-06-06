<?php

namespace Lc\Fel\Services\Builders;

use Carbon\Carbon;
use Exception;
use Lc\Fel\Contracts\DocumentBuilderInterface;
use Lc\Fel\Data\FelDocumentData;
use Lc\Fel\Data\FelItemData;
use Lc\Fel\Services\Builders\Concerns\XmlHelpers;
use SimpleXMLElement;

class GeneralDocumentBuilder implements DocumentBuilderInterface
{
    use XmlHelpers;

    protected const IVA_RATE = 0.12;

    public function __construct(private readonly string $documentType)
    {
    }

    public function build(FelDocumentData $data): string
    {
        if (count($data->items) === 0) {
            throw new Exception('El documento no tiene detalle para certificar.');
        }

        $options = $data->options;
        $tipoVenta = $this->resolveTipoVenta($options);
        $tipoVentaDet = $this->resolveTipoVentaDet($tipoVenta, $options);
        $taxMode = $this->resolveTaxMode($options);

        $xml = new SimpleXMLElement('<DocElectronico/>');
        $encabezado = $xml->addChild('Encabezado');
        $this->appendReceptor($encabezado, $data);
        $this->appendInfoDoc($encabezado, $data, $tipoVenta);

        $detalles = $xml->addChild('Detalles');
        $totals = [
            'bruto' => 0.0,
            'descuento' => 0.0,
            'exento' => 0.0,
            'otros' => 0.0,
            'neto' => 0.0,
            'isr' => 0.0,
            'iva' => 0.0,
            'total' => 0.0,
        ];

        foreach ($data->items as $item) {
            $line = $this->buildLineData($item, $taxMode);
            $productos = $detalles->addChild('Productos');
            $productos->addChild('Producto', $this->limitString($line['codigo'], 20));
            $productos->addChild('Descripcion', $this->limitString($line['descripcion'], 2000));
            $productos->addChild('Medida', $line['medida']);
            $productos->addChild('Cantidad', $this->dec($line['cantidad'], 5));
            $productos->addChild('Precio', $this->dec($line['precio'], 7));
            $productos->addChild('PorcDesc', $this->dec(0, 6));
            $productos->addChild('ImpBruto', $this->dec($line['imp_bruto']));
            $productos->addChild('ImpDescuento', $this->dec(0));
            $productos->addChild('ImpExento', $this->dec($line['imp_exento']));
            $productos->addChild('ImpOtros', $this->dec(0));
            $productos->addChild('ImpNeto', $this->dec($line['imp_neto']));
            $productos->addChild('ImpIsr', $this->dec(0));
            $productos->addChild('ImpIva', $this->dec($line['imp_iva']));
            $productos->addChild('ImpTotal', $this->dec($line['imp_total']));
            $productos->addChild('TipoVentaDet', $tipoVentaDet);

            $totals['bruto'] += $line['imp_bruto'];
            $totals['exento'] += $line['imp_exento'];
            $totals['neto'] += $line['imp_neto'];
            $totals['iva'] += $line['imp_iva'];
            $totals['total'] += $line['imp_total'];
        }

        if ($this->requiresAssociatedDocument()) {
            $associated = $this->resolveAssociatedDocument($options);
            $docAsociados = $detalles->addChild('DocAsociados');
            $docAsociados->addChild('DASerie', $this->limitString($associated['serie'], 20));
            $docAsociados->addChild('DAPreimpreso', $this->limitString((string) $associated['preimpreso'], 18));
        }

        $totales = $encabezado->addChild('Totales');
        $totales->addChild('Bruto', $this->dec($totals['bruto']));
        $totales->addChild('Descuento', $this->dec($totals['descuento']));
        $totales->addChild('Exento', $this->dec($totals['exento']));
        $totales->addChild('Otros', $this->dec($totals['otros']));
        $totales->addChild('Neto', $this->dec($totals['neto']));
        $totales->addChild('Isr', $this->dec($totals['isr']));
        $totales->addChild('Iva', $this->dec($totals['iva']));
        $totales->addChild('Total', $this->dec($totals['total']));

        $this->appendDatosAdicionales($encabezado, $data, $totals['total']);
        $this->appendAbonosIfNeeded($encabezado, $data, $totals['total']);

        return $this->stripXmlDeclaration($xml->asXML() ?: '');
    }

    protected function appendReceptor(SimpleXMLElement $encabezado, FelDocumentData $data): void
    {
        $receptor = $encabezado->addChild('Receptor');
        $receptor->addChild('NITReceptor', $this->limitString($this->normalizeNit($data->receiver->nit), 20));
        $receptor->addChild('Nombre', $this->limitString($data->receiver->name ?: 'Consumidor Final', 200));
        $receptor->addChild('Direccion', $this->limitString($data->receiver->address ?: 'Ciudad', 200));
    }

    protected function appendInfoDoc(SimpleXMLElement $encabezado, FelDocumentData $data, string $tipoVenta): void
    {
        $options = $data->options;
        $infoDoc = $encabezado->addChild('InfoDoc');
        $infoDoc->addChild('TipoVenta', $tipoVenta);
        $infoDoc->addChild('DestinoVenta', (string) ((int) ($options['destino_venta'] ?? 1)));
        $infoDoc->addChild('Fecha', $data->date->format('d/m/Y'));
        $infoDoc->addChild('Moneda', (string) ((int) ($options['moneda'] ?? 1)));
        $infoDoc->addChild('Tasa', $this->dec((float) ($options['tasa'] ?? 1), 6));
        $infoDoc->addChild('Referencia', $this->limitString((string) ($options['referencia'] ?? $data->reference), 40));
        $infoDoc->addChild('NumeroAcceso', $this->limitString((string) ($options['numero_acceso'] ?? ''), 10));
        $infoDoc->addChild('SerieAdmin', $this->limitString((string) ($options['serie_admin'] ?? ''), 20));
        $infoDoc->addChild('NumeroAdmin', $this->limitString((string) ($options['numero_admin'] ?? ''), 18));

        if (array_key_exists('reversion', $options) && trim((string) $options['reversion']) !== '') {
            $infoDoc->addChild('Reversion', strtoupper((string) $options['reversion']) === 'S' ? 'S' : 'N');
        }
    }

    protected function appendDatosAdicionales(SimpleXMLElement $encabezado, FelDocumentData $data, float $total): void
    {
        $options = $data->options;
        $additional = [];

        if (!in_array($this->documentType, ['NABN', 'NCRE', 'NDEB'], true)) {
            $additional['TipoReceptor'] = (string) ($options['tipo_receptor'] ?? ($data->receiver->nit === 'CF' ? '4' : '4'));
        }

        if (in_array($this->documentType, ['FCAM', 'FCAP'], true)) {
            $additional = array_merge($additional, [
                'MONTO_FAC' => $total,
                'DIAS_CREDITO' => max(1, (int) ($options['credit_days'] ?? 30)),
                'RECARGO' => $options['recargo'] ?? 0,
                'PORC_RECARGO' => $options['porc_recargo'] ?? 0,
                'PORC_ADMON' => $options['porc_admon'] ?? 0,
                'PORC_MANEJO' => $options['porc_manejo'] ?? 0,
                'PORC_COBRANZA' => $options['porc_cobranza'] ?? 0,
            ]);
        }

        $additional = array_merge($additional, is_array($options['datos_adicionales'] ?? null) ? $options['datos_adicionales'] : []);
        if ($additional === []) {
            return;
        }

        $datosAdicionales = $encabezado->addChild('DatosAdicionales');
        foreach ($additional as $key => $value) {
            $tag = $this->sanitizeTagName((string) $key);
            if ($tag === '' || $value === null) {
                continue;
            }

            $normalized = is_numeric($value)
                ? preg_replace('/\\.00$/', '', number_format((float) $value, 2, '.', ''))
                : trim((string) $value);

            if ($normalized !== '') {
                $datosAdicionales->addChild($tag, $this->limitString($normalized, 2000));
            }
        }
    }

    protected function appendAbonosIfNeeded(SimpleXMLElement $encabezado, FelDocumentData $data, float $total): void
    {
        $options = $data->options;
        $documentHasAbonos = in_array($this->documentType, ['FCAM', 'FCAP'], true);
        $requestedAbonos = $options['abonos'] ?? null;

        if (!$documentHasAbonos && empty($requestedAbonos)) {
            return;
        }

        $abonos = is_array($requestedAbonos) && count($requestedAbonos) > 0
            ? $requestedAbonos
            : [[
                'numero_abono' => 1,
                'fecha_vencimiento' => Carbon::now()->addDays(max(1, (int) ($options['credit_days'] ?? 30)))->format('Ymd'),
                'monto_abono' => $total,
            ]];

        $abonosFactura = $encabezado->addChild('AbonosFacturaCambiaria');
        foreach ($abonos as $index => $row) {
            $fecha = (string) ($row['fecha_vencimiento'] ?? Carbon::now()->addDays(30)->format('Ymd'));
            if (preg_match('/^\d{8}$/', $fecha) !== 1) {
                $fecha = Carbon::parse($fecha)->format('Ymd');
            }

            $abono = $abonosFactura->addChild('Abono');
            $abono->addChild('NumeroAbono', (string) ((int) ($row['numero_abono'] ?? ($index + 1))));
            $abono->addChild('FechaVencimiento', $fecha);
            $abono->addChild('MontoAbono', $this->dec((float) ($row['monto_abono'] ?? $total)));
        }
    }

    protected function buildLineData(FelItemData $item, string $taxMode): array
    {
        if ($item->quantity <= 0 || $item->unitPrice < 0) {
            throw new Exception('Los items enviados para el documento FEL tienen cantidad/precio inválidos.');
        }

        $tax = $this->calculateTaxAmounts($item->quantity, $item->unitPrice, $taxMode);

        return [
            'codigo' => $item->code,
            'descripcion' => $item->description,
            'medida' => (string) ((int) $item->measure),
            'cantidad' => $item->quantity,
            'precio' => $item->unitPrice,
            ...$tax,
        ];
    }

    protected function calculateTaxAmounts(float $cantidad, float $precio, string $taxMode): array
    {
        $impBruto = round($cantidad * $precio, 2);

        if ($taxMode === 'EXEMPT') {
            return [
                'imp_bruto' => $impBruto,
                'imp_exento' => $impBruto,
                'imp_neto' => 0.0,
                'imp_iva' => 0.0,
                'imp_total' => $impBruto,
            ];
        }

        $impNeto = round($impBruto / (1 + self::IVA_RATE), 2);

        return [
            'imp_bruto' => $impBruto,
            'imp_exento' => 0.0,
            'imp_neto' => $impNeto,
            'imp_iva' => round($impBruto - $impNeto, 2),
            'imp_total' => $impBruto,
        ];
    }

    protected function resolveTaxMode(array $options): string
    {
        $fromOption = strtoupper(trim((string) ($options['tax_mode'] ?? '')));
        if (in_array($fromOption, ['EXEMPT', 'TAXED'], true)) {
            return $fromOption;
        }

        if (in_array($this->documentType, ['FPEQ', 'FCAP'], true)) {
            return 'EXEMPT';
        }

        return (int) ($options['destino_venta'] ?? 1) !== 1 ? 'EXEMPT' : 'TAXED';
    }

    protected function resolveAssociatedDocument(array $options): array
    {
        $associated = $options['associated_document'] ?? null;
        if (!is_array($associated)) {
            throw new Exception("El tipo de documento {$this->documentType} requiere serie y preimpreso del documento asociado.");
        }

        $serie = trim((string) ($associated['serie'] ?? ''));
        $preimpreso = trim((string) ($associated['preimpreso'] ?? ''));
        if ($serie === '' || $preimpreso === '') {
            throw new Exception("El tipo de documento {$this->documentType} requiere serie y preimpreso del documento asociado.");
        }

        return compact('serie', 'preimpreso');
    }

    protected function resolveTipoVenta(array $options): string
    {
        $tipo = strtoupper(trim((string) ($options['tipo_venta'] ?? 'B')));
        return in_array($tipo, ['B', 'S'], true) ? $tipo : 'B';
    }

    protected function resolveTipoVentaDet(string $fallback, array $options): string
    {
        $tipo = strtoupper(trim((string) ($options['tipo_venta_det'] ?? $fallback)));
        return in_array($tipo, ['B', 'S'], true) ? $tipo : $fallback;
    }

    protected function requiresAssociatedDocument(): bool
    {
        return in_array($this->documentType, ['NABN', 'NCRE', 'NDEB'], true);
    }
}
