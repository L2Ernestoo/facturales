# Changelog

## Próxima versión

### Emisión, reintentos y anulaciones

- `issue()` reutiliza la certificación existente para la misma empresa, `source_type`, `source_id` y tipo de DTE normalizado. La respuesta incluye `already_certified: true` y los identificadores existentes. Si hay un error confirmado, devuelve el documento con `blocked: true` para reintentar por `certifyExisting()`. Un DTE anulado permite crear un reemplazo.
- La reserva de cada operación se guarda antes de llamar al certificador. `certifyExisting()` exige conservar empresa, origen y tipo del DTE, y aplica `strict_totals` antes de actualizar el documento y sus ítems.
- La integración con Guatefacturas exige UUID con formato válido, serie y preimpreso para certificar, y una confirmación afirmativa explícita para anular. Si la anulación devuelve serie o preimpreso, debe coincidir con el DTE enviado.
- Una respuesta ambigua, un timeout o un intento interrumpido requieren conciliación antes de reenviar. Una anulación incierta queda en `fel_annulments.status = UNCERTAIN`, y el DTE conserva su estado `CERTIFIED` hasta confirmar el resultado.

### Actualización en producción

La actualización usa las tablas y columnas existentes y conserva las firmas públicas. Los registros históricos se consultan al emitir o reintentar, sin transformación automática.

1. **Ambientes SOAP.** Verifica `fel_companies.mode` para cada empresa y la URL correspondiente en `fel_company_credentials`. Usa `test` con `url_test` y `prod` con `url_prod`. Configura la URL directa del servicio. Una URL faltante, un modo inválido o una redirección detiene la operación.
2. **Origen estable por operación.** Revisa los mapeadores que emiten varios documentos del mismo tipo para una misma orden. Usa un `source_id` estable de cada operación facturable, por ejemplo el ID de la factura parcial. La referencia y la sucursal quedan fuera de la clave. Si una empresa comparte base de datos entre pruebas y producción, usa bases separadas o identidades de origen distintas para evitar reutilizar un DTE de pruebas.
3. **Aplicación y workers.** Actualiza conjuntamente los procesos web, workers y tareas programadas que emiten o anulan. Ejecuta estas llamadas después de confirmar la transacción de la venta. Si usas un certificador propio, al reportar una emisión exitosa debe haber guardado el DTE como `CERTIFIED` con su UUID, y puede devolver `uncertain: true` cuando el resultado sea indeterminado.
4. **Prueba controlada.** Emite y anula un DTE en el ambiente de pruebas de Guatefacturas y verifica los estados y las respuestas. Si el servicio usa otro formato afirmativo de anulación, conserva una copia sanitizada del SOAP, confirma la operación en el portal y ajusta el parser con una prueba de regresión antes de habilitar ese formato.
5. **Credenciales históricas.** Si actualizas desde una versión que guardaba contraseñas en el SOAP, revisa con `php artisan fel:redact-credentials --dry-run` y ejecuta `php artisan fel:redact-credentials` para aplicar la redacción.

### Conciliación operativa

- **Emisión pendiente o incierta.** Localiza el DTE por empresa, origen y referencia. Confirma en Guatefacturas si el intento terminó y si existe el DTE. Si existe, sincroniza en una transacción `fel_uuid`, `fel_serie`, `fel_preimpreso`, `certified_at` y `status = CERTIFIED` a partir de la confirmación, limpia `error_message` y retira `certification_uncertain` y `certification_in_progress` de `metadata`. Si el proveedor confirma que no se emitió y no queda un intento en curso, deja `status = ERROR`, retira ambas marcas y llama a `certifyExisting()` con el mismo registro y los datos corregidos. Conserva evidencia de la conciliación. Aplica la misma revisión a DTE históricos certificados con UUID vacío.
- **Anulación pendiente o incierta.** Si el proveedor confirma la anulación, actualiza en una sola transacción la fila del intento y el documento a `ANNULLED`, y registra la fecha confirmada en `fel_annulments.annulled_at`. Si el proveedor confirma que el DTE sigue vigente y el intento terminó, marca la fila de anulación como `ERROR` y llama a `annul()` para registrar un nuevo intento cuando corresponda.
- **Duplicados históricos.** Identifica grupos por empresa, origen y tipo de DTE. Contrasta los UUID y estados en el portal y conserva los registros para auditoría. Si una operación aparece varias veces en el proveedor, sigue el procedimiento de anulación de la empresa antes de emitir un reemplazo.

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
