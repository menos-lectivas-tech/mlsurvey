<?php

/**
 * This class handles the link sent when registering (verify?code=...): it
 * verifies the account, and from then on the participant can vote identifying
 * themselves with their client certificate.
 */

require_once 'ifaces/view.php';
require_once 'utils/dbutils.php';
require_once 'utils/crypt.php';
require_once 'utils/verification.php';
require_once 'utils/clientcert.php';

class Verify extends View
{

    function getMenuGroup()
    {
        return ML_MENU_GROUP_REGISTER;
    }

    public function show()
    {
        $code = $_GET['code'] ?? null;
        if (!is_string($code) || $code === "") {
            showMain();
            return;
        }
        try {
            $raw = url_base64_decode($code);
        } catch (InvalidArgumentException $e) {
            $this->invalidLink();
            return;
        }
        try {
            if (!verifyCode(dbConn(), $raw)) {
                $this->invalidLink();
                return;
            }
        } catch (Exception $e) {
            echo ("<p><strong>Error al verificar la cuenta.</strong></p>");
            logMessage(LOGGER_ERROR, "Error {$e} verifying an account");
            return;
        }
        echo ("<p><strong>Tu cuenta está verificada.</strong></p>");
        echo ("<p>Ya puedes participar en las <a href='surveys'>consultas activas</a> " .
            "identificándote con tu certificado electrónico.</p>");
    }

    private function invalidLink()
    {
        echo ("<p><strong>El enlace no es válido, ya se ha usado o ha caducado.</strong></p>");
        echo ("<p>Si tu cuenta aún no está verificada, puedes pedir un enlace nuevo en " .
            "<a href='" . h(certURL("register")) . "'>Regístrate</a>, identificándote con tu " .
            "certificado electrónico. Los enlaces caducan en " . VERIFY_MINUTES . " minutos.</p>");
    }
}
