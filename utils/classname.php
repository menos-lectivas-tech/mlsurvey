<?php
/**
 * Converts a file name to a class name with the following rules.
 * - First letter is UPPERED.
 * - Underscores are removed and the following letter is UPPERED
 */
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