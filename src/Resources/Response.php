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
     *
     * @return void
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
     * Get status
     *
     * @return string
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * Get data
     *
     * @return mixed
     */
    public function getData()
    {
        return $this->data;
    }

    /**
     * Get error
     *
     * @return mixed
     */
    public function getError()
    {
        return $this->error;
    }

    /**
     * Check if status is success
     *
     * @return bool
     */
    public function success(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    /**
     * Check if status is failed
     *
     * @return bool
     */
    public function failed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }
}
