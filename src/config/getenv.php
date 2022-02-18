<?php

/**
 * @param $variable
 * @param $default
 *
 * @return mixed|null
 */
function env($variable, $default = null)
{
    return $_ENV[$variable] ?? $default;
}