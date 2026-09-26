<?php
include_once 'utils/classname.php';
include_once 'utils/session.php';
include_once 'utils/logger.php';
include_once 'ifaces/view.php';
include_once 'utils/user.php';
require_once "include/config.php";

Config::getSystemConfig ();
startSession ();
$viewsfolder = 'views';
$classviewfile = $viewsfolder;
$classview = "";
if (isset($_GET['view'])){
	$classview = $_GET['view'];
}
else {
	if (isset($_SERVER['REDIRECT_URL']))
		$theuri = $_SERVER['REDIRECT_URL'];
	else 
		$theuri = $_SERVER['REQUEST_URI'];
	$uris = explode ('/', $theuri);
	$classview = $uris[count($uris) -1];
}
if ($classview == 'logout'){
	clearSession ();
	$classview = "";
}

if ($classview != ""){
	$classviewfile .= '/' . $classview . '.php';
}
else {
	$classview = "MainView";
}
if ($classview != 'MainView' && file_exists ($classviewfile)){
		include_once $classviewfile;
		$classview = getClassName($classview);
	}
	else {
		logMessage (LOGGER_ERROR,  "file {$classviewfile} for {$classview} does not exist");
		$classview = "MainView";
	}
	
	$view = new $classview;
	$view->doInit ();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<?php include 'include/themehead.php';?>
<title><?= Config::$sitename != ""?Config::$sitename : "mlsurvey · Consultas"?></title>
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="description" content="Plataforma de consultas y votaciones." />
<meta name="color-scheme" content="light dark" />
<!-- css -->
<link href="css/bootstrap.min.css" rel="stylesheet" />
<link href="css/fancybox/jquery.fancybox.css" rel="stylesheet">
<link href="css/style.css" rel="stylesheet" />
<!-- El sistema de temas va después de la plantilla: la reescribe. -->
<link href="css/theme.css" rel="stylesheet" />
<link href="css/button3.css" rel="stylesheet" />
<link href="css/ui.css" rel="stylesheet" />
<!-- Sustituyen a alert() y confirm(), que no siguen el tema. -->
<link href="css/dialog.css" rel="stylesheet" />
<script src="js/mldialog.js"></script>
<script src="js/jquery.js"></script>
<?php
$view->addHead ();
?>
<!-- HTML5 shim, for IE6-8 support of HTML5 elements -->
<!--[if lt IE 9]>
      <script src="http://html5shim.googlecode.com/svn/trunk/html5.js"></script>
    <![endif]-->
</head>
<body>
<div id="wrapper">
	<!-- start header -->
	<header>
        <div class="navbar navbar-default navbar-static-top">
            <div class="container">
                <div class="navbar-header">
                    <button type="button" class="navbar-toggle" data-toggle="collapse" data-target=".navbar-collapse">
                        <span class="icon-bar"></span>
                        <span class="icon-bar"></span>
                        <span class="icon-bar"></span>
                    </button>
                    <!-- Sustituir .ml-brand-mark por <img src="img/logo.svg" alt=""> cuando haya logotipo. -->
                    <a class="navbar-brand" href="index.php">
                        <span class="ml-brand-mark" aria-hidden="true">ml</span>
                        <span>
							<?= Config::$mainheader != ""?Config::$mainheader:"mlsurvey"?>
						</span>
                    </a>
                </div>
                <div class="navbar-collapse collapse ">
                    <ul class="nav navbar-nav">
<!--                        <li class="active"><a href="">Inicio</a></li> 
						<li><a href="admin">Administración</a></li>
						<li><a href="surveys">Consultas</a></li>-->
						<?php
						include_once 'include/menuarray.php';
						foreach ($menuarray as $key => $menuitem) {
							//var_dump($menuitem);
							$entry = "<li ";
							if ($menuitem[ML_MENU_GROUP] == $view->getMenuGroup ())
								$entry .= "class='active' ";
							$entry .= "><a href='{$menuitem[ML_MENU_LOCATION]}'>" . 
								$menuitem[ML_MENU_ENTRY] . "</a></li>";
							echo ($entry);
						}
						?>
                    </ul>
                </div>
                <?php include 'include/themeswitcher.php'; ?>
            </div>
        </div>
	</header>
	<!-- end header -->
<div class="featured_content">
  <div class="container">
	<?php
		
	showView ($view);
	?>
  </div>
</div>
	<footer>
	<div class="container">
		<div class="row">
			<div class="widget">
				<h5 class="widgetheading">mlsurvey</h5>
				<p>Consultas y votaciones en línea: participación con código,
				   resultados publicados al cierre.</p>
			</div>
			<div class="widget">
				<h5 class="widgetheading">Navegación</h5>
				<ul class="link-list">
					<?php
					foreach ($menuarray as $menuitem) {
						echo ("<li><a href='{$menuitem[ML_MENU_LOCATION]}'>" .
							$menuitem[ML_MENU_ENTRY] . "</a></li>");
					}
					?>
				</ul>
			</div>
			<div class="widget">
				<!-- Personalizar con los datos reales de contacto. -->
				<h5 class="widgetheading">Contacto</h5>
				<address><?= empty (Config::$contact)?"No definido":Config::$contact; ?></address>
			</div>
		</div>
	</div>
	<div id="sub-footer">
		<div class="container">
			<div class="row">
				<div class="col-lg-6">
					<div class="copyright">
						<p>
							<span>Powered by: <a href="https://github.com/pacoandres/mlsurvey" target="_blank">mlsurvey</a></span>
						</p>
						<p>
							<span>Special thanks to: <a href="https://github.com/PHPMailer/PHPMailer" target="_blank">PHPMailer</a>,
						<a href="https://github.com/hugerte/hugerte" target="_blank">HugeRTE</a>
						and <a href="https://github.com/altcha-org/altcha" target="_blank">ALTCHA</a></span>
						</p>
					</div>
				</div>
				<div class="col-lg-6">
					<ul class="social-network">
						<li><a href="#" data-placement="top" title="Facebook"><i class="fa fa-facebook"></i></a></li>
						<li><a href="#" data-placement="top" title="Twitter"><i class="fa fa-twitter"></i></a></li>
						<li><a href="#" data-placement="top" title="Linkedin"><i class="fa fa-linkedin"></i></a></li>
						<li><a href="#" data-placement="top" title="Pinterest"><i class="fa fa-pinterest"></i></a></li>
						<li><a href="#" data-placement="top" title="Google plus"><i class="fa fa-google-plus"></i></a></li>
						<!--
						<i class="fa-brands fa-mastodon"></i>
						<i class="fa-brands fa-bluesky"></i>
						<i class="fa-brands fa-telegram"></i>
						-->
					</ul>
				</div>
			</div>
		</div>
	</div>
	</footer>
</div>
<a href="#" class="scrollup"><i class="fa fa-angle-up active"></i></a>
<!-- javascript
    ================================================== -->
<!-- Placed at the end of the document so the pages load faster -->
<script src="js/jquery.easing.1.3.js"></script>
<script src="js/bootstrap.min.js"></script>
<script src="js/jquery.fancybox.pack.js"></script>
<script src="js/jquery.fancybox-media.js"></script> 
<script src="js/portfolio/jquery.quicksand.js"></script>
<script src="js/portfolio/setting.js"></script>
<script src="js/animate.js"></script>
<script src="js/custom.js"></script>
<script src="js/theme.js"></script>
<!--<script src="js/owl-carousel/owl.carousel.js"></script>-->
<?php
if (isView ($view))
	$view->addJavascript ();
?>
</body>
</html>
