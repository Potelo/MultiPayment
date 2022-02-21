<?php

namespace Potelo\MultiPayment\Helpers;

/**
 *
 */
class Config
{
    /**
     * Default config path
     * @var string
     */
    private static string $configPath = __DIR__ . '/../config/multi-payment.php';

    /**
     * Setup config path
     *
     * @return void
     */
    public static function setup()
    {
        require_once(__DIR__ . '/../config/getenv.php');
        if (env('MULTIPAYMENT_CONFIG_PATH')) {
            self::$configPath = env('MULTIPAYMENT_CONFIG_PATH');
        }
    }

    /**
     * Get config array
     *
     * @return array
     */
    static public function getConfig(): array
    {
        self::setup();
        return require self::$configPath;
    }

    /**
     * Get config value by key
     *
     * @param  string  $key
     *
     * @return array|mixed
     */
    public static function get(string $key)
    {
        if (class_exists('\Illuminate\Config\Repository')) {
            return config('multi-payment.' . $key);
        }

        $configs = self::getConfig();
        $path = explode('.', $key);
        $value = $configs;
        foreach ($path as $key) {
            $value = $value[$key];
        }
        return $value;
    }
}
