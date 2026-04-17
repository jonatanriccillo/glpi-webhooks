<?php
/**
 * Integración de perfiles: registra el derecho "plugin_webhooks".
 * Por defecto sólo el perfil Super-Admin (id=4) recibe permisos completos;
 * el resto de los perfiles quedan en 0 y hay que habilitarlos a mano desde
 * Administración > Perfiles > [perfil] > Webhooks.
 */

if (!defined('GLPI_ROOT')) {
    die('Sorry. You can\'t access this file directly');
}

class PluginWebhooksProfile extends Profile
{
    public static $rightname = 'profile';

    public const SUPER_ADMIN_PROFILE_ID = 4;

    public static function getAllRights(bool $all = false): array
    {
        return [
            [
                'itemtype' => 'PluginWebhooksWebhook',
                'label'    => __('Webhooks', 'webhooks'),
                'field'    => 'plugin_webhooks',
            ],
        ];
    }

    public static function initProfile(): void
    {
        global $DB;

        foreach (self::getAllRights() as $right) {
            if (countElementsInTable('glpi_profilerights', ['name' => $right['field']]) === 0) {
                ProfileRight::addProfileRights([$right['field']]);
            }
        }
    }

    /**
     * Se ejecuta una vez durante el install. Otorga permisos completos al
     * perfil Super-Admin y, si el instalador no es Super-Admin, también a
     * su propio perfil. Los demás perfiles se mantienen en 0.
     */
    public static function createFirstAccess(int $profiles_id): void
    {
        $rights = [];
        foreach (self::getAllRights(true) as $right) {
            $rights[$right['field']] = ALLSTANDARDRIGHT;
        }

        ProfileRight::updateProfileRights(self::SUPER_ADMIN_PROFILE_ID, $rights);

        if ($profiles_id !== self::SUPER_ADMIN_PROFILE_ID && $profiles_id > 0) {
            ProfileRight::updateProfileRights($profiles_id, $rights);
        }
    }

    public function showForm($profiles_id = 0, $openform = true, $closeform = true)
    {
        echo "<div class='firstbloc'>";
        if (
            ($canedit = Session::haveRightsOr(
                self::$rightname,
                [CREATE, UPDATE, PURGE]
            ))
            && $openform
        ) {
            $profile = new Profile();
            echo "<form method='post' action='" . $profile->getFormURL() . "'>";
        }

        $profile = new Profile();
        $profile->getFromDB($profiles_id);

        $rights = self::getAllRights();
        $profile->displayRightsChoiceMatrix(
            $rights,
            [
                'canedit'       => $canedit,
                'default_class' => 'tab_bg_2',
                'title'         => __('Webhooks', 'webhooks'),
            ]
        );

        if ($canedit && $closeform) {
            echo "<div class='center'>";
            echo Html::hidden('id', ['value' => $profiles_id]);
            echo Html::submit(_sx('button', 'Save'), ['name' => 'update']);
            echo '</div>';
            Html::closeForm();
        }
        echo '</div>';
    }
}
