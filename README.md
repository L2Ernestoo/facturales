# FacturalesGT

Reusable Laravel package for Guatemalan FEL certification.

## Install locally

Add a path repository in the host app:

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
    "l2ernestoo/facturalesgt": "dev-main"
  }
}
```

Then run:

```bash
composer update l2ernestoo/facturalesgt --with-all-dependencies
php artisan migrate
```

## Design

- The package stores FEL company, branch, credential, document, item and annulment data in database tables.
- Credentials are encrypted with Laravel Crypt.
- The package does not depend on app-specific models such as `Order` or `Sale`.
- Host apps provide a `FelSourceMapperInterface` implementation to convert their source record into `FelDocumentData`.

## Included certifier

- Guatefacturas SOAP generation and annulment.

## Included DTE builders

`FACT`, `FCAM`, `FPEQ`, `FCAP`, `FESP`, `NABN`, `RDON`, `RECI`, `NDEB`, `NCRE`.
