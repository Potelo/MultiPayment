<?php
namespace Potelo\MultiPayment\Tests;

class TestCase extends \PHPUnit\Framework\TestCase
{
    public function __construct(?string $name = null, array $data = [], $dataName = '')
    {
        $dotenv = \Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
        $dotenv->safeLoad();
        parent::__construct($name, $data, $dataName);
    }
}