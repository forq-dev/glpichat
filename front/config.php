<?php

define('GLPI_ROOT', dirname(__DIR__, 3));
include (GLPI_ROOT . "/inc/includes.php");

Session::checkRight("plugin_glpichat_admin", READ);

Html::header("GLPI Chat", $_SERVER['PHP_SELF'], "config", "plugins");

if (isset($_POST['update'])) {
    Session::checkRight("plugin_glpichat_admin", UPDATE);
    Config::setConfigurationValues('plugin:glpichat', [
        'guest_session_ttl'         => (int)$_POST['guest_session_ttl'],
        'external_followup_user_id' => (int)$_POST['external_followup_user_id'],
    ]);
    Html::back();
}

$guest_session_ttl = (int)Config::getConfigurationValue('plugin:glpichat', 'guest_session_ttl');
if ($guest_session_ttl <= 0) {
    $guest_session_ttl = 3600; // Padrão: 1 hora
}
$external_followup_user_id = (int)Config::getConfigurationValue('plugin:glpichat', 'external_followup_user_id');

echo "<div class='container' style='max-width: 800px; margin-top: 20px;'>";
echo "<form method='post' action='" . Html::getSimpleFormAction($_SERVER['PHP_SELF']) . "'>";
// CSRF token nativo do GLPI
Html::writeFormHeader();

echo "<div class='card'>";
echo "  <div class='card-header'><strong>" . __("Configurações do GLPI Chat", "glpichat") . "</strong></div>";
echo "  <div class='card-body'>";

// Campo TTL
echo "    <div class='mb-3'>";
echo "      <label class='form-label' for='guest_session_ttl'>" . __("Tempo limite de inatividade da sessão de convidados (TTL em segundos)", "glpichat") . "</label>";
echo "      <input type='number' min='60' name='guest_session_ttl' id='guest_session_ttl' class='form-control' value='$guest_session_ttl' required>";
echo "      <div class='form-text text-muted'>" . __("Tempo que a sessão do convidado externo permanece ativa sem interações antes de expirar.", "glpichat") . "</div>";
echo "    </div>";

// Campo external_followup_user_id
echo "    <div class='mb-3'>";
echo "      <label class='form-label' for='dropdown_external_followup_user_id'>" . __("Usuário do GLPI associado a mensagens de convidados externos", "glpichat") . "</label>";
User::dropdown([
    'name' => 'external_followup_user_id',
    'value' => $external_followup_user_id,
    'width' => '100%',
]);
echo "      <div class='form-text text-muted'>" . __("As ações no chat e followups inseridos por convidados externos serão associadas a este usuário do GLPI.", "glpichat") . "</div>";
echo "    </div>";

echo "  </div>"; // card-body
echo "  <div class='card-footer text-end'>";
if (Session::haveRight("plugin_glpichat_admin", UPDATE)) {
    echo "    <input type='submit' name='update' class='btn btn-primary' value='" . __("Salvar") . "'>";
}
echo "  </div>";
echo "</div>"; // card

echo "</form>";
echo "</div>"; // container

Html::footer();
