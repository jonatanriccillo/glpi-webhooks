# Webhooks — plugin para GLPI 11

Registro genérico de webhooks para GLPI 11. Cada webhook elige un
**tipo de disparador** (`trigger_type`) y publica contra el mismo
registro: nombre, URL, método HTTP, headers, template de payload JSON
y entidad. Dos familias de features hoy:

- **Vencimientos** (`expiration`): cron que avisa antes de que venzan
  contratos, licencias, certificados, dominios o garantías.
- **Eventos de ticket** (`ticket`): dispara en tiempo real sobre el
  ciclo de vida de un Ticket (creación, cambios de estado, asignación,
  seguimientos, tareas, validaciones, encuesta de satisfacción, etc.),
  con filtrado por un constructor de criterios nativo de GLPI embebido
  en la propia UI.

> GLPI 11 trae webhooks nativos solo para eventos CRUD de ítems
> (create / update / delete). Este plugin complementa con eventos
> *derivados del estado o del ciclo de vida* del ítem, y una pipeline
> pensada para sumar más features sin tocar el schema.

Compatible únicamente con **GLPI 11.x**.

---

## Qué hace

El dispatcher (común a ambas familias) renderiza el template con
placeholders, hace la request por cURL, y guarda el resultado (HTTP,
excerpt de respuesta, error).

### Feature: avisos de vencimiento (`expirationcheck`)

Cron diario que escanea *todos* los ítems soportados —sin depender del
flag de "alertas" por entidad que trae GLPI— y, para cada webhook
activo, envía una notificación cuando un ítem entra en la ventana de
`anticipation_days` configurada.

Itemtypes soportados:

| Itemtype | Campo de vencimiento |
|---|---|
| `Contract` | `begin_date` + `duration` (meses) |
| `SoftwareLicense` | `expire` |
| `Certificate` | `date_expiration` |
| `Domain` | `date_expiration` |
| `Infocom` (garantías) | `warranty_date` + `warranty_duration` |

Garantías del pipeline:

- **Un solo aviso por `(webhook, ítem, fecha de vencimiento)`**: si el
  ítem se renueva (cambia la fecha), el siguiente ciclo envía de nuevo.
- **Catch-up**: si el cron no corrió el día exacto, la próxima corrida
  dentro de la ventana envía igual (una vez).
- **Reintentos automáticos**: un envío fallido no bloquea reintentos;
  un 2xx posterior reemplaza al último intento fallido en el log.
- **Vencidos también avisan una vez**: `days_left < 0` dispara si
  nunca se notificó para ese vencimiento.

### Feature: eventos de ticket (tiempo real)

En vez de un cron, engancha los hooks de ciclo de vida de GLPI y
dispara al instante. El catálogo de eventos **espeja el catálogo
nativo de notificaciones de GLPI** (Configuración → Notificaciones →
Ticket) — mismas claves, mismo significado, para no inventar una
taxonomía propia:

| Evento | Cuándo dispara |
|---|---|
| `new` | se crea un ticket |
| `update` | cambia cualquier campo del ticket |
| `solved` | pasa a un estado resuelto |
| `rejectsolution` | se rechaza una solución propuesta |
| `closed` | pasa a un estado cerrado |
| `delete` | se envía a la papelera |
| `requester_user` / `requester_group` | nuevo solicitante |
| `observer_user` / `observer_group` | nuevo observador |
| `assign_user` / `assign_group` / `assign_supplier` | nuevo asignado |
| `add_task` / `update_task` / `delete_task` | ciclo de vida de una tarea |
| `add_followup` / `update_followup` / `delete_followup` | ciclo de vida de un seguimiento |
| `add_document` | se adjunta un documento |
| `validation` / `validation_answer` | ciclo de una validación/aprobación |
| `satisfaction` / `replysatisfaction` | ciclo de la encuesta de satisfacción |
| `pendingreason_add` / `pendingreason_del` | ciclo de un "motivo de pausa" |

