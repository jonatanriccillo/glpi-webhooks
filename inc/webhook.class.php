<?php
/**
 * Webhook target.
 *
 * Tabs:
 *   - Main form (default): connection settings (URL, method, headers, scope).
 *   - Payload: JSON template editor, clickable placeholder chips, live preview.
 *   - Test & status: "Send test now" button, last test + last real send status.
 *   - Sent log: history of real sends with HTTP status badges.
 */

if (!defined('GLPI_ROOT')) {
    die('Sorry. You can\'t access this file directly');
}

class PluginWebhooksWebhook extends CommonDBTM
{
    public static $rightname = 'plugin_webhooks';

    public $dohistory = true;

    public const SUPPORTED_ITEMTYPES = [
        'Contract'        => ['label_key' => 'Contract',        'icon' => 'ti ti-file-description'],
        'SoftwareLicense' => ['label_key' => 'Software license','icon' => 'ti ti-certificate'],
        'Certificate'     => ['label_key' => 'Certificate',     'icon' => 'ti ti-shield-check'],
        'Domain'          => ['label_key' => 'Domain',          'icon' => 'ti ti-world'],
        'Infocom'         => ['label_key' => 'Warranty',        'icon' => 'ti ti-device-desktop'],
    ];

    public const HTTP_METHODS = ['POST', 'PUT', 'PATCH'];

    public static function getTypeName($nb = 0): string
    {
        return _n('Webhook', 'Webhooks', $nb, 'webhooks');
    }

    public static function getIcon(): string
    {
        return 'ti ti-webhook';
    }

    public function isEntityAssign(): bool
    {
        return true;
    }

