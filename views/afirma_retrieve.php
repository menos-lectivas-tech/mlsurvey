<?php

/**
 * RetrieveService of the @firma intermediate server (utils/afirmaproxy.php),
 * used by autoscript.js and AutoFirma in mobile devices.
 */

require_once 'ifaces/view.php';
require_once 'utils/afirmaproxy.php';

class AfirmaRetrieve extends View
{
    public function doInit()
    {
        afirmaProxyRetrieve();
    }

    public function show()
    {
    }
}
