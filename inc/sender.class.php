<?php
/**
 * Template rendering + HTTP transport for webhooks.
 */

if (!defined('GLPI_ROOT')) {
    die('Sorry. You can\'t access this file directly');
}

class PluginWebhooksSender
{
    public const DEFAULT_TEMPLATE = <<<'JSON'
{
  "text": "{itemtype_label}: {name} vence el {expiration_date}",
  "blocks": [
    {
      "type": "header",
      "text": { "type": "plain_text", "text": "{emoji} {itemtype_label} por vencer", "emoji": true }
    },
    {
      "type": "section",
      "fields": [
        { "type": "mrkdwn", "text": "*Nombre:*\n{name}" },
        { "type": "mrkdwn", "text": "*Vence:*\n{expiration_date}" },
        { "type": "mrkdwn", "text": "*Dias restantes:*\n{days_left_label}" },
        { "type": "mrkdwn", "text": "*Entidad:*\n{entity}" }
      ]
    },
    {
      "type": "actions",
      "elements": [
        {
          "type": "button",
          "text": { "type": "plain_text", "text": "Ver en GLPI", "emoji": true },
          "url": "{item_url}",
          "style": "primary"
        }
      ]
    },
    {
      "type": "context",
      "elements": [
        { "type": "mrkdwn", "text": "GLPI - {itemtype_label} #{id}" }
      ]
    }
  ]
}
JSON;

    public const PLACEHOLDERS = [
        'itemtype'        => 'Nombre técnico del itemtype (ej: Contract)',
        'itemtype_label'  => 'Etiqueta humana del itemtype (ej: Contrato)',
        'id'              => 'ID numérico del item',
        'name'            => 'Nombre del item',
        'entity'          => 'Entidad (completename)',
        'expiration_date' => 'Fecha de vencimiento (YYYY-MM-DD)',
        'days_left'       => 'Días restantes (entero)',
        'days_left_label' => 'Etiqueta amigable: "hoy" / "1 día" / "N días"',
        'item_url'        => 'URL al item en GLPI',
        'emoji'           => 'Emoji Slack según urgencia (:rotating_light:, :warning:, :hourglass_flowing_sand:)',
    ];

    /**
     * Placeholders available to webhooks with trigger_type = 'ticket'.
     */
    public const TICKET_PLACEHOLDERS = [
        'event'          => 'Clave del evento (new, update, solved, closed, assign_user, add_followup, add_task, validation, satisfaction, etc. — ver catálogo completo en la pestaña "Eventos")',
        'event_label'    => 'Etiqueta humana del evento',
        'ticket_id'      => 'ID del ticket',
        'title'          => 'Título del ticket',
        'status_label'   => 'Estado actual (Nuevo / En curso / Resuelto / …)',
        'priority_label' => 'Prioridad',
        'urgency_label'  => 'Urgencia',
        'impact_label'   => 'Impacto',
        'type_label'     => 'Tipo (Incidencia / Solicitud)',
        'category'       => 'Categoría ITIL',
        'requester'      => 'Solicitante(s)',
        'assigned'       => 'Asignado a (técnicos y grupos)',
        'content'        => 'Descripción del ticket (texto plano, recortado)',
        'event_content'  => 'Contenido del seguimiento / tarea / solución (según el evento)',
        'author'         => 'Usuario que disparó el evento',
        'entity'         => 'Entidad (completename)',
        'ticket_url'     => 'URL al ticket en GLPI',
        'emoji'          => 'Emoji Slack según el evento',
        'date'           => 'Fecha/hora del evento',
    ];

