<?php

declare(strict_types=1);

namespace GlpiPlugin\Glpichat;

use CommonGLPI;
use Profile;
use Session;

use function glpichat_right_names;

final class ProfileTab extends CommonGLPI
{
    public static function getTypeName($nb = 0): string
    {
        return 'GLPI Chat';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($withtemplate || !$item instanceof Profile) {
            return '';
        }

        return self::createTabEntry('GLPI Chat', 0, self::class, 'ti ti-message-circle');
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof Profile) {
            return false;
        }

        $rights_definitions = [
            [
                'label' => 'Chat publico',
                'field' => 'plugin_glpichat_public',
                'rights' => [
                    READ => __('Read'),
                    CREATE => __('Write'),
                ],
            ],
            [
                'label' => 'Chat interno',
                'field' => 'plugin_glpichat_internal',
                'rights' => [
                    READ => __('Read'),
                    CREATE => __('Write'),
                ],
            ],
            [
                'label' => 'Convidar terceiros',
                'field' => 'plugin_glpichat_invite',
                'rights' => [
                    READ => __('Read'),
                    CREATE => __('Write'),
                    UPDATE => __('Update'),
                ],
            ],
            [
                'label' => 'Ver auditoria',
                'field' => 'plugin_glpichat_view_audit',
                'rights' => [READ => __('Read')],
            ],
            [
                'label' => 'Administrar plugin',
                'field' => 'plugin_glpichat_admin',
                'rights' => [
                    READ => __('Read'),
                    UPDATE => __('Update'),
                ],
            ],
        ];

        $can_edit = Session::haveRight('profile', CREATE)
            || Session::haveRight('profile', UPDATE)
            || Session::haveRight('profile', PURGE);

        if ($can_edit) {
            echo '<form method="post" action="' . htmlescape(Profile::getFormURL()) . '" data-track-changes="true" data-submit-once>';
            echo '<input type="hidden" name="id" value="' . (int) $item->getID() . '">';
            echo \Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
        }

        $item->displayRightsChoiceMatrix($rights_definitions, [
            'canedit' => $can_edit,
        ]);

        if ($can_edit) {
            echo '<div class="form-button-separator card-body mx-n2 border-top d-flex flex-row-reverse align-items-start flex-wrap">';
            echo \Html::submit(_x('button', 'Save'), [
                'name' => 'update',
                'class' => 'btn btn-primary me-2',
                'icon' => 'ti ti-device-floppy',
            ]);
            echo '</div>';
            echo '</form>';
        }

        return true;
    }
}
