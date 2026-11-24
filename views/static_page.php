<?php


class StaticPage extends View {

#[Override]
	public function show()
    {
        if (!isset ($_REQUEST["page"])){
            showMain();
            return;
        }
        $file = "static/" . $_REQUEST["page"] . ".html";
        if (!is_file($file)){
            showMain();
            return;
        }

        echo (file_get_contents($file));
    }
}