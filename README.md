# FacturalesGT

FacturalesGT es un package Laravel para emitir, almacenar y anular documentos FEL de Guatemala desde aplicaciones propias, puntos de venta, ERPs o sistemas administrativos.

La primera version esta disenada para trabajar con el certificador **Ainnova / Guatefacturas** mediante su servicio SOAP. El package mantiene una arquitectura extensible para agregar otros certificadores sin acoplar la logica fiscal a modelos de una aplicacion especifica.

## Caracteristicas

- Configuracion desde base de datos, no desde `.env`.
- Credenciales cifradas con `Laravel Crypt`.
- Empresa emisora, sucursales fiscales, credenciales, documentos, items y anulaciones.
- Builders para `FACT`, `FCAM`, `FPEQ`, `FCAP`, `FESP`, `NABN`, `RDON`, `RECI`, `NDEB` y `NCRE`.
- Certificacion y anulacion con Ainnova / Guatefacturas.
- Snapshots de documentos emitidos para auditoria.
- Mapeo independiente del modelo fuente: el package no conoce `Order`, `Sale`, `Invoice` ni ningun modelo de tu app.
- Soporte para limites mensuales de DTE y configuracion POS por sucursal.

## Requisitos

- PHP `^8.2`
- Laravel `^11`, `^12` o `^13`
- Extensiones PHP `curl` y `simplexml`
- Una aplicacion Laravel con `APP_KEY` configurado para poder cifrar credenciales

## Instalacion

Cuando el package ya este publicado en Packagist:

```bash
composer require l2ernestoo/facturalesgt
php artisan migrate
```

Durante desarrollo local puedes usar un repository tipo `path`:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../LC-FEL",
      "options": { "symlink": true }
    }
  ],
  "require": {
    "l2ernestoo/facturalesgt": "dev-master"
  }
}
```

Luego ejecuta:

```bash
composer update l2ernestoo/facturalesgt
php artisan migrate
```

## Configuracion opcional

El package carga migraciones automaticamente. Si quieres personalizar defaults tecnicos o nombres de tablas, publica el config:

```bash
php artisan vendor:publish --tag=fel-config
```

El archivo `config/fel.php` no debe usarse para guardar usuarios, passwords o URLs privadas por ambiente. Esas credenciales se guardan en base de datos.

## Tablas creadas

- `fel_companies`: empresa emisora principal, NIT, regimen, certificador, modo y limites.
- `fel_company_credentials`: usuario FEL, password FEL, basic auth, URLs y maquina.
- `fel_branch_settings`: relacion entre empresa emisora y sucursal del sistema.
- `fel_documents`: documentos emitidos o con error.
- `fel_document_items`: snapshot de lineas certificadas.
- `fel_annulments`: anulaciones y respuesta del certificador.

Los nombres se pueden cambiar desde `config/fel.php`.

## Crear configuracion Ainnova / Guatefacturas

```php
use Lc\Fel\Models\FelBranchSetting;
use Lc\Fel\Models\FelCompany;
use Lc\Fel\Models\FelCompanyCredential;

$company = FelCompany::create([
    'name' => 'Mi Empresa, S.A.',
    'nit' => '1234567',
    'regime' => 'general', // general, pequeno_contribuyente, etc.
    'certifier' => 'guatefacturas',
    'mode' => 'test', // test o prod
    'default_document_type' => 'FACT',
    'show_pos_switch' => true,
    'auto_mark_pos_switch' => true,
    'monthly_dte_limit' => 1000,
]);

FelCompanyCredential::create([
    'fel_company_id' => $company->id,
    'user' => 'USUARIO_FEL',
    'password' => 'PASSWORD_FEL',
    'basic_user' => 'BASIC_USER',
    'basic_password' => 'BASIC_PASSWORD',
    'url_test' => 'https://dte.guatefacturas.com/webservices63/feltestSB/Guatefac',
    'url_prod' => 'https://dte.guatefacturas.com/webservices63/fel/Guatefac',
    'machine_id' => '1',
]);

FelBranchSetting::create([
    'fel_company_id' => $company->id,
    'branch_source_id' => 1, // ID de la sucursal en tu app
    'fiscal_name' => 'Sucursal Principal',
    'address' => 'Ciudad',
    'establishment_code' => '1',
    'is_active' => true,
]);
```

> Nota: `password` y `basic_password` se cifran automaticamente. No los guardes en `.env`.

## Mapear una venta, orden o factura

Cada aplicacion debe convertir su modelo fuente a `FelDocumentData`. Para eso puedes implementar `FelSourceMapperInterface`.

```php
namespace App\Services\Fel;

use App\Models\Order;
use Lc\Fel\Contracts\FelSourceMapperInterface;
use Lc\Fel\Data\FelBranchData;
use Lc\Fel\Data\FelCompanyData;
use Lc\Fel\Data\FelDocumentData;
use Lc\Fel\Data\FelItemData;
use Lc\Fel\Data\FelReceiverData;
use Lc\Fel\Repositories\DatabaseFelConfigRepository;

class OrderFelMapper implements FelSourceMapperInterface
{
    public function __construct(
        private readonly DatabaseFelConfigRepository $config,
    ) {
    }

