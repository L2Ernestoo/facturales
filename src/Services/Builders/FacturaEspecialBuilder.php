<?php

namespace Lc\Fel\Services\Builders;

use Exception;
use Lc\Fel\Contracts\DocumentBuilderInterface;
use Lc\Fel\Data\FelDocumentData;
use Lc\Fel\Services\Builders\Concerns\XmlHelpers;
use SimpleXMLElement;

class FacturaEspecialBuilder implements DocumentBuilderInterface
{
    use XmlHelpers;

    protected const IVA_RATE = 0.12;

    public function build(FelDocumentData $data): string
    {
        if (count($data->items) === 0) {
            throw new Exception('El documento no tiene detalle para certificar.');
        }

        $options = $data->options;
        $tipoVenta = $this->resolveTipoVenta($options);
        $tipoVentaDet = $this->resolveTipoVentaDet($tipoVenta, $options);
        $porcIsr = (float) ($options['porc_isr'] ?? 0.05);
        if ($porcIsr < 0) {
            throw new Exception('El porcentaje de ISR no puede ser negativo.');
        }

        $bruto = round((float) ($options['total_bruto'] ?? $data->total), 2);
        $neto = round($bruto / (1 + self::IVA_RATE), 2);
        $iva = round($bruto - $neto, 2);
        $isr = round($neto * $porcIsr, 2);

        $first = $data->items[0];
        $xml = new SimpleXMLElement('<DocElectronico/>');
        $encabezado = $xml->addChild('Encabezado');
        $receptor = $encabezado->addChild('Receptor');
        $receptor->addChild('NITVendedor', $this->limitString($this->normalizeNit($options['nit_vendedor'] ?? $data->receiver->nit), 20));
        $receptor->addChild('NombreVendedor', $this->limitString((string) ($options['nombre_vendedor'] ?? $data->receiver->name), 200));
        $receptor->addChild('Direccion', $this->limitString((string) ($options['direccion_vendedor'] ?? $data->receiver->address), 200));

        $infoDoc = $encabezado->addChild('InfoDoc');
        $infoDoc->addChild('TipoVenta', $tipoVenta);
        $infoDoc->addChild('DestinoVenta', (string) ((int) ($options['destino_venta'] ?? 1)));
        $infoDoc->addChild('Fecha', $data->date->format('d/m/Y'));
        $infoDoc->addChild('Moneda', (string) ((int) ($options['moneda'] ?? 1)));
        $infoDoc->addChild('Tasa_Cambio', $this->dec((float) ($options['tasa_cambio'] ?? 1), 6));
        $infoDoc->addChild('TipoDocIdentificacion', (string) ((int) ($options['tipo_doc_identificacion'] ?? 1)));
        $infoDoc->addChild('NumeroIdentificacion', $this->limitString((string) ($options['numero_identificacion'] ?? $data->receiver->nit), 40));
        $infoDoc->addChild('PaisEmision', (string) ((int) ($options['pais_emision'] ?? 1)));
        $infoDoc->addChild('DepartamentoEmision', (string) ((int) ($options['departamento_emision'] ?? 1)));
        $infoDoc->addChild('MunicipioEmision', (string) ((int) ($options['municipio_emision'] ?? 1)));
        $infoDoc->addChild('Referencia', $this->limitString((string) ($options['referencia'] ?? $data->reference), 40));
        $infoDoc->addChild('PorcISR', $this->dec($porcIsr, 4));

        if (array_key_exists('reversion', $options)) {
            $infoDoc->addChild('Reversion', strtoupper((string) $options['reversion']) === 'S' ? 'S' : 'N');
        }

        $totales = $encabezado->addChild('Totales');
        $totales->addChild('Bruto', $this->dec($bruto));
        $totales->addChild('Descuento', $this->dec(0));
        $totales->addChild('Exento', $this->dec(0));
        $totales->addChild('Otros', $this->dec(0));
        $totales->addChild('Neto', $this->dec($neto));
        $totales->addChild('Isr', $this->dec($isr));
        $totales->addChild('Iva', $this->dec($iva));
        $totales->addChild('Total', $this->dec($bruto));

        $detalles = $xml->addChild('Detalles');
        $productos = $detalles->addChild('Productos');
        $productos->addChild('Producto', $this->limitString((string) ($options['producto_codigo'] ?? $first->code), 20));
        $productos->addChild('Descripcion', $this->limitString((string) ($options['descripcion'] ?? $first->description), 2000));
        $productos->addChild('Medida', (string) ((int) ($options['medida_codigo'] ?? $first->measure)));
        $productos->addChild('Cantidad', $this->dec((float) ($options['cantidad'] ?? 1), 5));
        $productos->addChild('Precio', $this->dec($bruto, 7));
        $productos->addChild('PorcDesc', $this->dec(0, 6));
        $productos->addChild('ImpBruto', $this->dec($bruto));
        $productos->addChild('ImpDescuento', $this->dec(0));
        $productos->addChild('ImpExento', $this->dec(0));
        $productos->addChild('ImpOtros', $this->dec(0));
        $productos->addChild('ImpNeto', $this->dec($neto));
        $productos->addChild('ImpIsr', $this->dec($isr));
        $productos->addChild('ImpIva', $this->dec($iva));
        $productos->addChild('ImpTotal', $this->dec($bruto));

        $datosProd = $productos->addChild('DatosAdicionalesProd');
        $datosProd->addChild('TipoVentaDet', $tipoVentaDet);

        return $this->stripXmlDeclaration($xml->asXML() ?: '');
    }

    protected function resolveTipoVenta(array $options): string
    {
        $tipo = strtoupper(trim((string) ($options['tipo_venta'] ?? 'S')));
        return in_array($tipo, ['B', 'S'], true) ? $tipo : 'S';
    }

    protected function resolveTipoVentaDet(string $fallback, array $options): string
    {
        $tipo = strtoupper(trim((string) ($options['tipo_venta_det'] ?? $fallback)));
        return in_array($tipo, ['B', 'S'], true) ? $tipo : $fallback;
    }
}
