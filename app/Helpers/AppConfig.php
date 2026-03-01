<?php

if (!function_exists('checkIfNetworkExists')) {
    function checkIfNetworkExists()
    {
        $network = @fopen("https://www.google.com", "r");
        if ($network) {
            return true;
        } else {
            return false;
        }
    }
}
