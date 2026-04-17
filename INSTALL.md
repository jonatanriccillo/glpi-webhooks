# Webhooks plugin for GLPI 11

Registro genérico de webhooks. Cada webhook es `(nombre, URL, método,
headers, anticipation_days, template de payload, itemtypes, entidad)`.
Features del plugin publican contra este registro.

## Feature actual: avisos de vencimiento

Cron diario `expirationcheck` que escanea **todos** los ítems (sin
depender del flag "alertas" por entidad en GLPI) y, para cada webhook
activo:

1. Compara `days_left` con `anticipation_days` del webhook.
2. Si `0 ≤ days_left ≤ anticipation_days` y no fue avisado para ese
   `(webhook, ítem, fecha de vencimiento)`, envía la notificación.
3. Registra el envío. Garantía: **un solo aviso por ítem/vencimiento**.

Itemtypes soportados:

- `Contract` (contratos)
- `SoftwareLicense` (licencias)
- `Certificate` (certificados)
- `Domain` (dominios)
- `Infocom` (garantías)

**Catch-up**: si el cron no corrió el día exacto, la próxima ejecución
dentro de la ventana envía de todos modos (una vez). Si el ítem se
renueva (cambia `expiration_date`), se envía un nuevo aviso.

## Instalación

1. Copiar `webhooks/` a `<GLPI>/plugins/webhooks/`.
2. **Configuración > Plugins** → Instalar (crea tablas) → Activar.
3. **Administración > Perfiles > [perfil] > Webhooks** → dar permiso.
4. **Configuración > Webhooks > +** → crear webhook.

Si ya tenías una versión anterior instalada, al hacer clic en
**Instalar** se corre una migración que agrega las columnas nuevas sin
borrar datos.

## UI del webhook

Cada webhook tiene 4 pestañas:

### Pestaña principal

- **Connection**: nombre, URL, método HTTP (POST/PUT/PATCH), headers
  custom (uno por línea `K: V`), activo.
- **Scope & trigger**: itemtypes (chips clickeables), entidad +
  sub-entidades, **Anticipation (days)** (default 30).

### Pestaña "Payload"

- Chips clickeables para cada placeholder (click → copia al clipboard).
- Textarea monoespaciada con el template JSON.
- Botones *Save template* y *Restore default template*.
- Panel de **preview** con el template renderizado usando datos de
  ejemplo (contrato ficticio a 15 días del vencimiento).

Placeholders disponibles:

| Placeholder | Valor |
|---|---|
| `{itemtype}` | Nombre técnico (`Contract`, `SoftwareLicense`, ...) |
| `{itemtype_label}` | Etiqueta humana |
| `{id}` | ID del item |
| `{name}` | Nombre del item |
| `{entity}` | Entidad (completename) |
| `{expiration_date}` | YYYY-MM-DD |
| `{days_left}` | Días restantes (entero) |
| `{days_left_label}` | "hoy" / "1 día" / "N días" |
| `{item_url}` | URL del item en GLPI |
| `{emoji}` | Emoji Slack según urgencia |

### Pestaña "Test & status"

- **Send test now**: dispara un evento sintético al endpoint (no genera
  dedupe). El resultado queda reflejado en el panel de abajo.
- **Last real send** / **Last test**: timestamp, HTTP badge (verde 2xx /
  rojo error / negro "ERR") y detalle de respuesta.

### Pestaña "Sent log"

Últimos 50 envíos reales con badges por HTTP y link al ítem.

## Cron

`Configuración > Acciones automáticas > expirationcheck` (frecuencia
diaria). Forzar con *Ejecutar*. En producción conviene cron externo.

## Desinstalación

**Configuración > Plugins > Webhooks > Desinstalar** borra las 3
tablas del plugin y el cron.