No están disponibles 7 eventos nativos que GLPI dispara por cron o
motor de reglas, no por una acción puntual (`validation_reminder`,
`alertnotclosed`, `recall`, `recall_ola`, `user_mention`,
`auto_reminder`, `pendingreason_close`) — reproducirlos requeriría
replicar ese cron/motor de reglas, fuera de alcance de un feature
basado en hooks de ciclo de vida en tiempo real.

**Filtro = constructor de criterios embebido, nativo de GLPI.** La
pestaña "Filtros" del webhook renderiza el mismo widget multi-criterio
que la lista de Tickets (campo, operador y valor por tipo de dato,
grupos AND/OR, "+ regla" / "+ grupo") — no hay que crear una búsqueda
guardada aparte. Un ticket dispara sólo si entra en el alcance de
entidad del webhook **y** coincide con los criterios guardados.

Notas de comportamiento:

- Corre **dentro del guardado del ticket**: cualquier error queda
  atrapado (un webhook fallido nunca rompe el ticket) y el envío usa un
  timeout corto (8s) para no bloquear la operación.
- Registrado sin depender de sesión de usuario → también dispara desde
  el mail collector, la API o un cron.
- Un mismo cambio puede disparar varios eventos a la vez (resolver
  dispara `update` **y** `solved`; crear con técnico asignado dispara
  `new` **y** `assign_user`).

### UI

Las pestañas del webhook dependen del tipo de disparador:

**Vencimientos**: Webhook (form) · Payload · Por vencer · Prueba y
estado · Registro de envíos.

**Eventos de ticket**: Webhook (form) · Payload · Filtros · Prueba y
estado · Registro de eventos.

- **Webhook** (form principal): tipo de disparador, nombre, URL,
  método (POST/PUT/PATCH), headers custom, entidad + sub-entidades, y
  según el tipo: itemtypes + anticipación (vencimientos) o selector de
  eventos en combobox buscable (tickets).
- **Payload**: editor monoespaciado del template JSON, chips
  clickeables que copian placeholders al clipboard, botones *Guardar* /
  *Restaurar template por defecto*, panel de **preview** en vivo con
  datos de ejemplo.
- **Por vencer** (sólo vencimientos): lista anotada de ítems del
  alcance del webhook con estado (`overdue` / `pending` / `sent` /
  `future`), ordenados por urgencia. Botón **Enviar pendientes ahora**.
- **Filtros** (sólo tickets): constructor de criterios embebido +
  contador en vivo de cuántos tickets coinciden ahora mismo.
- **Prueba y estado**: botón **Enviar prueba ahora** (request sintética
  sin dedupe/log), último test, último envío real con su detalle
  (response body), y para tickets el ticket+evento exacto que disparó
  el último envío real.
- **Registro de envíos / Registro de eventos**: histórico con badges
  HTTP y link al ítem.

### Template y placeholders

El payload es un template JSON con `{placeholders}` que se reemplazan
con valores JSON-escapados. Debe quedar como JSON válido después de
sustituir. Cada familia tiene su propio set:

**Vencimientos:**

| Placeholder | Valor |
|---|---|
| `{itemtype}` | Nombre técnico (`Contract`, `SoftwareLicense`, …) |
| `{itemtype_label}` | Etiqueta humana |
| `{id}` | ID del ítem |
| `{name}` | Nombre del ítem |
| `{entity}` | Entidad (completename) |
| `{expiration_date}` | YYYY-MM-DD |
| `{days_left}` | Días restantes (entero) |
| `{days_left_label}` | `"hoy"` / `"1 día"` / `"N días"` |
| `{item_url}` | URL del ítem en GLPI |
| `{emoji}` | Emoji de urgencia: `:rotating_light:` ≤3d, `:warning:` ≤7d, `:hourglass_flowing_sand:` resto |

**Eventos de ticket:**

