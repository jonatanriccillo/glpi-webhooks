# Webhooks plugin for GLPI 11

Registro genérico de webhooks. Cada webhook tiene un **tipo de disparador**
(`trigger_type`) que elige contra qué familia de features reacciona:

- **Vencimientos** (`expiration`): cron que avisa antes de que venzan
  contratos, licencias, certificados, dominios y garantías.
- **Eventos de ticket** (`ticket`): dispara en tiempo real cuando un
  ticket se crea, cambia de estado, se resuelve/cierra, se asigna, recibe
  un seguimiento/tarea/solución, o cambia de prioridad/categoría.

Config común: `(nombre, URL, método, headers, template de payload, entidad)`.
El resto depende del tipo (ver cada sección).

## Feature: avisos de vencimiento

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

## Feature: eventos de ticket

Webhook con disparador **Eventos de ticket**. En vez de un cron, engancha
los hooks de ciclo de vida de GLPI y dispara en tiempo real. En **Tipo**
(fijo en "Ticket") + **Evento** elegís, con un combobox buscable (podés
elegir varios), a qué eventos te suscribís. El catálogo es **el mismo que
usa GLPI para las notificaciones por correo** (Configuración >
Notificaciones > Ticket) — mismas claves, mismo significado:

| Evento | Cuándo dispara |
|---|---|
| Ticket nuevo (`new`) | se crea un ticket |
| Actualización de ticket (`update`) | cambia cualquier campo del ticket |
| Ticket resuelto (`solved`) | pasa a un estado resuelto |
| Solución rechazada (`rejectsolution`) | se rechaza una solución propuesta |
| Cierre del ticket (`closed`) | pasa a un estado cerrado |
| Eliminación del ticket (`delete`) | se envía a la papelera |
| Nuevo/a usuario/grupo solicitante | se agrega un solicitante |
| Nuevo/a usuario/grupo observador | se agrega un observador |
| Nuevo técnico/grupo/proveedor asignado | se agrega un asignado |
| Nueva/actualización/eliminación de tarea | ciclo de vida de una tarea |
| Nuevo/actualización/eliminación de seguimiento | ciclo de vida de un seguimiento |
| Nuevo documento adjunto | se adjunta un archivo |
| Solicitud / respuesta de aprobación | ciclo de una validación |
| Encuesta de satisfacción enviada / respondida | ciclo de la encuesta |
| Motivo de pausa agregado / quitado | ciclo de un "pending reason" |

**No están disponibles** (GLPI los dispara por cron o motor de reglas, no
por una acción puntual — no se pueden replicar con hooks de ciclo de vida):
recordatorio de aprobación, tickets no resueltos, recordatorios automáticos
de SLA/OLA, menciones de usuario, recordatorio automático, cierre
automático por motivo de pausa.

### Filtro (pestaña "Filtros")

El filtrado fino usa el **mismo constructor de criterios de la lista de
Tickets**, embebido directamente en la pestaña — no hay que crear nada
aparte:

1. En la pestaña **Filtros** del webhook, agregá reglas con **"+ regla"**:
   elegís el campo (título, descripción, estado, prioridad, categoría,
   técnico, SLA, cualquier campo de Ticket o de un plugin), el operador, y
   el valor (con el mismo selector que usa GLPI según el tipo de campo).
   También podés agrupar reglas con **"+ grupo"** y combinarlas con
   AND/OR.
2. **Guardar filtro**. Un ticket dispara sólo si entra en el alcance de
   entidad del webhook **y** coincide con estos criterios. Sin criterios =
   todos los del alcance. La pestaña muestra en vivo cuántos tickets
   coinciden ahora mismo.

### Notas de comportamiento

- Los envíos corren **dentro del guardado del ticket**, con timeout corto
  (8s) y atrapando cualquier error: un webhook lento o caído nunca rompe ni
  bloquea la operación del ticket.
- Un mismo cambio puede disparar varios eventos (p. ej. resolver dispara
  `status_changed` **y** `solved`; al crear con técnico asignado dispara
  `created` **y** `assigned`). Suscribite sólo a los que quieras.
- El historial vive en la pestaña **Registro de eventos** (sin dedupe: cada
  evento se registra).

## Instalación

1. Copiar `webhooks/` a `<GLPI>/plugins/webhooks/`.
2. **Configuración > Plugins** → Instalar (crea tablas) → Activar.
3. **Administración > Perfiles > [perfil] > Webhooks** → dar permiso.
4. **Configuración > Webhooks > +** → crear webhook.

Si ya tenías una versión anterior instalada, al hacer clic en
**Instalar** se corre una migración que agrega las columnas nuevas sin
borrar datos.

## UI del webhook

Las pestañas dependen del **tipo de disparador**:

- **Vencimientos**: Principal · Payload · Por vencer · Prueba y estado ·
  Registro de envíos.
- **Eventos de ticket**: Principal · Payload · **Filtros** · Prueba y estado ·
  **Registro de eventos**.

### Pestaña principal

- **Tipo de webhook**: selector *Vencimientos* / *Eventos de ticket*. Cambia
  qué se muestra debajo.
- **Conexión**: nombre, URL, método HTTP (POST/PUT/PATCH), headers custom
  (uno por línea `K: V`), activo, entidad + sub-entidades.
- Según el tipo: itemtypes + **Anticipación (días)** (vencimientos), o
  **Eventos suscritos** (tickets).

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
