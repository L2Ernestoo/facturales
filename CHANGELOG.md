# Changelog

## v1.1.0

Compatible con v1.0.x: no hay migraciones nuevas ni cambios de firmas públicas. Los documentos sin descuentos y sin "&" generan exactamente el mismo XML que v1.0.2.

### Seguridad

- El envelope SOAP guardado en `fel_documents.soap_request` y el devuelto en `data.soap_request` / `debug.soap_request` ya no incluye el password FEL (`<pPassword>********</pPassword>`). Antes quedaba en texto plano en la base de datos y, si la app devolvía el resultado de `issue()` / `certifyExisting()` como JSON, llegaba al navegador.
- Nuevo comando `php artisan fel:redact-credentials [--dry-run]` para limpiar los documentos guardados por versiones anteriores.
- Si una credencial no se puede descifrar (por ejemplo, un `APP_KEY` distinto), se registra un warning. Se sigue usando el valor guardado, como antes.

### Correcciones

- Los descuentos por línea (`FelItemData::$discount`) ahora se envían al certificador (`PorcDesc`, `ImpDescuento`, `Totales/Descuento`), y el IVA se calcula sobre el monto con descuento. Antes se ignoraban y la factura salía por el precio completo.
- Los textos con "&" (nombres, direcciones, descripciones) se escapan bien. Antes el carácter se eliminaba sin aviso.
- `certifyExisting()` ya no reenvía un DTE que está `CERTIFIED`: devuelve `success: true` y `already_certified: true` con el UUID existente. Un DTE `ANNULLED` tampoco se puede reintentar.
- Dos certificaciones o anulaciones simultáneas del mismo documento (doble clic, reintentos) quedan bloqueadas con un lock de cache. La segunda recibe `busy: true`. Si el cache no soporta locks, se continúa como antes.
- `annul()` valida que el documento esté `CERTIFIED` y tenga serie y preimpreso antes de llamar al certificador, y no permite anular dos veces. En esos casos devuelve `success: false` y `annulment: null`.
- Cada documento se certifica y se anula con el certificador configurado para su propia empresa (`fel_companies.certifier`), no con el de la primera empresa activa.
- Al reintentar con `certifyExisting()`, el snapshot (receptor, total, items) se actualiza con los datos que realmente se envían.
- La fecha de vencimiento por defecto de FCAM/FCAP se calcula desde la fecha del documento, no desde la hora del servidor.

### Nuevo

- Cuando el certificador no responde a tiempo, el resultado incluye `uncertain: true`, el documento guarda `metadata.certification_uncertain` y el mensaje pide verificar en el portal de Guatefacturas antes de reintentar.
- Si el total declarado no coincide con la suma de los items, se registra un warning y `metadata.total_mismatch`. Con `fel.strict_totals = true`, `issue()` rechaza el documento.
- Tests de integración con Testbench y fixtures de regresión del XML de v1.0.2.
