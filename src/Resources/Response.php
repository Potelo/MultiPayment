<?php

namespace   Potelo\MultiPayment\Resources;

/**
 * Class Response
 */
class Response
{

    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';

    /**
     * @var string
     */
    private string $status;

    /**
     * @var mixed
     */
    private $data;

    /**
     * @var mixed
     */
    private $error;

    /**
     * Response constructor.
     *
     * @param string $status
     * @param $response
     */
    public function __construct(string $status, $response)
    {
        $this->status = $status;
        if ($status === self::STATUS_SUCCESS) {
            $this->data = $response;
        } else {
            $this->error = $response;
        }
    }

    /**
     * @return string
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * @return mixed
     */
    public function getData()
    {
        return $this->data;
    }

    /**
     * @return mixed
     */
    public function getError()
    {
        return $this->error;
    }

    /**
     * @return bool
     */
    public function success(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    /**
     * @return bool
     */
    public function failed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }
}
