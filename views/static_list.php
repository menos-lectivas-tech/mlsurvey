<?php
require_once 'include/fileparams.php';


class StaticList extends View
{

    public const STATIC_EXTENSION = ".html";
    public const MANAGEACTION = "static_action";
    public const PAGEID = "pageid";
    private array $files = array();
    #[Override]
    function isMulticol()
    {
        startSession();
        if (isAdmin())
            return true;

        return false;
    }

    #[Override]
    function leftMenu()
    {
        include 'include/userleftcolumn.php';
    }


    public function show()
    {
        if (!isAdmin()) {
            showMain();
            return;
        }
?>
        <div class="col-md-8">
            <h2>Gestión de páginas estáticas</h2>
            <form id="staticm" name="staticmanage" method="POST"
                action="static_manage">
                <?= setTokenHTML(); ?>
                <input type="hidden" name="<?= self::PAGEID; ?>" id="<?= self::PAGEID; ?>" value="">
                <?php
                $this->listPages();
                ?>
                <p>
                    <input type="submit" class="button-3" name="<?= self::MANAGEACTION ?>" id="add" value="Añadir">
                </p>
            </form>
        </div>
    <?php
    }

    private function listPages()
    {
        if (!is_dir (FileParams::STATIC_DIR))
            mkdir(FileParams::STATIC_DIR);
        
        $files = glob(FileParams::STATIC_DIR . "*" . self::STATIC_EXTENSION);
        if (empty($files)) {
            echo "<p><strong>No hay páginas estáticas que gestionar.</strong></p>";
            return;
        }
    ?>
        <link href="css/tablecard.css" rel="stylesheet" />
        <link href="css/button3.css" rel="stylesheet" />
        <div class="card-table-container">
            <table class="card-like-table ml-stack" id="surveystable">
                <thead>
                    <tr>
                        <td>Archivo de la página</td>
                        <td class="ml-row-actions">Acciones</td>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $id = 0;
                    foreach ($files as $filepath) {
                        if (!is_file($filepath))
                            continue;
                        $filename = pathinfo($filepath, PATHINFO_FILENAME);
                        $this->files[$id] = $filename;
                    ?>
                        <tr>
                            <td>
                                <?= $filename; ?>
                            </td>
                            <td class="ml-row-actions"><button type="button" class="button-3 is-ghost is-icon"
                                    id="copy-<?= $id; ?>" onclick="copylink_staticfile (<?= $id; ?>);"
                                    title="Copiar enlace" aria-label="Copiar enlace">
                                    <?= mlIcon('copy_static_link'); ?>
                                </button>
                                <button type="submit" class="button-3 is-ghost is-icon"
                                    name="<?= self::MANAGEACTION ?>" value="Modificar"
                                    id="modify-<?= $id; ?>" onclick="return pick_staticfile (<?= $id; ?>);"
                                    title="Modificar" aria-label="Modificar la página <?= htmlspecialchars($filename); ?>">
                                    <?= mlIcon('edit'); ?>
                                </button>
                                <button type="submit" class="button-3 is-ghost is-icon is-danger"
                                    name="<?= self::MANAGEACTION ?>" value="Eliminar"
                                    id="delete-<?= $id; ?>" onclick="delete_staticfile (<?= $id; ?>);"
                                    title="Eliminar" aria-label="Eliminar la página <?= htmlspecialchars($filename); ?>">
                                    <?= mlIcon('trash'); ?>
                                </button>
                            </td>
                        </tr>
                    <?php
                        $id++;
                    }
                    ?>
                </tbody>
            </table>
        </div>
    <?php
    }

    #[Override]
    public function addJavascript()
    {
    ?>
        <script type="text/javascript">
            var files = JSON.parse('<?= json_encode($this->files); ?>');

            function copylink_staticfile(id) {
                path = ' . FileParams::STATIC_DIR; ?>';
                link = '<?= Config::PARAMS["site_url"]; ?>' + "static_page?page=" + files[id];
                navigator.clipboard.writeText(link);
                mlDialog.alert(link + "\nCopiado al portapapeles.");
            }

            function pick_staticfile(id) {
                file = document.getElementById('<?= self::PAGEID; ?>');
                file.value = files[id];
                return true;
            }

            async function delete_staticfile(id) {
                event.preventDefault();
                const button = document.getElementById('delete-'+id);
                const form = document.getElementById('staticm');
                mlDialog.confirm({
                    title: "Eliminar página estática",
                    message: "¿Seguro que quieres eliminar la página " + files[id] +
                        "?\nEsto no se puede deshacer.",
                    confirmText: "Eliminar",
                    danger: true
                }).then(function(confirmado) {
                    if (!confirmado)
                        return false;

                    file = document.getElementById('<?= self::PAGEID; ?>');
                    file.value = files[id];
                    //For submit value sended.
                    button.dataset.ready = 'true';
                    form.requestSubmit(button);
                });
            }
        </script>
<?php
    }
}
