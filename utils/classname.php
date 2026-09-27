<?php
function getClassName ($filename){
    $ret = strtoupper ($filename[0]);
    for ($i = 1; $i < strlen ($filename); $i++){
        if ($filename[$i] == '_' && $i + 1 < strlen ($filename)){
            $ret .= strtoupper ($filename[++$i]);
            continue;
        }
        $ret .= $filename[$i];
    }
    return $ret;
}