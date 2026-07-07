# Changelog

Todas las versiones notables del plugin **Webhooks para GLPI 11**.

## [1.1.0]

Segunda familia de features: **eventos de ticket** en tiempo real, además
de los avisos de vencimiento existentes. Compatible con **GLPI 11.0.x**.

### Tipo de disparador por webhook (`trigger_type`)

- Cada webhook es `expiration` (comportamiento previo, por cron) o `ticket`
  (nuevo, por lifecycle hooks). Las instalaciones existentes quedan como
  `expiration` — sin cambios de comportamiento.
- El form y las pestañas ramifican según el tipo.

### Feature: eventos de ticket

- Hooks de ciclo de vida (`item_add`/`item_update`/`pre_item_delete`/
  `item_purge`) sobre `Ticket` y sus itemtypes satélite (`ITILFollowup`,
  `TicketTask`, `ITILSolution`, `Ticket_User`, `Group_Ticket`,
  `Supplier_Ticket`, `Document_Item`, `TicketValidation`,
  `TicketSatisfaction`, `PendingReason_Item`).
- **Catálogo de 25 eventos que espeja 1:1 el catálogo nativo de
  notificaciones de GLPI** (`NotificationTargetTicket`): `new`, `update`,
  `solved`, `rejectsolution`, `closed`, `delete`, `requester_user`,
  `requester_group`, `observer_user`, `observer_group`, `assign_user`,
  `assign_group`, `assign_supplier`, `add_task`, `update_task`,
  `delete_task`, `add_followup`, `update_followup`, `delete_followup`,
  `add_document`, `validation`, `validation_answer`, `satisfaction`,
  `replysatisfaction`, `pendingreason_add`, `pendingreason_del`. Selección
  con combobox buscable multi-selección (**Tipo: Ticket** + **Evento**),
  igual que el picker de Configuración > Notificaciones. Quedan fuera a
  propósito 7 eventos nativos cron/regla-driven (no una acción puntual):
  `validation_reminder`, `alertnotclosed`, `recall`, `recall_ola`,
  `user_mention`, `auto_reminder`, `pendingreason_close`.
- **Filtro = constructor de criterios embebido, nativo de GLPI** (el mismo
  widget de la lista de Tickets: campo, operador y valor por datatype, con
  grupos AND/OR y "+ regla"/"+ grupo"). Un ticket dispara sólo si entra en
  el alcance de entidad **y** coincide con los criterios guardados —
  cualquier campo de GLPI (estado, prioridad, categoría, técnico, SLA,
  título, contenido, campos de plugins…), sin crear nada aparte.
- Corre dentro del save del ticket: todo en `try/catch` (un webhook fallido
  nunca rompe el ticket) y con timeout corto (8s).
- Registrado sin guard de login → también dispara desde mail collector, API
  o cron.
- Placeholders de ticket: `{event}`, `{event_label}`, `{ticket_id}`,
  `{title}`, `{status_label}`, `{priority_label}`, `{urgency_label}`,
  `{impact_label}`, `{type_label}`, `{category}`, `{requester}`,
  `{assigned}`, `{content}`, `{event_content}`, `{author}`, `{entity}`,
  `{ticket_url}`, `{emoji}`, `{date}`. Template por defecto Slack Block Kit.

### UI (webhook de tipo ticket)

- **Payload**: mismos editor/preview, con los placeholders de ticket.
- **Filtros**: constructor de criterios embebido + contador en vivo de
  cuántos tickets coinciden.
- **Prueba y estado** y **Registro de eventos** (log sin dedupe, últimos 100).

### Tablas / migración

- Nuevas columnas en `glpi_plugin_webhooks_webhooks`: `trigger_type`,
  `ticket_events`, `ticket_criteria`.
- Nueva tabla `glpi_plugin_webhooks_events` (log de eventos, sin unique).
- `migrate` idempotente agrega columnas + crea la tabla nueva; `uninstall`
  también dropea `_events`.

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

[1.1.0]: https://github.com/jonatanriccillo/glpi-webhooks/releases/tag/v1.1.0
[1.0.0]: https://github.com/jonatanriccillo/glpi-webhooks/releases/tag/v1.0.0
