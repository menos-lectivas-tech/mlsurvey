<?php
require_once 'utils/session.php';
require_once 'utils/dbutils.php';
require_once 'utils/token.php';

/**
 * Gets user name from user id
 * 
 * @param int $id User id.
 * 
 * @return string User name.
 * 
 * @throws Exception If not found or on database error.
 */
function getUserName ($id){
    $query = dbConn ()->prepare ("SELECT username from {Users} WHERE userid= :id LIMIT 1");
    $query->bindParam (':id', $id);
    $query->execute ();
    if ($query->rowcount () == 0)
        throw new Exception("No userid {$id}");
    return $query->fetch ()['username'];
}

/**
 * Deletes user from database.
 * 
 * @param int $id User id
 * 
 * @throws Exception Database exception if throwed.
 */
function rmUser ($id){
    $query = dbConn ()->prepare ("DELETE from {Users} WHERE userid= :id");
    $query->bindParam (':id', $id);
    $query->execute ();
}

/**
 * Adds user
 * 
 * @param string $user The user name.
 * @param string $pass The password
 * @param string $role The user role. Now empty means survey manager and non emty is admin.
 * 
 * @throws Exception Database exception if throwed or when user name is in use.
 */
function adduser ($user, $pass, $role = ''){
    $iscli = (PHP_SAPI === 'cli');
    if (!$iscli){
        startSession ();
        if (!isAdmin ())
            return 1;
    }
    $dbconn = dbConn ();
    if ($iscli)
        echo "Comprobando unicidad del usuario\n";
    $query = $dbconn->prepare ("select * from {Users} where username= :usu");
    $query->bindParam (':usu', $user);
    $query->execute ();
    if ($query->rowcount () > 0)
        throw new Exception("Ya existe un usuario con ese nombre");
    if ($iscli)
        echo "Añadiendo usuario\n";
    $pass_crypt = password_hash ($pass, PASSWORD_DEFAULT);
    $insert = $dbconn->prepare ('insert into {Users} (username, passwd, role) values (:usu, :pass, :role)');
    $insert->bindParam (':pass', $pass_crypt);
    $insert->bindParam (':usu', $user);
    $insert->bindParam (':role', $role);
    $insert->execute ();
    return 0;
}

/**
 * Check if a user is an admin.
 * 
 * @param int $id The user id. If not provided, the current user.
 * 
 * @return bool
 * 
 * @throws Exception Database exception if throwed.
 */
function isAdmin ($id = ""){
    startSession ();
    if ($id === "")
        return isset ($_SESSION['admin']);
    $query = dbConn ()->prepare ("SELECT role from {Users} where userid = :id");
    $query->bindParam (':id', $id);
    $query->execute ();
    if ($query->rowCount () == 0)
        throw new Exception("Can't find user with id {$id}");
    return $query->fetch ()['role'] != "";
}

/**
 * Checks if a user is logged in.
 * 
 * @return bool
 */
function isUser (){
    startSession ();
    return isset ($_SESSION['userid']);
}


/**
 * Validates user/password combination.
 * 
 * 
 * @param string $user The user name.
 * @param string $passwd The password
 * @param int $id The id of the user for checking. If provided, validates user name and
 * password for the provided id.
 * 
 * @return bool
 * 
 * @throws Exception On error.
 */
function validateUser ($user, $passwd, $id = -1){
    startSession ();
    if (!checkToken ())
        throw new Exception("Wrong security token", 1);
    if (isset($_SESSION['userid']) && $id == -1)
        return 0;

    $dbconn = dbConn ();
    if ($id == -1){
        $query = $dbconn->prepare ("select * from {Users} where username = :usu LIMIT 1");
        $query->bindParam (':usu', $user, PDO::PARAM_STR);
    }
    else {
        $query = $dbconn->prepare ("select * from {Users} where userid = :id LIMIT 1");
        $query->bindParam (':id', $id, PDO::PARAM_INT);
    }
    $query->execute ();
    $row = $query->fetch ();
    if ($row === false || !isset ($row['passwd']) || !password_verify ($passwd, $row['passwd']))
        return 1;
    if ($id == -1){
        /* Id de sesión nuevo al entrar: uno fijado de antemano
           por un tercero no debe quedar autenticado. */
        session_regenerate_id (true);
        $_SESSION['userid'] = $row['userid'];
        if ($row['role'] != '')
            $_SESSION['admin'] = true;
        else
            unset ($_SESSION['admin']);
    }
    return 0;
}

/* Si ya hay otra usuaria (distinta de $exceptid) con ese nombre. */
function userNameExists ($username, $exceptid = -1): bool {
    $dbconn = dbConn ();
    $query = $dbconn->prepare ("SELECT 1 FROM {Users} WHERE username = :usu AND userid <> :id");
    $query->bindParam (':usu', $username, PDO::PARAM_STR);
    $query->bindParam (':id', $exceptid, PDO::PARAM_INT);
    $query->execute ();
    $exists = $query->rowCount () > 0;
    $query->closeCursor ();
    return $exists;
}

/**
 * Changes user data.
 * 
 * @param int $id The user id.
 * @param string $username The new user name.
 * @param string $isadmin The new role.
 * @param string $passwd If provided, the new password. Otherwise lefts password unchanged.
 * 
 * @throws Exception Database exception if throwed.
 */
function alterUser ($id, $username, $isadmin, $passwd = ""){
    /* El nombre identifica a la usuaria al entrar: no puede repetirse. */
    if (userNameExists ($username, $id))
        throw new Exception ("Ya existe un usuario con ese nombre");
    $dbconn = dbConn ();
    if ($passwd != ""){
        $query = $dbconn->prepare ("UPDATE {Users} set username = :name, passwd = :passwd, " .
            "role = :admin where userid = :id");
        $query->bindValue (':passwd', password_hash ($passwd, PASSWORD_DEFAULT));
    }
    else
        $query = $dbconn->prepare ("UPDATE {Users} set username = :name, role = :admin where userid = :id");
    $query->bindParam (':id', $id);
    $query->bindParam (':name', $username);
    $query->bindParam (':admin', $isadmin);
    $query->execute ();
}
/*
 * Revalida la usuaria de la sesión contra la base de datos en cada
 * petición: si la han eliminado pierde la sesión y, si le han cambiado el
 * rol, el cambio se aplica ya, no cuando vuelva a entrar.
 */
function refreshUserSession (){
    startSession ();
    if (!isset ($_SESSION['userid']))
        return;
    $dbconn = dbConn ();
    $query = $dbconn->prepare ("SELECT role FROM {Users} WHERE userid = :id");
    $query->bindParam (':id', $_SESSION['userid'], PDO::PARAM_INT);
    $query->execute ();
    $row = $query->fetch ();
    $query->closeCursor ();
    if ($row === false){
        logMessage (LOGGER_INFO, "User {$_SESSION['userid']} no longer exists, closing session");
        unset ($_SESSION['userid'], $_SESSION['admin']);
        return;
    }
    if (!empty ($row['role']))
        $_SESSION['admin'] = true;
    else
        unset ($_SESSION['admin']);
}
