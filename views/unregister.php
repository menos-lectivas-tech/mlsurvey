<?php
require_once 'utils/token.php';
require_once 'utils/altcha.php';

class Unregister extends View
{
    public function addHead()
    {
        echo (altchaScriptHTML());
    }
    
    public function loadStyles()
    {
?>
        <link href="css/button3.css" rel="stylesheet" />
        <link href="css/questions.css" rel="stylesheet" />
        <?= altchaStyleHTML(); ?>
    <?php
    }

    public function show()
    {
    ?>
        <section class="ml-participate-card" id="unregister">
            <form name="unregisterform" method="POST" action="unsubscribe">
                <?= setTokenHTML(); ?>
                <h3>Date de baja</h3>
                <p>Date de baja indicando tu dirección de correo.</p>
                <p><strong>El proceso se puede alargar hasta 30 días para que no puedas repetir tu
                        participación en ninguna consulta.</strong></p>
                <p>Una vez solicitada la baja recibirás un mensaje de correo con un enlace para
                    confirmarla. Para finalizar el proceso debes pulsar en ese enlace, de otra forma
                    no causarás baja.</p>
                <div class="ml-participate-field">
                    <label for="unregister-email">Dirección de correo</label>
                    <small>Debe coincidir con la que usaste para participar.</small>
                </div>
                <div class="ml-participate-row">
                    <input type="email" name="email" id="unregister-email" required>
                    <?= altchaWidgetHTML(); ?>
                    <button type="submit" class="button-3 ml-participate-btn">Solicitar la baja</button>
                </div>
            </form>
        </section>
<?php
    }
}
