<?php


class StaticPage extends View
{

    #[Override]
    public function loadStyles()
    {
?>
        <link href="css/staticpage.css" rel="stylesheet" />
<?php
    }

    #[Override]
    public function show()
    {
        if (!isset($_REQUEST["page"])) {
            showMain();
            return;
        }
        $file = "static/" . $_REQUEST["page"] . ".html";
        if (!is_file($file)) {
            showMain();
            return;
        }

        echo "<div class=\"staticpage\">\n", file_get_contents($file), "\n</div>\n";
    }
}
