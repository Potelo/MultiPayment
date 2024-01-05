<?php

namespace Potelo\MultiPayment\Builders;

use Potelo\MultiPayment\Models\Model;
use Potelo\MultiPayment\Contracts\Gateway;
use Potelo\MultiPayment\Helpers\ConfigurationHelper;

class Builder
{
    protected Gateway $gateway;
    protected Model $model;

    /**
     * Builder constructor.
     *
     * @param  Gateway|string|null  $gateway
     */
    public function __construct($gateway = null)
    {
        $this->setGateway($gateway);
    }

    /**
     * Create a new model and return.
     *
     * @return Model
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayNotAvailableException
     * @throws \Potelo\MultiPayment\Exceptions\ModelAttributeValidationException
     */
    public function create(): Model
    {
        $this->model->save($this->gateway, true);
        return $this->model;
    }

    /**
     * Returns the model instance.
     *
     * @return Model
     */
    public function get(): Model
    {
        return $this->model;
    }

    /**
     * Set the gateway.
     *
     * @param  Gateway|string|null  $gateway
     */
    public function setGateway($gateway = null): self
    {
        $this->gateway = ConfigurationHelper::resolveGateway($gateway);

        return $this;
    }

    /**
     * Set the gateway adicional options.
     *
     * @param  array  $gatewayAdicionalOptions
     *
     * @return $this
     */
    public function setGatewayAdicionalOptions(array $gatewayAdicionalOptions): self
    {
        $this->model->gatewayAdicionalOptions = $gatewayAdicionalOptions;

        return $this;
    }
}