    public function map(mixed $source, array $options = []): FelDocumentData
    {
        /** @var Order $source */
        $company = $this->config->company($options['company_id'] ?? null);
        $branch = $this->config->branchSetting($source->branch_id, $company?->id);

        return new FelDocumentData(
            documentType: $options['document_type'] ?? $company->default_document_type,
            reference: 'ORD-' . $source->id,
            date: now(),
            company: new FelCompanyData(
                id: $company->id,
                name: $company->name,
                nit: $company->nit,
                regime: $company->regime,
                certifier: $company->certifier,
                mode: $company->mode,
                monthlyDteLimit: $company->monthly_dte_limit,
            ),
            branch: new FelBranchData(
                id: $branch->id,
                sourceBranchId: $branch->branch_source_id,
                companyId: $company->id,
                name: $branch->fiscal_name,
                address: $branch->address,
                establishmentCode: $branch->establishment_code,
                logoPath: $branch->logo_path,
            ),
            receiver: new FelReceiverData(
                nit: $options['nit'] ?? 'CF',
                name: $options['name'] ?? 'Consumidor Final',
                address: $options['address'] ?? 'Ciudad',
            ),
            items: $source->items->map(fn ($item) => new FelItemData(
                code: (string) $item->product_id,
                description: $item->product->name,
                quantity: (float) $item->quantity,
                unitPrice: (float) $item->price,
                measure: '1',
                discount: 0,
                metadata: ['source_item_id' => $item->id],
            ))->all(),
            total: (float) $source->total,
            sourceType: Order::class,
            sourceId: $source->id,
            options: $options,
        );
    }
}
```

## Emitir un DTE

```php
use App\Models\Order;
use App\Services\Fel\OrderFelMapper;
use Lc\Fel\Services\FelManager;

$order = Order::with(['items.product'])->findOrFail($id);

$data = app(OrderFelMapper::class)->map($order, [
    'document_type' => 'FACT',
    'nit' => 'CF',
    'name' => 'Consumidor Final',
    'address' => 'Ciudad',
]);

$result = app(FelManager::class)->issue($data);

if ($result['success']) {
    $document = $result['document'];

    echo $document->fel_uuid;
    echo $document->fel_serie;
    echo $document->fel_preimpreso;
}
```

Si Ainnova / Guatefacturas responde correctamente, el documento queda con status `CERTIFIED`. Si el certificador falla, queda con status `ERROR` y se conserva `request_xml`, `soap_request`, `response_body` o `error_message` segun aplique.

## Reintentar un documento con error

```php
use Lc\Fel\Models\FelDocument;
use Lc\Fel\Services\FelManager;

$document = FelDocument::with('items')->findOrFail($documentId);
$data = app(OrderFelMapper::class)->map($order, $document->metadata['options'] ?? []);

$result = app(FelManager::class)->certifyExisting($document, $data);
```

## Anular un DTE

```php
use Lc\Fel\Models\FelDocument;
use Lc\Fel\Services\FelManager;

$document = FelDocument::where('fel_uuid', $uuid)->firstOrFail();

$result = app(FelManager::class)->annul($document, 'Anulacion solicitada por el cliente', [
    'user_id' => auth()->id(),
]);
```

Si la anulacion es aceptada, el documento pasa a status `ANNULLED` y se crea un registro en `fel_annulments`.

## Configuracion para POS

Puedes leer la configuracion activa por sucursal:

```php
use Lc\Fel\Services\FelManager;

$settings = app(FelManager::class)->posSettings($branchId);
```

El resultado incluye empresa activa, documento por defecto, si debe mostrarse un switch de FEL, si debe marcarse automaticamente y el uso mensual:

```php
[
    'enabled' => true,
    'company_id' => 1,
    'default_document_type' => 'FACT',
    'show_pos_switch' => true,
    'auto_mark_pos_switch' => true,
    'usage' => [
        'limit' => 1000,
        'used' => 150,
        'remaining' => 850,
        'percent' => 15.0,
    ],
]
```

## Tipos de documento soportados

| Tipo | Descripcion |
| --- | --- |
| `FACT` | Factura |
| `FCAM` | Factura cambiaria |
| `FPEQ` | Factura pequeno contribuyente |
| `FCAP` | Factura cambiaria pequeno contribuyente |
| `FESP` | Factura especial |
| `NABN` | Nota de abono |
| `RDON` | Recibo por donacion |
| `RECI` | Recibo |
| `NDEB` | Nota de debito |
| `NCRE` | Nota de credito |

Para `NCRE` y `NDEB`, envia el documento asociado en `options`:

```php
[
    'associated_document' => [
        'serie' => 'A1',
        'preimpreso' => '123',
    ],
]
```

## Respuesta certificada

El package guarda datos importantes devueltos por Ainnova / Guatefacturas:

```php
$document->fel_uuid;       // NumeroAutorizacion
$document->fel_serie;      // Serie
$document->fel_preimpreso; // Preimpreso

$document->metadata['certifier_response'] ?? [];
```

`metadata.certifier_response` puede incluir `nombre`, `direccion`, `telefono` y `referencia`. Esto es util para tickets, PDFs, reportes y auditoria.

## Extender a otro certificador

Implementa `CertifierInterface` y registralo en `config/fel.php`:

```php
'certifiers' => [
    'guatefacturas' => Lc\Fel\Services\Certifiers\GuatefacturasCertifier::class,
    'otro' => App\Fel\Certifiers\OtroCertifier::class,
],
```

Luego configura la empresa con `certifier = otro`.

## Ejecutar pruebas

```bash
composer install
vendor/bin/phpunit
```

## Licencia

MIT
