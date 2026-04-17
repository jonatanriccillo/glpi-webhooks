# Changelog

Todas las versiones notables del plugin **Webhooks para GLPI 11**.

El formato sigue [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/)
y el proyecto adopta [Versionado Semántico](https://semver.org/lang/es/).

## [1.0.0]

Primera versión pública. Compatible con **GLPI 11.0.x**.

### Registro genérico de webhooks

- Tabla de webhooks con `(nombre, URL, método HTTP, headers,
  anticipation_days, template de payload, itemtypes, entidad + recursión,
  flag activo)`.
- Método HTTP configurable: `POST` / `PUT` / `PATCH`.
- Headers custom (uno por línea `K: V`).
- Asignación por entidad con herencia a sub-entidades.
- Right dedicado `plugin_webhooks` y pestaña de permisos por perfil.
- Entrada de menú en *Configuración*.

### Feature: avisos de vencimiento

- Cron diario `expirationcheck` que escanea *todos* los ítems
  soportados, sin depender del flag de alertas por entidad de GLPI.
- Itemtypes soportados: `Contract`, `SoftwareLicense`, `Certificate`,
  `Domain`, `Infocom` (garantías).
- Ventana de aviso por webhook vía `anticipation_days` (default 30).
- Garantía de **un solo aviso por `(webhook, ítem, fecha de
  vencimiento)`**: si el ítem se renueva, la siguiente corrida vuelve
  a avisar.
- **Catch-up**: si el cron no corrió el día exacto, la siguiente
  corrida dentro de la ventana envía igual.
- Vencidos (`days_left < 0`) también se avisan una vez.
- Reintentos automáticos: un envío fallido no bloquea próximos
  intentos; un 2xx posterior reemplaza al último intento fallido.

### Pipeline de envío

- Renderizado de template JSON con placeholders: `{itemtype}`,
  `{itemtype_label}`, `{id}`, `{name}`, `{entity}`,
  `{expiration_date}`, `{days_left}`, `{days_left_label}`,
  `{item_url}`, `{emoji}`.
- Valores JSON-escapados al sustituir; validación de que el payload
  resultante sigue siendo JSON válido.
- Transporte HTTP por cURL con timeouts (15s total / 10s conexión) y
  `CURLOPT_SSL_VERIFYPEER=true`.
- Template por defecto Slack Block Kit listo para Incoming Webhook.

### UI por webhook (5 pestañas)

- **Conexión**: form principal con itemtypes como chips clickeables.
- **Payload**: editor monoespaciado + chips de placeholders que copian
  al clipboard + panel de preview en vivo con datos de ejemplo +
  botones *Guardar* / *Restaurar template por defecto*.
- **Por vencer**: lista anotada del alcance del webhook con estados
  `overdue` / `pending` / `sent` / `future` ordenados por urgencia, más
  botón **Enviar pendientes ahora** por webhook.
- **Prueba y estado**: botón **Send test now** (request sintética sin
  dedupe), último test, último envío real, badges por HTTP status.
- **Registro de envíos**: histórico con badges HTTP y link al ítem.

### Planificador

- Card de estado del cron con *frecuencia*, *último run*, *próximo
  run* y badge de estado (Activo / Deshabilitado / Ejecutando).
- Botón **Ejecutar ahora** que corre el scheduler sobre todos los
  webhooks activos y actualiza `lastrun`.

### Instalación / migración

- `install`: crea las 3 tablas vía `sql/empty-1.0.0.sql`, registra el
  cron `expirationcheck` (diario, externo) e inicializa el perfil.
- `migrate` (idempotente): `Migration::addField` agrega columnas
  nuevas a instalaciones previas sin borrar datos.
- `uninstall`: drop de las 3 tablas + limpieza de
  `glpi_displaypreferences` y `glpi_logs`, y unregister del cron.

### Tablas

- `glpi_plugin_webhooks_webhooks`: config por webhook, incluye
  `last_sent_date`, `last_http_status`, `last_error`, `last_test_date`,
  `last_test_status`, `last_test_response`.
- `glpi_plugin_webhooks_sent`: dedupe + histórico con unique key
  `(webhooks_id, itemtype, items_id, expiration_date)` y columnas
  `http_status`, `response_excerpt`, `error`, `sent_date`.
- `glpi_plugin_webhooks_profiles`: rights por perfil.

[1.0.0]: https://github.com/jonatanriccillo/glpi-webhooks/releases/tag/v1.0.0
