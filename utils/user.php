<?php
require_once 'utils/session.php';
require_once 'utils/dbutils.php';
require_once 'utils/token.php';

function getUserName ($id){
    try {
        $dbconn = dbConn ();
        $query = $dbconn->prepare ("SELECT username from {Users} WHERE userid= :id LIMIT 1");
        $query->bindParam (':id', $id);
        $query->execute ();
        if ($query->rowcount () == 0){
            throw new Exception("Delete user: No userid {$id}");
            return null;
        }
        $row = $query->fetch ();
        return $row['username'];
    } catch (Exception $e) {
        throw $e;
    }
    return null;
}

function rmUser ($id){
    try {
        $dbconn = dbConn ();
        $query = $dbconn->prepare ("DELETE from {Users} WHERE userid= :id");
        $query->bindParam (':id', $id);
        $query->execute ();
    }
    catch (Exception $e){
        throw $e;
    }
}

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
    if ($query->rowcount () > 0){
      throw new Exception("Ya existe un usuario con ese nombre");
      return 1;
    }
    else {
        try {
            if ($iscli)
                echo "Añadiendo usuario\n";

            $pass_crypt = password_hash ($pass, PASSWORD_DEFAULT);
            $insert = $dbconn->prepare ('insert into {Users} (username, passwd, role) values (:usu, :pass, :role)');
            $insert->bindParam (':pass', $pass_crypt);
            $insert->bindParam (':usu', $user);
            $insert->bindParam (':role', $role);
            $insert->execute ();
        }
        catch (Exception $e){
            throw $e;
            return 1;           
        }
    }
    return 0;
}

function isAdmin ($id = ""){
    startSession ();
    if ($id === ""){
        if (isset($_SESSION['admin']))
            return true;
        return false;
    }
    else {
        try {
            $dbconn = dbConn ();
            $query = $dbconn->prepare ("SELECT role from {Users} " . 
            "where userid = :id");
            $query->bindParam (':id', $id);
            $query->execute ();
            if ($query->rowCount () == 0){
                throw new Exception("Can't find user with id {$id}");
                return false;
            }
            $row = $query->fetch ();
            if ($row['role'] != "")
                return true;
        }
        catch (Exception $e){
            throw $e;
            return false;
        }
    }
    return false;
}

function isUser (){
    startSession ();
    if (isset($_SESSION['userid']))
        return true;
    return false;    
}

function validateUser ($user, $passwd, $id = -1){
    startSession ();
    /*if (!isset($_SESSION['token']) || $_SESSION['token'] != $token){
        throw new Exception("Wrong security token", 1);
        return 1;
        
    }*/
    if (!checkToken ()){
        throw new Exception("Wrong security token", 1);
        return 1;
    }
    if (isset($_SESSION['userid']) && $id == -1){
        return 0;
    }


    try {
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
    }
    catch (Exception $e){
        throw $e;
        return 1;
        
    }
    if ($query->rowCount () != 0){
        $row = $query->fetch ();
        if (isset ($row['passwd'])){
            $pass_crypt = $row['passwd'];
            //if ($pass_crypt == crypt($passwd, $pass_crypt)) {
            if (password_verify ($passwd, $pass_crypt)){
                if ($id == -1){
                    /* Id de sesión nuevo al entrar: uno fijado de antemano
                       por un tercero no debe quedar autenticado. */
                    session_regenerate_id (true);
                    $_SESSION['userid'] = $row['userid'];
                    if ($row['role'] != '')
                        $_SESSION['admin'] = true;
                    else {
                        unset ($_SESSION['admin']);
                    }              
                }     
                return 0;
            }
        }
    }
    
    return 1;
    
}

function alterUser ($id, $username, $isadmin, $passwd = ""){
    try {
        if ($passwd == "")
            $pass_crypt = "";
        else
            $pass_crypt = password_hash ($passwd, PASSWORD_DEFAULT);

        $dbconn = dbConn ();
        if ($passwd != ""){
            $query = $dbconn->prepare ("UPDATE {Users} " . 
                " set username = :name, passwd = :passwd, " . 
                "role = :admin where userid = :id");
            $query->bindParam (':passwd', $pass_crypt);
        }
        else{
            $query = $dbconn->prepare ("UPDATE {Users} " . 
                " set username = :name, role = :admin where userid = :id");
        }

        $query->bindParam (':id', $id);
        $query->bindParam (':name', $username);
        $query->bindParam (':admin', $isadmin);
        $query->execute ();
    }
    catch (Exception $e){
        throw $e;
        return 1;
    }
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
