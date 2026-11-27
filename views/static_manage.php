<?php
require "views/static_list.php";

class StaticManage extends View
{

    private const MANAGEACTION = "static_manage";
    private bool $mustshow = false;
    private const PAGECONTENT = "content";
    #[Override]
    function addHead()
    {
?>
        <script type="text/javascript" src="vendor/hugerte/hugerte/hugerte.min.js" referrerpolicy="origin" crossorigin="anonymous"></script>
        <!-- Viste el marco del editor con el tema; el JS se ocupa del iframe. -->
        <link href="css/hugerte-theme.css" rel="stylesheet" />
        <script src="js/hugerte-theme.js"></script>
    <?php
    }

    #[Override]
    function doInit()
    {
        $this->mustshow = isAdmin() && (isset($_REQUEST[StaticList::MANAGEACTION]) ||
            isset($_REQUEST[self::MANAGEACTION]));
    }

    #[Override]
    function isMulticol()
    {
        startSession();
        if ($this->mustshow)
            return true;

        return false;
    }

    #[Override]
    function leftMenu()
    {
        include 'include/userleftcolumn.php';
    }

    #[Override]
    public function show()
    {
        if (!$this->mustshow) {
            showMain();
            return;
        }
        if (!checkToken()) {
            echo ('<div class="col-md-8">');
            tokenError();
            echo ('</div>');
            return;
        }
        echo "<div class='col-md-8'>";
        if (isset($_REQUEST[self::MANAGEACTION])) {
            $this->savePage();
            return;
        }
        if ($_REQUEST[StaticList::MANAGEACTION] == "Eliminar") {
            $this->deletePage($_REQUEST[StaticList::PAGEID]);
            return;
        }
        $this->editPage($_REQUEST[StaticList::MANAGEACTION]);
        echo "</div>";
    }

    private function deletePage(string $file)
    {
        $filename = FileParams::STATIC_DIR . $file . StaticList::STATIC_EXTENSION;
        unlink($filename);
        echo "<p><strong>La página {$file} ha sido eliminada.</strong></p>";
    }

    private function editPage(string $action)
    {
        $content = "";
        $page = $_REQUEST[StaticList::PAGEID];
        if ($action == "Modificar") {
            $filepath = FileParams::STATIC_DIR . $page .
                StaticList::STATIC_EXTENSION;
            $content = file_get_contents($filepath);
            echo "<h1>Modificando la página estática <i>{$page}</i></h1>";
        } else
            echo "<h1>Creando una página estática</h1>";
    ?>
        <form id='staticm' name='staticm' method='POST' action='static_manage'>
            <?= setTokenHTML(); ?>
            <p><label for="page">Archivo de la página:<br>
                    <small>(no se permiten los espacios, tildes ni los caracteres
                        ñ, Ñ, /, \, ?, *, ni signos de puntuación)</small>
                </label>
                <input type="text" id="<?= StaticList::PAGEID; ?>"
                    name="<?= StaticList::PAGEID; ?>" tabindex="-1"
                    value="<?= $page; ?>" required pattern="[a-zA-z0-9\+_\-]+">
            </p>
            <p><label for="pagedesc">Contenido:</label>
                <textarea class="pagecontent" name="<?= self::PAGECONTENT; ?>"
                    id="<?= self::PAGECONTENT; ?>">
                    <?= htmlspecialchars($content); ?>
                </textarea>
            </p>
            <p><input type="submit" name="<?= self::MANAGEACTION; ?>" value="Guardar"
                    onclick="return savePage ();" class="button-3">
                <a class="button-3" href="static_list">Cancelar</a>
            </p>
        </form>
    <?php
    }

    private function savePage()
    {
        $page = $_REQUEST[StaticList::PAGEID];
        $filename = FileParams::STATIC_DIR . $page .
            StaticList::STATIC_EXTENSION;
        error_clear_last();
        $result = @file_put_contents(
            $filename,
            $_REQUEST[self::PAGECONTENT]
        );
        if ($result === false) {
            $e = error_get_last();
            echo "<p><strong>Error al guardar la página estática {$page}.</strong></p>";
            logMessage(LOGGER_ERROR, "Can't save {$page} static page: {$e}");
            return;
        }
        echo "<p><strong>La página estática {$page} ha sido guardada.</strong></p>";
    }
    #[Override]
    public function addJavascript()
    {
    ?>
        <script type='text/javascript'>
            $(document).ready(function() {
                hugerte.init(mlHugerte.options({
                    selector: '.pagecontent',
                    plugins: 'link autolink lists',
                    toolbar: 'undo redo | styles | bold italic | link | indent outdent | bullist numlist'
                }));
            });

            function savePage() {
                return true;
            }
        </script>
<?php
    }
}