    public const DEFAULT_TICKET_TEMPLATE = <<<'JSON'
{
  "text": "{emoji} {event_label} — #{ticket_id} {title}",
  "blocks": [
    {
      "type": "header",
      "text": { "type": "plain_text", "text": "{emoji} {event_label}", "emoji": true }
    },
    {
      "type": "section",
      "fields": [
        { "type": "mrkdwn", "text": "*Ticket:*\n#{ticket_id} — {title}" },
        { "type": "mrkdwn", "text": "*Estado:*\n{status_label}" },
        { "type": "mrkdwn", "text": "*Prioridad:*\n{priority_label}" },
        { "type": "mrkdwn", "text": "*Categoría:*\n{category}" },
        { "type": "mrkdwn", "text": "*Solicitante:*\n{requester}" },
        { "type": "mrkdwn", "text": "*Asignado:*\n{assigned}" }
      ]
    },
    {
      "type": "section",
      "text": { "type": "mrkdwn", "text": "{content}" }
    },
    {
      "type": "actions",
      "elements": [
        {
          "type": "button",
          "text": { "type": "plain_text", "text": "Abrir en GLPI", "emoji": true },
          "url": "{ticket_url}",
          "style": "primary"
        }
      ]
    },
    {
      "type": "context",
      "elements": [
        { "type": "mrkdwn", "text": "{entity} · por {author} · {date}" }
      ]
    }
  ]
}
JSON;

