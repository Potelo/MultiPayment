<?php

namespace Potelo\MultiPayment\Config;

class Config
{
    private static string $configPath = __DIR__ . '/../config/multi-payment.php';

    public static function setup()
    {
        if (!empty($_ENV['MULTI_PAYMENT_CONFIG_PATH'])) {
            self::$configPath = $_ENV['MULTI_PAYMENT_CONFIG_PATH'];
        }
    }

     static public function getConfig(): array
    {
        return require self::$configPath;
    }

    public static function get(string $key)
    {
        self::setup();
        $configs = self::getConfig();
        $path = explode('.', $key);
        $value = $configs;
        foreach ($path as $key) {
            $value = $value[$key];
        }
        return $value;
    }
}