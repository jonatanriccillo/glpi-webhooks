# Webhooks — plugin para GLPI 11

Registro genérico de webhooks para GLPI 11. Cubre escenarios que los
webhooks nativos no atienden: notificación proactiva por ventanas de
tiempo (antes de que algo pase), dedupe por evento, template de
payload editable y pruebas en vivo desde la UI.

> GLPI 11 trae webhooks nativos solo para eventos CRUD de ítems
> (create / update / delete). Este plugin complementa con eventos
> *derivados del estado* del ítem —empezando por vencimientos— y una
> pipeline pensada para sumar más features sin tocar el schema.

Compatible únicamente con **GLPI 11.x**.

---

## Qué hace

Un webhook en este plugin es la tupla:

```
(nombre, URL, método HTTP, headers, anticipation_days,
 template de payload JSON, itemtypes alcanzados, entidad + recursión)
```

Cada feature del plugin publica contra este registro. El dispatcher
renderiza el template con placeholders, hace la request por cURL,
guarda el resultado (HTTP, excerpt de respuesta, error) y dedupe por
evento para garantizar **un solo aviso por ítem/evento**.

### Features incluidas

#### Avisos de vencimiento (`expirationcheck`)

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

### UI

Cada webhook abre con cinco pestañas:

1. **Conexión** (form principal): nombre, URL, método (POST/PUT/PATCH),
   headers custom (uno por línea `K: V`), entidad + sub-entidades,
   itemtypes como chips clickeables, anticipation en días, flag activo.
2. **Payload**: editor monoespaciado del template JSON, chips
   clickeables que copian placeholders al clipboard, botones
   *Guardar* / *Restaurar template por defecto*, panel de **preview**
   en vivo con datos de ejemplo.
3. **Por vencer**: lista anotada de ítems del alcance del webhook con
   estado (`overdue` / `pending` / `sent` / `future`), ordenados por
   urgencia. Botón **Enviar pendientes ahora** por webhook.
4. **Prueba y estado**: botón **Send test now** (request sintética sin
   dedupe), último test, último envío real, badges por status HTTP.
5. **Registro de envíos**: histórico con badges HTTP y link al ítem.

### Template y placeholders

El payload es un template JSON con `{placeholders}` que se reemplazan
con valores JSON-escapados. Debe quedar como JSON válido después de
sustituir.

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

El template por defecto es un mensaje Slack Block Kit listo para pegar
como Incoming Webhook — también funciona contra Discord, Teams
(adaptador mediante) o cualquier endpoint HTTP que acepte JSON.

---

## Instalación

1. Copiar `webhooks/` a `<GLPI>/plugins/webhooks/`.
2. **Configuración → Plugins** → *Instalar* (crea tablas) → *Activar*.
3. **Administración → Perfiles → [perfil] → Webhooks** → dar permiso.
4. **Configuración → Webhooks → +** → crear webhook.

Si venías de una versión previa, *Instalar* corre una migración
incremental (`Migration::addField`) que agrega columnas nuevas sin
borrar datos.

### Cron

Se registra automáticamente:

```
Configuración → Acciones automáticas → expirationcheck  (diario)
```

En producción conviene `CronTask::MODE_EXTERNAL` disparado por cron
del sistema. En lab alcanza con el modo interno.

### Desinstalación

**Configuración → Plugins → Webhooks → Desinstalar** borra las tres
tablas del plugin y desregistra el cron.

---

## Tablas

- `glpi_plugin_webhooks_webhooks` — config por webhook + últimos
  `last_sent_*` / `last_test_*`.
- `glpi_plugin_webhooks_sent` — dedupe + histórico. Unique key sobre
  `(webhooks_id, itemtype, items_id, expiration_date)`. Guarda
  `http_status`, `response_excerpt`, `error`.
- `glpi_plugin_webhooks_profiles` — asignación de rights.

---

## Roadmap

El registro es genérico; la feature actual es solo la primera. Próximas
features apuntadas:

- **Eventos de tickets y cambios**: notificar transiciones de estado,
  asignaciones, SLA en riesgo, escalados.
- **Más itemtypes con fecha**: revisión de KB caducada, reservas
  próximas a vencer, tareas con due date.
- **Filtros adicionales por webhook**: por grupo técnico, por
  proveedor, por tipo de contrato.
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
