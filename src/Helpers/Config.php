<?php

namespace Potelo\MultiPayment\Helpers;

class Config
{
    private static string $configPath = __DIR__ . '/../config/multi-payment.php';

    public static function setup()
    {
        if (!empty($_ENV['MULTIPAYMENT_CONFIG_PATH'])) {
            self::$configPath = $_ENV['MULTIPAYMENT_CONFIG_PATH'];
        }
    }

    static public function getConfig(): array
    {
        self::setup();
        return require self::$configPath;
    }

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
