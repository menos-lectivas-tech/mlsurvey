<?php

/**
 * Generates the declaration to be signed in the browser with AutoFirma
 * (js/declaracion-firma.js): it gets by POST the email address written in the
 * form and the certificate the participant has chosen, and answers with JSON:
 * {"pdf": the declaration model filled in with the DNI/NIE of the certificate
 * and the address, in base64} or {"error": the reason, to be shown}.
 *
 * Nothing is stored: the signed declaration is sent afterwards with the
 * declaration form and checked in full as an uploaded one
 * (RegistrationView::registerWithDeclaracion).
 */

require_once 'ifaces/view.php';
require_once 'utils/token.php';
require_once 'utils/pdfsign.php';

class DeclaracionPdf extends View
{

    public function doInit()
    {
        /* La página se ha generado con ob_start en index.php: solo va el JSON. */
        while (ob_get_level() > 0)
            ob_end_clean();
        header("Content-Type: application/json; charset=utf-8");
        header("Cache-Control: no-store");
        header("X-Content-Type-Options: nosniff");
        [$status, $body] = $this->generate();
        http_response_code($status);
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /** @return array [HTTP status, body] */
    private function generate(): array
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST')
            return [405, ["error" => "Método no permitido."]];
        /* Solo desde el formulario de la declaración, abierto en esta sesión:
           cada llamada lanza qpdf. El token no se gasta, se gasta al enviar. */
        if (!hasToken())
            return [403, ["error" => "La sesión ha caducado. Recarga la página."]];
        $email = $_POST['email'] ?? null;
        $cert = $_POST['cert'] ?? null;
        if (!is_string($email) || !is_string($cert))
            return [400, ["error" => "Faltan la dirección de correo o el certificado."]];
        try {
            $email = declaracionEmail($email);
            $dni = declaracionCertDni($cert);
            return [200, ["pdf" => base64_encode(declaracionFill($dni, $email))]];
        } catch (PdfSignException $e) {
            return [422, ["error" => $e->getMessage()]];
        } catch (Throwable $e) {
            logMessage(LOGGER_ERROR, "Error {$e} generating the declaration");
            return [500, ["error" => "No se ha podido generar la declaración. Inténtalo más tarde."]];
        }
    }

    public function show()
    {
    }
}
