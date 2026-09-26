<?php
/**
 * This is the base class for all Views. Defines some default methods and an
 * the abstract method 'show' so every View must define its own.
 */
include_once 'utils/logger.php';
abstract class View 
{
    public const MENU_GROUP_NONE = "none";
    
    /**
     * This method creates the corresponding page content.
     */
    abstract public function show ();

    /**
     * With this method the class tells to index which is the disposition of the
     * page content.
     * 
     * @return "false when the content is a block and true if it's multicol. Default is false"
     */
    public function isMulticol (){
        return false;
    }

    /**
     * Creates the left column is View has multiple columns
     */
    public function leftMenu (){
        return;
    }

    /**
     * Creates the right column is View has multiple columns
     */
    public function rightMenu (){
        return;
    }

    /**
     * This method is called by index.php in the styles block. 
     * Use it for loading/create extra css styles.
     */
    public function loadStyles (){
        return;
    }

    /**
     * This method is called by index.php in the styles block. 
     * Use it for loading/create extra javascript.
     */
    public function addJavascript (){
        return;
    }

    /**
     * This method tells index.php which menu item should be highlighted.
     * 
     * @return "MENU_GROUP_NONE or one of the values defined in include/menuarray.php"
     */
    public function getMenuGroup (){
        return self::MENU_GROUP_NONE;
    }

    /**
     * This method is called by index.php before any HTML code has been generated.
     */
    public function doInit (){
        return;
    }

    /**
     * This method is called by index.php in the HTML head section for including
     * extra head information needed by the View. 
     */
    public function addHead (){
        return;
    }

}

/**
 * Method for checking if an object extends the View class.
 * 
 * @return "true if object extends the View class"
 */
function isView ($viewobject){
    if ($viewobject instanceof View){
        return true;
    }
    $name = get_class ($viewobject);
    logMessage (LOGGER_ERROR, "Class {$name} does not implement View");
    return false;
}

/**
 * This method shows the content of a View class extender.
 * 
 * @param $view The View object.
 */
function showView ($view){
    if (isView ($view)){
		$view->loadStyles ();
        $name = get_class ($view);
		if ($view->isMulticol ()){
			logMessage (LOGGER_DEBUG, "Loading multicols for {$name}");
			echo ('<div class="row">');
			$view->leftMenu ();
			$view->show ();
			$view->rightMenu ();
			echo ('</div>');
		}
		else {
			logMessage (LOGGER_DEBUG, "Loading multicols for {$name}");
			$view->show ();
		}
	}
}

/**
 * This is the class for the main page.
 */
class MainView extends View {
    function show (){

    if (Config::$maincontent != "")
        echo (Config::$maincontent);
     
    else
        echo ("<h1>Este es el contenido de main</h1>\n");
    }
}

/**
 * Creates a Main View object and shows it
 */
function showMain (){
    $main = new MainView;
    $main->show ();
}