    public function maybeRecursive(): bool
    {
        return true;
    }

    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs);
        $this->addStandardTab(self::class, $tabs, $options);
        $this->addStandardTab('Log', $tabs, $options);
        return $tabs;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof self && $item->fields['id'] > 0) {
            return [
                1 => __('Payload', 'webhooks'),
                2 => __('Por vencer', 'webhooks'),
                3 => __('Prueba y estado', 'webhooks'),
                4 => __('Registro de envíos', 'webhooks'),
            ];
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof self) {
            switch ((int) $tabnum) {
                case 1: $item->showPayloadTab(); break;
                case 2: $item->showUpcomingTab(); break;
                case 3: $item->showTestTab(); break;
                case 4: $item->showLogTab(); break;
            }
        }
        return true;
    }

    public function rawSearchOptions(): array
    {
        $t = $this->getTable();
        return [
            ['id' => 'common', 'name' => self::getTypeName(2)],
            ['id' => 1, 'table' => $t, 'field' => 'name', 'name' => __('Name'), 'datatype' => 'itemlink', 'massiveaction' => false],
            ['id' => 2, 'table' => $t, 'field' => 'is_active', 'name' => __('Active'), 'datatype' => 'bool'],
            ['id' => 3, 'table' => $t, 'field' => 'url', 'name' => __('URL'), 'datatype' => 'text'],
            ['id' => 4, 'table' => $t, 'field' => 'http_method', 'name' => __('Método HTTP', 'webhooks'), 'datatype' => 'string'],
            ['id' => 5, 'table' => $t, 'field' => 'itemtypes', 'name' => __('Tipos de ítem', 'webhooks'), 'datatype' => 'text'],
            ['id' => 6, 'table' => $t, 'field' => 'anticipation_days', 'name' => __('Anticipación (días)', 'webhooks'), 'datatype' => 'integer'],
            ['id' => 7, 'table' => $t, 'field' => 'last_sent_date', 'name' => __('Último envío', 'webhooks'), 'datatype' => 'datetime', 'massiveaction' => false],
            ['id' => 8, 'table' => $t, 'field' => 'last_http_status', 'name' => __('Último HTTP', 'webhooks'), 'datatype' => 'integer', 'massiveaction' => false],
            ['id' => 9, 'table' => $t, 'field' => 'comment', 'name' => __('Comments'), 'datatype' => 'text'],
            ['id' => 80, 'table' => 'glpi_entities', 'field' => 'completename', 'name' => __('Entity'), 'datatype' => 'dropdown', 'massiveaction' => false],
            ['id' => 86, 'table' => $t, 'field' => 'is_recursive', 'name' => __('Child entities'), 'datatype' => 'bool'],
            ['id' => 121, 'table' => $t, 'field' => 'date_creation', 'name' => __('Creation date'), 'datatype' => 'datetime', 'massiveaction' => false],
            ['id' => 19, 'table' => $t, 'field' => 'date_mod', 'name' => __('Last update'), 'datatype' => 'datetime', 'massiveaction' => false],
        ];
    }

    public function prepareInputForAdd($input)
    {
        if (!isset($input['payload_template']) || trim((string) $input['payload_template']) === '') {
            $input['payload_template'] = PluginWebhooksSender::DEFAULT_TEMPLATE;
        }
        if (!isset($input['anticipation_days']) || (int) $input['anticipation_days'] <= 0) {
            $input['anticipation_days'] = 30;
        }
        return $this->sanitizeInput($input);
    }

    public function prepareInputForUpdate($input)
    {
        return $this->sanitizeInput($input);
    }

    private function sanitizeInput(array $input)
    {
        if (isset($input['itemtypes']) && is_array($input['itemtypes'])) {
            $keep = [];
            foreach ($input['itemtypes'] as $itemtype) {
                if (array_key_exists($itemtype, self::SUPPORTED_ITEMTYPES)) {
                    $keep[] = $itemtype;
                }
            }
            $input['itemtypes'] = json_encode(array_values(array_unique($keep)));
        }

        if (isset($input['url'])) {
            $input['url'] = trim((string) $input['url']);
            if ($input['url'] !== '' && !preg_match('~^https?://~i', $input['url'])) {
                Session::addMessageAfterRedirect(__('La URL debe empezar con http:// o https://', 'webhooks'), false, ERROR);
                return false;
            }
        }

        if (isset($input['http_method'])) {
            $input['http_method'] = strtoupper(trim((string) $input['http_method']));
            if (!in_array($input['http_method'], self::HTTP_METHODS, true)) {
                $input['http_method'] = 'POST';
            }
        }

        if (isset($input['anticipation_days'])) {
            $v = (int) $input['anticipation_days'];
            $input['anticipation_days'] = ($v > 0 && $v <= 3650) ? $v : 30;
        }

        if (isset($input['payload_template']) && $input['payload_template'] !== '') {
            $decoded = json_decode((string) $input['payload_template'], true);
            if (!is_array($decoded)) {
                Session::addMessageAfterRedirect(
                    __('El template del payload no es JSON válido: ', 'webhooks') . json_last_error_msg(),
                    false,
                    ERROR
                );
                return false;
            }
        }

        return $input;
    }

    public function getSelectedItemtypes(): array
    {
        if (empty($this->fields['itemtypes'])) {
            return [];
        }
        $decoded = json_decode((string) $this->fields['itemtypes'], true);
        return is_array($decoded) ? $decoded : [];
    }

    // -----------------------------------------------------------------------
    // Default form: connection settings
    // -----------------------------------------------------------------------

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);
        $this->showFormHeader($options);

        $selected = $this->getSelectedItemtypes();

        echo "<tr class='tab_bg_2'><th colspan='4'><i class='ti ti-plug'></i> "
            . __('Conexión', 'webhooks') . '</th></tr>';

        echo "<tr class='tab_bg_1'>";
        echo '<td><i class="ti ti-tag"></i> ' . __('Name') . ' <span class="red">*</span></td>';
        echo '<td>' . Html::input('name', ['value' => $this->fields['name'] ?? '', 'size' => 40]) . '</td>';
        echo '<td><i class="ti ti-bolt"></i> ' . __('Active') . '</td>';
        echo '<td>';
        Dropdown::showYesNo('is_active', $this->fields['is_active'] ?? 1);
        echo '</td></tr>';

        echo "<tr class='tab_bg_1'>";
        echo '<td><i class="ti ti-link"></i> ' . __('URL') . ' <span class="red">*</span></td>';
        echo "<td colspan='3'>";
        echo Html::input('url', [
            'value' => $this->fields['url'] ?? '',
            'size'  => 90,
            'type'  => 'url',
            'placeholder' => 'https://hooks.slack.com/services/... (o cualquier endpoint JSON)',
        ]);
        echo '<br><span class="text-muted"><small>'
            . __('Endpoint HTTP(S) que va a recibir el payload JSON.', 'webhooks')
            . '</small></span></td></tr>';

        echo "<tr class='tab_bg_1'>";
        echo '<td><i class="ti ti-arrow-right"></i> ' . __('Método HTTP', 'webhooks') . '</td>';
        echo '<td>';
        Dropdown::showFromArray(
            'http_method',
            array_combine(self::HTTP_METHODS, self::HTTP_METHODS),
            ['value' => $this->fields['http_method'] ?? 'POST']
        );
        echo '</td>';
        echo '<td><i class="ti ti-subtask"></i> ' . __('Child entities') . '</td>';
        echo '<td>';
        Dropdown::showYesNo('is_recursive', $this->fields['is_recursive'] ?? 1);
        echo '</td></tr>';

        echo "<tr class='tab_bg_1'>";
        echo '<td><i class="ti ti-key"></i> ' . __('Headers custom', 'webhooks') . '</td>';
        echo "<td colspan='3'>";
        echo "<textarea name='headers' rows='3' style='width:100%; font-family:monospace; font-size:12px;' "
            . "placeholder='Authorization: Bearer xxx&#10;X-Custom: value'>"
            . htmlspecialchars((string) ($this->fields['headers'] ?? ''))
            . '</textarea>';
        echo '<div class="text-muted"><small>'
            . __('Un "Clave: Valor" por línea. Dejar vacío para usar los valores por defecto.', 'webhooks')
            . '</small></div></td></tr>';

        echo "<tr class='tab_bg_2'><th colspan='4'><i class='ti ti-target'></i> "
            . __('Alcance y disparador', 'webhooks') . '</th></tr>';

        echo "<tr class='tab_bg_1'>";
        echo '<td><i class="ti ti-list-check"></i> ' . __('Tipos de ítem', 'webhooks') . ' <span class="red">*</span></td>';
        echo "<td colspan='3'>";
        echo "<div style='display:flex; flex-wrap:wrap; gap:10px;'>";
        foreach (self::SUPPORTED_ITEMTYPES as $itemtype => $meta) {
            $checked = in_array($itemtype, $selected, true) ? 'checked' : '';
            $label   = self::itemtypeLabel($itemtype);
            echo "<label style='display:inline-flex; align-items:center; gap:6px; padding:6px 12px; "
                . "border:1px solid #dee2e6; border-radius:20px; cursor:pointer; background:#f8f9fa;'>"
                . "<input type='checkbox' name='itemtypes[]' value='" . htmlspecialchars($itemtype) . "' {$checked}> "
                . "<i class='" . $meta['icon'] . "'></i> "
                . htmlspecialchars($label)
                . '</label>';
        }
        echo '</div></td></tr>';

        echo "<tr class='tab_bg_1'>";
        echo '<td><i class="ti ti-calendar-clock"></i> ' . __('Anticipación (días)', 'webhooks') . '</td>';
        echo "<td colspan='3'>";
        echo "<input type='number' name='anticipation_days' value='"
            . (int) ($this->fields['anticipation_days'] ?? 30)
            . "' min='1' max='3650' step='1' style='width:120px;'>";
        echo '<span class="text-muted">&nbsp; ' . __('días antes del vencimiento para empezar a alertar', 'webhooks') . '</span>';
        echo '</td></tr>';

        echo "<tr class='tab_bg_2'><th colspan='4'><i class='ti ti-message-2'></i> "
            . __('Notas', 'webhooks') . '</th></tr>';

        echo "<tr class='tab_bg_1'>";
        echo '<td>' . __('Comments') . '</td>';
        echo "<td colspan='3'>";
        echo "<textarea name='comment' rows='2' style='width:100%;'>"
            . htmlspecialchars((string) ($this->fields['comment'] ?? ''))
            . '</textarea>';
        echo '</td></tr>';

        $this->showFormButtons($options);
        return true;
    }

    // -----------------------------------------------------------------------
    // Tab: Payload
    // -----------------------------------------------------------------------

    public function showPayloadTab(): void
    {
        $id       = (int) $this->fields['id'];
        $template = (string) ($this->fields['payload_template'] ?? PluginWebhooksSender::DEFAULT_TEMPLATE);
        $action   = Plugin::getWebDir('webhooks') . '/front/webhook.form.php';

        $sample_ctx = PluginWebhooksSender::buildTestContext($this);
        [$preview, $preview_err] = PluginWebhooksSender::render($template, $sample_ctx);
        $preview_json = $preview !== null
            ? json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : '';

        echo '<div class="card mb-3">';
        echo '<div class="card-header"><h3 class="card-title"><i class="ti ti-braces"></i> '
            . __('Template de payload (JSON)', 'webhooks') . '</h3>';
        echo '<div class="card-subtitle text-muted">'
            . __('Usá {placeholders} para inyectar datos reales del ítem. Debe ser JSON válido después de la sustitución.', 'webhooks')
            . '</div></div>';
        echo '<div class="card-body">';

        echo "<form method='post' action='" . htmlspecialchars($action) . "'>";
        echo Html::hidden('id', ['value' => $id]);
        echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);

        echo '<div class="mb-3"><strong>' . __('Placeholders disponibles (click para copiar)', 'webhooks') . '</strong></div>';
        echo '<div style="display:flex; flex-wrap:wrap; gap:8px; margin-bottom:14px;">';
        foreach (PluginWebhooksSender::PLACEHOLDERS as $key => $desc) {
            $chip = '{' . $key . '}';
            echo "<span title='" . htmlspecialchars($desc) . "' "
                . "onclick='navigator.clipboard.writeText(" . json_encode($chip) . "); this.style.background=\"#c8e6c9\"; setTimeout(()=>this.style.background=\"#e7f1ff\",600);' "
                . "style='cursor:pointer; padding:6px 12px; background:#e7f1ff; border:1px solid #9ec5fe; border-radius:16px; font-family:monospace; font-size:12px; user-select:all;'>"
                . htmlspecialchars($chip)
                . '</span>';
        }
        echo '</div>';

        echo '<textarea name="payload_template" rows="22" style="width:100%; font-family:monospace; font-size:12px; line-height:1.45;" spellcheck="false">'
            . htmlspecialchars($template) . '</textarea>';

        echo '<div class="mt-3 d-flex gap-2">';
        echo Html::submit('<i class="ti ti-device-floppy"></i> ' . __('Guardar template', 'webhooks'), [
            'name' => 'save_template', 'class' => 'btn btn-primary',
        ]);
        echo Html::submit('<i class="ti ti-restore"></i> ' . __('Restaurar template por defecto', 'webhooks'), [
            'name'    => 'reset_template',
            'class'   => 'btn btn-outline-secondary',
            'confirm' => __('Esto sobrescribe el template actual. ¿Continuar?', 'webhooks'),
        ]);
        echo '</div>';

        Html::closeForm();
        echo '</div></div>';

        echo '<div class="card">';
        echo '<div class="card-header"><h3 class="card-title"><i class="ti ti-eye"></i> '
            . __('Vista previa (con datos de ejemplo)', 'webhooks') . '</h3></div>';
        echo '<div class="card-body">';
        if ($preview_err !== null) {
            echo '<div class="alert alert-danger"><i class="ti ti-alert-triangle"></i> '
                . htmlspecialchars($preview_err) . '</div>';
        } else {
            echo '<pre style="background:#0f172a; color:#e2e8f0; padding:14px; border-radius:6px; max-height:400px; overflow:auto; font-size:12px;">'
                . htmlspecialchars($preview_json) . '</pre>';
        }
        echo '</div></div>';
    }

    // -----------------------------------------------------------------------
    // Scheduler status card (shown above the Upcoming list)
    // -----------------------------------------------------------------------

    private function showSchedulerCard(string $action): void
    {
        global $CFG_GLPI;

        $task = new CronTask();
        $found = $task->getFromDBbyName('PluginWebhooksCrontask', 'expirationcheck');

        echo '<div class="card mb-3">';
        echo '<div class="card-header">';
        echo '<h3 class="card-title"><i class="ti ti-clock-play"></i> '
            . __('Planificador', 'webhooks') . '</h3>';
        echo '<div class="card-subtitle text-muted">'
            . __('Indica si el cron diario está activo y cuándo se va a ejecutar la próxima vez.', 'webhooks')
            . '</div>';
        echo '</div>';
        echo '<div class="card-body">';

        if (!$found) {
            echo '<div class="alert alert-danger"><i class="ti ti-alert-triangle"></i> '
                . __('La tarea cron no está registrada. Reinstalá el plugin.', 'webhooks')
                . '</div>';
            echo '</div></div>';
            return;
        }

        $state     = (int) $task->fields['state'];
        $lastrun   = $task->fields['lastrun'] ?? null;
        $frequency = (int) ($task->fields['frequency'] ?? 0);
        $next_ts   = ($lastrun && $frequency > 0) ? strtotime((string) $lastrun) + $frequency : null;

        if ($state === CronTask::STATE_DISABLE) {
            $state_badge = '<span class="badge bg-danger"><i class="ti ti-player-pause"></i> '
                . __('Deshabilitado', 'webhooks') . '</span>';
        } elseif ($state === CronTask::STATE_RUNNING) {
            $state_badge = '<span class="badge bg-warning"><i class="ti ti-player-play"></i> '
                . __('Ejecutando', 'webhooks') . '</span>';
        } else {
            $state_badge = '<span class="badge bg-success"><i class="ti ti-check"></i> '
                . __('Activo', 'webhooks') . '</span>';
        }

        echo '<div class="row g-3 mb-3">';
        echo '<div class="col-md-3"><small class="text-muted d-block">' . __('Estado', 'webhooks') . '</small>' . $state_badge . '</div>';
        echo '<div class="col-md-3"><small class="text-muted d-block">' . __('Frecuencia', 'webhooks') . '</small><strong>' . self::humanizeSeconds($frequency) . '</strong></div>';
        echo '<div class="col-md-3"><small class="text-muted d-block">' . __('Última corrida', 'webhooks') . '</small><strong>'
            . ($lastrun ? Html::convDateTime((string) $lastrun) : '<span class="text-muted">' . __('nunca', 'webhooks') . '</span>')
            . '</strong></div>';
        echo '<div class="col-md-3"><small class="text-muted d-block">' . __('Próxima corrida (estimada)', 'webhooks') . '</small><strong>'
            . ($next_ts
                ? Html::convDateTime(date('Y-m-d H:i:s', $next_ts))
                : '<span class="text-muted">' . __('pendiente primera corrida', 'webhooks') . '</span>')
            . '</strong></div>';
        echo '</div>';

        if ($state !== CronTask::STATE_WAITING && $state !== CronTask::STATE_RUNNING) {
            echo '<div class="alert alert-warning"><i class="ti ti-alert-circle"></i> '
                . __('La tarea cron está deshabilitada en GLPI. Los envíos programados NO se van a ejecutar hasta que se la reactive.', 'webhooks')
                . '</div>';
        }

        echo '<div class="d-flex flex-wrap gap-2">';
        echo "<form method='post' action='" . htmlspecialchars($action) . "' class='m-0'>";
        echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
        echo Html::hidden('id', ['value' => (int) $this->fields['id']]);
        $confirm = __('¿Ejecutar el planificador sobre TODOS los webhooks activos ahora?', 'webhooks');
        echo '<button type="submit" name="run_scheduler" class="btn btn-outline-primary" '
            . "onclick=\"return confirm(" . json_encode($confirm) . ")\">"
            . '<i class="ti ti-player-play"></i> '
            . __('Ejecutar planificador ahora (todos los webhooks)', 'webhooks')
            . '</button>';
        Html::closeForm();

        $cron_url = ($CFG_GLPI['root_doc'] ?? '') . '/front/crontask.form.php?id=' . (int) $task->fields['id'];
        echo '<a href="' . htmlspecialchars($cron_url) . '" class="btn btn-outline-secondary">'
            . '<i class="ti ti-external-link"></i> '
            . __('Abrir en admin de Cron', 'webhooks')
            . '</a>';
        echo '</div>';

        echo '</div></div>';
    }

    private static function humanizeSeconds(int $seconds): string
    {
        if ($seconds <= 0) {
            return '—';
        }
        if ($seconds % DAY_TIMESTAMP === 0) {
            $n = (int) ($seconds / DAY_TIMESTAMP);
            return sprintf(_n('%d día', '%d días', $n, 'webhooks'), $n);
        }
        if ($seconds % HOUR_TIMESTAMP === 0) {
            $n = (int) ($seconds / HOUR_TIMESTAMP);
            return sprintf(_n('%d hora', '%d horas', $n, 'webhooks'), $n);
        }
        if ($seconds % MINUTE_TIMESTAMP === 0) {
            $n = (int) ($seconds / MINUTE_TIMESTAMP);
            return sprintf(_n('%d minuto', '%d minutos', $n, 'webhooks'), $n);
        }
        return $seconds . 's';
    }

    // -----------------------------------------------------------------------
    // Tab: Upcoming
    // -----------------------------------------------------------------------

    public function showUpcomingTab(): void
    {
        $id           = (int) $this->fields['id'];
        $anticipation = (int) ($this->fields['anticipation_days'] ?? 30);
        $action       = Plugin::getWebDir('webhooks') . '/front/webhook.form.php';

        $this->showSchedulerCard($action);

        $entries = PluginWebhooksCrontask::collectUpcomingForWebhook($this);

        $counts = ['overdue' => 0, 'pending' => 0, 'sent' => 0, 'future' => 0, 'failed' => 0];
        foreach ($entries as $e) {
            $counts[$e['status']]++;
            if (!empty($e['last_failed'])) {
                $counts['failed']++;
            }
        }
        $actionable = $counts['overdue'] + $counts['pending'];

        // Upcoming only shows items that still need action OR are future.
        // Successfully sent items live in the Sent log tab.
        $visible = array_values(array_filter(
            $entries,
            fn($e) => $e['status'] !== 'sent'
        ));

        echo '<div class="card">';
        echo '<div class="card-header">';
        echo '<h3 class="card-title"><i class="ti ti-calendar-stats"></i> '
            . __('Contratos e ítems detectados', 'webhooks') . '</h3>';
        echo '<div class="card-subtitle text-muted">'
            . sprintf(
                __('Alcance completo del webhook. Ventana: %d días antes del vencimiento. Los ítems ya vencidos también reciben un aviso la primera vez.', 'webhooks'),
                $anticipation
            )
            . '</div>';
        echo '</div>';

        echo '<div class="card-body">';

        echo '<div class="d-flex align-items-center gap-2 flex-wrap mb-3">';
        echo "<form method='post' action='" . htmlspecialchars($action) . "' class='m-0'>";
        echo Html::hidden('id', ['value' => $id]);
        echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
        $disabled = $actionable === 0 ? 'disabled' : '';
        $confirm  = __('¿Enviar los vencidos y pendientes al URL del webhook ahora?', 'webhooks');
        echo "<button type='submit' name='send_pending' class='btn btn-primary' {$disabled} "
            . "onclick=\"return confirm(" . json_encode($confirm) . ")\">"
            . '<i class="ti ti-send"></i> '
            . sprintf(__('Enviar %d ahora', 'webhooks'), $actionable)
            . '</button>';
        Html::closeForm();

        echo '<span class="badge bg-danger" style="font-size:13px;"><i class="ti ti-alert-triangle"></i> '
            . sprintf(__('%d vencidos', 'webhooks'), $counts['overdue']) . '</span>';
        echo '<span class="badge bg-primary" style="font-size:13px;"><i class="ti ti-hourglass"></i> '
            . sprintf(__('%d pendientes', 'webhooks'), $counts['pending']) . '</span>';
        if ($counts['failed'] > 0) {
            echo '<span class="badge bg-warning" style="font-size:13px;"><i class="ti ti-refresh-alert"></i> '
                . sprintf(__('%d con intentos fallidos', 'webhooks'), $counts['failed']) . '</span>';
        }
        echo '<span class="badge bg-success" style="font-size:13px;"><i class="ti ti-check"></i> '
            . sprintf(__('%d enviados (ver Registro)', 'webhooks'), $counts['sent']) . '</span>';
        echo '<span class="badge bg-secondary" style="font-size:13px;"><i class="ti ti-clock"></i> '
            . sprintf(__('%d futuros', 'webhooks'), $counts['future']) . '</span>';
        echo '</div>';

        if ($visible === []) {
            if ($counts['sent'] > 0) {
                echo '<div class="text-center text-success p-4">'
                    . '<i class="ti ti-circle-check" style="font-size:32px;"></i><br>'
                    . sprintf(__('Los %d ítems se enviaron correctamente. Ver la pestaña "Registro de envíos" para el historial.', 'webhooks'), $counts['sent'])
                    . '</div>';
            } else {
                echo '<div class="text-center text-muted p-4">'
                    . '<i class="ti ti-database-off" style="font-size:32px;"></i><br>'
                    . __('No se detectaron ítems. Verificá que el webhook tenga tipos seleccionados y que el alcance de entidad coincida con tus datos.', 'webhooks')
                    . '</div>';
            }
            echo '</div></div>';
            return;
        }

        echo '<table class="table table-hover table-vcenter">';
        echo '<thead><tr>';
        echo '<th>' . __('Estado', 'webhooks') . '</th>';
        echo '<th>' . __('Tipo', 'webhooks') . '</th>';
        echo '<th>' . __('Nombre', 'webhooks') . '</th>';
        echo '<th>' . __('Entidad', 'webhooks') . '</th>';
        echo '<th>' . __('Vence', 'webhooks') . '</th>';
        echo '<th class="text-end">' . __('Días', 'webhooks') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($visible as $e) {
            $itemtype = $e['itemtype'];
            $row      = $e['row'];
            $dl       = (int) $row['days_left'];
            $status   = $e['status'];

            $row_style = '';
            switch ($status) {
                case 'overdue':
                    $status_cell = '<span class="badge bg-danger"><i class="ti ti-alert-triangle"></i> '
                        . __('Vencido', 'webhooks') . '</span>';
                    $row_style = 'background:#fff5f5;';
                    break;
                case 'pending':
                    $status_cell = '<span class="badge bg-primary"><i class="ti ti-hourglass"></i> '
                        . __('Pendiente', 'webhooks') . '</span>';
                    $row_style = 'background:#eff6ff;';
                    break;
                case 'future':
                default:
                    $status_cell = '<span class="badge bg-secondary"><i class="ti ti-clock"></i> '
                        . __('Futuro', 'webhooks') . '</span>';
                    $row_style = 'opacity:0.65;';
                    break;
            }

            if ($dl < 0 || $dl <= 3) {
                $days_badge = '<span class="badge bg-danger">' . $dl . '</span>';
            } elseif ($dl <= 7) {
                $days_badge = '<span class="badge bg-warning">' . $dl . '</span>';
            } elseif ($dl <= $anticipation) {
                $days_badge = '<span class="badge bg-info">' . $dl . '</span>';
            } else {
                $days_badge = '<span class="badge bg-secondary">' . $dl . '</span>';
            }

            $item_id   = (int) $row['id'];
            $item_link = '#';
            if (class_exists($itemtype)) {
                $item_link = $itemtype::getFormURLWithID($item_id);
            }

            $entity_name = '';
            $entity = new Entity();
            if ($entity->getFromDB((int) ($row['entities_id'] ?? 0))) {
                $entity_name = (string) $entity->fields['completename'];
            }

            $failure_note = '';
            if (!empty($e['last_failed']) && $e['last_attempt']) {
                $la   = $e['last_attempt'];
                $msg  = (string) ($la['error'] ?? '');
                if ($msg === '') {
                    $msg = 'HTTP ' . (int) ($la['http_status'] ?? 0);
                }
                $when = $la['sent_date'] ?? '';
                $failure_note = '<br><small class="text-danger"><i class="ti ti-refresh-alert"></i> '
                    . sprintf(
                        __('Último intento fallido: %s (%s) — va a reintentar', 'webhooks'),
                        htmlspecialchars(PluginWebhooksSender::excerpt($msg, 160)),
                        htmlspecialchars((string) $when)
                    )
                    . '</small>';
            }

            echo '<tr style="' . $row_style . '">';
            echo '<td>' . $status_cell . '</td>';
            echo '<td>' . htmlspecialchars(self::itemtypeLabel($itemtype)) . '</td>';
            echo '<td><a href="' . htmlspecialchars($item_link) . '">'
                . htmlspecialchars((string) $row['name']) . '</a>' . $failure_note . '</td>';
            echo '<td><small class="text-muted">' . htmlspecialchars($entity_name) . '</small></td>';
            echo '<td>' . htmlspecialchars((string) $row['expiration_date']) . '</td>';
            echo '<td class="text-end">' . $days_badge . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '<div class="text-muted small mt-2"><i class="ti ti-info-circle"></i> '
            . __('Los "Futuros" pasan automáticamente a "Pendiente" al entrar en la ventana de anticipación. Los "Enviados" dejan de alertar hasta que el ítem se renueve (cambie la fecha de vencimiento).', 'webhooks')
            . '</div>';
        echo '</div></div>';
    }

    // -----------------------------------------------------------------------
    // Tab: Test & status
    // -----------------------------------------------------------------------

    public function showTestTab(): void
    {
        $id     = (int) $this->fields['id'];
        $action = Plugin::getWebDir('webhooks') . '/front/webhook.form.php';

        echo '<div class="card mb-3">';
        echo '<div class="card-header"><h3 class="card-title"><i class="ti ti-send"></i> '
            . __('Enviar evento de prueba', 'webhooks') . '</h3></div>';
        echo '<div class="card-body">';
        echo '<p class="text-muted mb-3">'
            . __('Dispara un evento sintético ("Contrato vence en 15 días") al URL configurado usando el template actual. No genera entrada de dedupe.', 'webhooks')
            . '</p>';

        echo "<form method='post' action='" . htmlspecialchars($action) . "'>";
        echo Html::hidden('id', ['value' => $id]);
        echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
        echo '<button type="submit" name="test_send" class="btn btn-primary btn-lg">'
            . '<i class="ti ti-send"></i> ' . __('Enviar prueba ahora', 'webhooks') . '</button>';
        Html::closeForm();
        echo '</div></div>';

        echo '<div class="card">';
        echo '<div class="card-header"><h3 class="card-title"><i class="ti ti-activity"></i> '
            . __('Estado', 'webhooks') . '</h3></div>';
        echo '<div class="card-body p-0">';
        echo '<table class="table table-hover mb-0">';
        echo '<thead><tr><th style="width:30%;"></th><th>' . __('Cuándo', 'webhooks')
            . '</th><th>' . __('HTTP', 'webhooks') . '</th><th>' . __('Detalle', 'webhooks') . '</th></tr></thead>';
        echo '<tbody>';
        $this->renderStatusRow(
            '<i class="ti ti-broadcast"></i> ' . __('Último envío real', 'webhooks'),
            $this->fields['last_sent_date'] ?? null,
            $this->fields['last_http_status'] ?? null,
            $this->fields['last_error'] ?? null
        );
        $this->renderStatusRow(
            '<i class="ti ti-flask"></i> ' . __('Última prueba', 'webhooks'),
            $this->fields['last_test_date'] ?? null,
            $this->fields['last_test_status'] ?? null,
            $this->fields['last_test_response'] ?? null
        );
        echo '</tbody></table>';
        echo '</div></div>';
    }

    private function renderStatusRow(string $label, $when, $status, $detail): void
    {
        $when_cell = ($when === null || $when === '')
            ? '<span class="text-muted">—</span>'
            : Html::convDateTime((string) $when);

        $status_cell = '<span class="text-muted">—</span>';
        if ($status !== null && $status !== '') {
            $s = (int) $status;
            if ($s >= 200 && $s < 300) {
                $status_cell = '<span class="badge bg-success">' . $s . '</span>';
            } elseif ($s === 0) {
                $status_cell = '<span class="badge bg-dark">ERR</span>';
            } else {
                $status_cell = '<span class="badge bg-danger">' . $s . '</span>';
            }
        }

        $detail_cell = '<span class="text-muted">—</span>';
        if ($detail !== null && $detail !== '') {
            $detail_cell = '<pre style="margin:0; background:#f6f6f6; padding:6px 8px; font-size:11px; white-space:pre-wrap; max-height:160px; overflow:auto; border-radius:4px;">'
                . htmlspecialchars((string) $detail) . '</pre>';
        }

        echo '<tr>';
        echo '<td><strong>' . $label . '</strong></td>';
        echo '<td>' . $when_cell . '</td>';
        echo '<td>' . $status_cell . '</td>';
        echo '<td>' . $detail_cell . '</td>';
        echo '</tr>';
    }

    // -----------------------------------------------------------------------
    // Tab: Sent log
    // -----------------------------------------------------------------------

    public function showLogTab(): void
    {
        global $DB;

        $id = (int) $this->fields['id'];

        $iter = $DB->request([
            'FROM'  => 'glpi_plugin_webhooks_sent',
            'WHERE' => ['webhooks_id' => $id],
            'ORDER' => ['sent_date DESC'],
            'LIMIT' => 50,
        ]);

        echo '<div class="card">';
        echo '<div class="card-header"><h3 class="card-title"><i class="ti ti-history"></i> '
            . __('Últimos 50 eventos', 'webhooks') . '</h3></div>';
        echo '<div class="card-body p-0">';
        echo '<table class="table table-hover table-vcenter mb-0">';
        echo '<thead><tr>';
        echo '<th>' . __('Enviado', 'webhooks') . '</th>';
        echo '<th>' . __('Tipo', 'webhooks') . '</th>';
        echo '<th>' . __('ID', 'webhooks') . '</th>';
        echo '<th>' . __('Fecha de vencimiento', 'webhooks') . '</th>';
        echo '<th>' . __('HTTP', 'webhooks') . '</th>';
        echo '<th>' . __('Detalle', 'webhooks') . '</th>';
        echo '</tr></thead><tbody>';

        $count = 0;
        foreach ($iter as $row) {
            $count++;
            $status = (int) ($row['http_status'] ?? 0);
            if ($status >= 200 && $status < 300) {
                $badge = '<span class="badge bg-success">' . $status . '</span>';
            } elseif ($status === 0) {
                $badge = '<span class="badge bg-dark">ERR</span>';
            } else {
                $badge = '<span class="badge bg-danger">' . $status . '</span>';
            }

            $detail = (string) ($row['error'] ?? '');
            if ($detail === '') {
                $detail = (string) ($row['response_excerpt'] ?? '');
            }
            if ($status >= 200 && $status < 300 && $detail === '') {
                $detail = 'OK';
            }

            $itemtype_label = self::itemtypeLabel((string) $row['itemtype']);
            $item_link = '#';
            if (class_exists((string) $row['itemtype']) && (int) $row['items_id'] > 0) {
                $item_link = (string) $row['itemtype']::getFormURLWithID((int) $row['items_id']);
            }

            echo '<tr>';
            echo '<td>' . Html::convDateTime((string) $row['sent_date']) . '</td>';
            echo '<td>' . htmlspecialchars($itemtype_label) . '</td>';
            echo '<td>';
            if ($item_link !== '#') {
                echo '<a href="' . htmlspecialchars($item_link) . '">#' . (int) $row['items_id'] . '</a>';
            } else {
                echo (int) $row['items_id'];
            }
            echo '</td>';
            echo '<td>' . htmlspecialchars((string) $row['expiration_date']) . '</td>';
            echo '<td>' . $badge . '</td>';
            echo '<td><small>' . htmlspecialchars(PluginWebhooksSender::excerpt($detail, 120)) . '</small></td>';
            echo '</tr>';
        }

        if ($count === 0) {
            echo '<tr><td colspan="6" class="text-center text-muted p-4">'
                . '<i class="ti ti-inbox"></i> ' . __('Todavía no se enviaron eventos.', 'webhooks')
                . '</td></tr>';
        }

        echo '</tbody></table>';
        echo '</div></div>';
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    public static function itemtypeLabel(string $itemtype): string
    {
        switch ($itemtype) {
            case 'Contract':        return __('Contract');
            case 'SoftwareLicense': return __('Software license');
            case 'Certificate':     return __('Certificate');
            case 'Domain':          return __('Domain');
            case 'Infocom':         return __('Garantía (Infocom)', 'webhooks');
            default:                return $itemtype;
        }
    }

    public static function findActiveForItemtype(string $itemtype, int $entities_id): array
    {
        global $DB;

        $rows = [];
        $iter = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['is_active' => 1],
        ]);

        foreach ($iter as $row) {
            $decoded = json_decode((string) $row['itemtypes'], true);
            if (!is_array($decoded) || !in_array($itemtype, $decoded, true)) {
                continue;
            }
            if (!self::entityMatches((int) $row['entities_id'], (int) $row['is_recursive'], $entities_id)) {
                continue;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private static function entityMatches(int $webhook_entity, int $is_recursive, int $target_entity): bool
    {
        if ($webhook_entity === $target_entity) {
            return true;
        }
        if (!$is_recursive) {
            return false;
        }
        $ancestors = getAncestorsOf('glpi_entities', $target_entity);
        return in_array($webhook_entity, array_map('intval', $ancestors), true);
    }
}