    /**
     * Replaces {placeholders} with JSON-safe values, then decodes the result
     * as a JSON array. Returns [payload_array|null, error_message|null].
     */
    public static function render(string $template, array $ctx): array
    {
        $rendered = $template;
        foreach ($ctx as $key => $value) {
            $needle   = '{' . $key . '}';
            $encoded  = json_encode(
                (string) $value,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            if ($encoded === false) {
                return [null, "Could not JSON-encode placeholder '{$key}'"];
            }
            $json_safe = substr($encoded, 1, -1);
            $rendered  = str_replace($needle, $json_safe, $rendered);
        }

        $decoded = json_decode($rendered, true);
        if (!is_array($decoded)) {
            $err = json_last_error_msg();
            return [null, "Rendered payload is not valid JSON: {$err}"];
        }
        return [$decoded, null];
    }

    /**
     * Parses a "K: V" (one per line) headers blob into an array suitable for
     * CURLOPT_HTTPHEADER.
     */
    public static function parseHeaders(?string $blob): array
    {
        $out = [];
        if ($blob === null || trim($blob) === '') {
            return $out;
        }
        foreach (preg_split('/\r\n|\r|\n/', $blob) as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, ':') === false) {
                continue;
            }
            $out[] = $line;
        }
        return $out;
    }

    /**
     * POST (or other method) the given payload to $url. Returns array with
     * ok / status / response / error.
     */
    public static function send(string $url, array $payload, string $method = 'POST', array $extra_headers = [], int $timeout = 15): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            return [
                'ok'       => false,
                'status'   => 0,
                'response' => '',
                'error'    => 'Could not JSON-encode payload',
            ];
        }

        $headers = array_merge(
            [
                'Content-Type: application/json',
                'User-Agent: GLPI-Webhooks/' . PLUGIN_WEBHOOKS_VERSION,
            ],
            $extra_headers
        );

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min($timeout, 10),
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return [
                'ok'       => false,
                'status'   => $status,
                'response' => '',
                'error'    => $err !== '' ? $err : 'cURL failed without error message',
            ];
        }

        return [
            'ok'       => $status >= 200 && $status < 300,
            'status'   => $status,
            'response' => (string) $response,
            'error'    => null,
        ];
    }

    /**
     * Updates the "last_*" status columns of a webhook row after a send.
     */
    public static function recordResult(int $webhooks_id, array $result, bool $is_test): void
    {
        global $DB;

        $now      = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $status   = isset($result['status']) ? (int) $result['status'] : 0;
        $response = (string) ($result['response'] ?? '');
        $error    = $result['error'] !== null
            ? (string) $result['error']
            : ($result['ok'] ? null : 'HTTP ' . $status);

        if ($is_test) {
            $DB->update(
                'glpi_plugin_webhooks_webhooks',
                [
                    'last_test_date'     => $now,
                    'last_test_status'   => $status,
                    'last_test_response' => self::excerpt($response !== '' ? $response : ($error ?? '')),
                ],
                ['id' => $webhooks_id]
            );
            return;
        }

        $DB->update(
            'glpi_plugin_webhooks_webhooks',
            [
                'last_sent_date'   => $now,
                'last_http_status' => $status,
                // On success, keep the response body (like the test path already
                // does) instead of clearing the detail column — otherwise a
                // healthy 2xx send shows an empty "Detalle" in the Estado tab.
                'last_error'       => $result['ok']
                    ? ($response !== '' ? self::excerpt($response) : null)
                    : self::excerpt($error ?? ''),
            ],
            ['id' => $webhooks_id]
        );
    }

    public static function excerpt(string $s, int $max = 500): string
    {
        if (strlen($s) <= $max) {
            return $s;
        }
        return substr($s, 0, $max) . '…';
    }

    /**
     * Synthetic context for the "Send test" button. Uses real entity name so
     * the admin sees what a real event will look like.
     */
    public static function buildTestContext(PluginWebhooksWebhook $webhook): array
    {
        global $CFG_GLPI;

        $entity_name = '';
        $entity = new Entity();
        if ($entity->getFromDB((int) $webhook->fields['entities_id'])) {
            $entity_name = (string) $entity->fields['completename'];
        }

        return [
            'itemtype'        => 'Contract',
            'itemtype_label'  => PluginWebhooksWebhook::itemtypeLabel('Contract'),
            'id'              => 0,
            'name'            => 'TEST — ' . $webhook->fields['name'],
            'entity'          => $entity_name,
            'expiration_date' => date('Y-m-d', strtotime('+15 days')),
            'days_left'       => 15,
            'days_left_label' => '15 días',
            'item_url'        => rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/'),
            'emoji'           => ':hourglass_flowing_sand:',
        ];
    }

    /**
     * Synthetic context for the "Send test" button on a ticket-type webhook.
     * Uses static sample values (no live GLPI lookups) so it is safe to render
     * anywhere, including the payload preview.
     */
    public static function buildTicketTestContext(PluginWebhooksWebhook $webhook): array
    {
        global $CFG_GLPI;

        $entity_name = '';
        $entity = new Entity();
        if ($entity->getFromDB((int) $webhook->fields['entities_id'])) {
            $entity_name = (string) $entity->fields['completename'];
        }

        $author = (string) ($_SESSION['glpifriendlyname'] ?? $_SESSION['glpiname'] ?? 'GLPI');

        return [
            'event'          => 'new',
            'event_label'    => 'Ticket nuevo',
            'ticket_id'      => 0,
            'title'          => 'TEST — ' . $webhook->fields['name'],
            'status_label'   => 'Nuevo',
            'priority_label' => 'Media',
            'urgency_label'  => 'Media',
            'impact_label'   => 'Medio',
            'type_label'     => 'Incidencia',
            'category'       => 'Soporte',
            'requester'      => 'Juan Pérez',
            'assigned'       => '—',
            'content'        => 'Ticket de prueba generado desde la configuración del webhook.',
            'event_content'  => '',
            'author'         => $author,
            'entity'         => $entity_name,
            'ticket_url'     => rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/') . '/front/ticket.form.php?id=0',
            'emoji'          => ':new:',
            'date'           => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
            // Silent aliases so a template borrowed from the expiration set still renders.
            'id'             => 0,
            'name'           => 'TEST — ' . $webhook->fields['name'],
            'item_url'       => rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/'),
        ];
    }

    public static function emojiFor(int $days_left): string
    {
        if ($days_left <= 3) {
            return ':rotating_light:';
        }
        if ($days_left <= 7) {
            return ':warning:';
        }
        return ':hourglass_flowing_sand:';
    }

    public static function daysLeftLabel(int $days_left): string
    {
        if ($days_left <= 0) {
            return 'hoy';
        }
        if ($days_left === 1) {
            return '1 día';
        }
        return $days_left . ' días';
    }
}
