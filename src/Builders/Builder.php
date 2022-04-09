<?php

namespace Potelo\MultiPayment\Builders;

use Potelo\MultiPayment\Models\Model;
use Illuminate\Support\Facades\Config;
use Potelo\MultiPayment\Contracts\Gateway;
use Potelo\MultiPayment\Helpers\ConfigurationHelper;
use Potelo\MultiPayment\Exceptions\GatewayFallbackException;
use Potelo\MultiPayment\Exceptions\GatewayNotAvailableException;

class Builder
{
    protected bool $useFallback = false;
    private ?Gateway $fallbackGateway = null;
    protected Gateway $gateway;
    protected Model $model;

    /**
     * Builder constructor.
     *
     * @param  Gateway|string|null  $gateway
     *
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException
     */
    public function __construct($gateway = null)
    {
        $this->gateway = ConfigurationHelper::resolveGateway($gateway);
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
        try {
            $beforeSaveModel = clone $this->model;
            $this->model->save($this->fallbackGateway ?? $this->gateway);
            return $this->model;
        } catch (GatewayNotAvailableException $e) {
            if (Config::get('multi-payment.fallback') && $this->useFallback) {
                $this->fallbackGateway = ConfigurationHelper::getNextGateway($this->fallbackGateway ?? $this->gateway);
                if (get_class($this->fallbackGateway) !== get_class($this->gateway)) {
                    $this->model = clone $beforeSaveModel;
                    return $this->create();
                }
                throw new GatewayFallbackException('All gateways failed');
            }
            throw $e;
        }
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
}