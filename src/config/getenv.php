<?php

/**
 * get the value of an environment variable
 *
 * @param $variable
 * @param $default
 *
 * @return mixed|null
 */
function env($variable, $default = null)
{
    return $_ENV[$variable] ?? $default;
}