| Placeholder | Valor |
|---|---|
| `{event}` / `{event_label}` | Clave / etiqueta del evento |
| `{ticket_id}` / `{title}` | ID y título del ticket |
| `{status_label}` / `{priority_label}` / `{urgency_label}` / `{impact_label}` / `{type_label}` | Campos ITIL del ticket |
| `{category}` | Categoría ITIL |
| `{requester}` / `{assigned}` | Solicitante(s) / asignado(s) |
| `{content}` | Descripción del ticket (texto plano, recortado) |
| `{event_content}` | Contenido del seguimiento/tarea/solución/validación según el evento |
| `{author}` | Usuario que disparó el evento |
| `{entity}` / `{ticket_url}` / `{emoji}` / `{date}` | Entidad, URL, emoji según evento, fecha/hora |

Ambos templates por defecto son Slack Block Kit listos para pegar como
Incoming Webhook — también funcionan contra Discord, Teams (adaptador
mediante), n8n, o cualquier endpoint HTTP que acepte JSON.

---

## Instalación

1. Copiar `webhooks/` a `<GLPI>/plugins/webhooks/`.
2. **Configuración → Plugins** → *Instalar* (crea tablas) → *Activar*.
3. **Administración → Perfiles → [perfil] → Webhooks** → dar permiso
   (Super-Admin lo recibe automáticamente al instalar).
4. **Configuración → Webhooks → +** → crear webhook, elegir tipo de
   disparador.

Si venías de una versión previa, *Instalar* corre una migración
incremental (`Migration::addField`) que agrega columnas y tablas
nuevas sin borrar datos existentes.

### Cron

Se registra automáticamente (usado sólo por la familia Vencimientos):

```
Configuración → Acciones automáticas → expirationcheck  (diario)
```

En producción conviene `CronTask::MODE_EXTERNAL` disparado por cron
del sistema. En lab alcanza con el modo interno. La familia de
Eventos de ticket no usa cron: dispara por hooks de ciclo de vida.

### Desinstalación

**Configuración → Plugins → Webhooks → Desinstalar** borra las cuatro
tablas del plugin y desregistra el cron.

---

## Tablas

- `glpi_plugin_webhooks_webhooks` — config por webhook. Incluye
  `trigger_type`, y según el tipo: `itemtypes` + `anticipation_days`
  (vencimientos) o `ticket_events` + `ticket_criteria` (tickets).
  También los últimos `last_sent_*` / `last_test_*` (compartidos).
- `glpi_plugin_webhooks_sent` — dedupe + histórico de vencimientos.
  Unique key sobre `(webhooks_id, itemtype, items_id,
  expiration_date)`. Guarda `http_status`, `response_excerpt`, `error`.
- `glpi_plugin_webhooks_events` — log de eventos de ticket, sin
  dedupe (cada evento se registra siempre): `webhooks_id, itemtype,
  items_id, event, http_status, response_excerpt, error, event_date`.
- `glpi_plugin_webhooks_profiles` — asignación de rights.

---

## Roadmap

El registro es genérico; hoy cubre dos familias de features. Próximas
apuntadas:

- **Change / Problem**: extender la familia de eventos ITIL más allá
  de Ticket, reusando el mismo mecanismo de hooks + filtro embebido.
- **Cola + cron drain** para eventos de ticket: hoy el envío es
  síncrono (timeout corto) dentro del guardado del ticket; para
  endpoints lentos, mover a una cola procesada por cron.
- **Más itemtypes con fecha** (vencimientos): revisión de KB caducada,
  reservas próximas a vencer, tareas con due date.
- **Firma HMAC opcional** del payload (header `X-Signature`) para
  endpoints que la requieran.
- **Reenvío manual** desde el histórico de un envío específico.
- **Exportar/importar webhooks** (JSON) para mover configuraciones
  entre entornos.
- **Localización completa** (`locales/`) al inglés.

Sugerencias y casos de uso nuevos: issues bienvenidos.

---

## Licencia

GPLv3. Ver encabezado en `setup.php`.

## Autor

Jonatan Riccillo
