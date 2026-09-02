<?php

namespace Potelo\MultiPayment\Models;

use Potelo\MultiPayment\Enums\Capability;
use Potelo\MultiPayment\Contracts\GatewayContract;
use Potelo\MultiPayment\Contracts\AcceptsUnknownValue;
use Potelo\MultiPayment\Helpers\ConfigurationHelper;
use Potelo\MultiPayment\Exceptions\GatewayException;
use Potelo\MultiPayment\Exceptions\ConfigurationException;
use Potelo\MultiPayment\Exceptions\GatewayNotAvailableException;
use Potelo\MultiPayment\Exceptions\UnsupportedOperationException;
use Potelo\MultiPayment\Exceptions\ModelAttributeValidationException;

/**
 * @property array $gatewayAdicionalOptions Obsoleto desde 2026-09-02, use $gatewayOptions. Alias
 *                                          que lê e escreve o mesmo array, com aviso de deprecação.
 */
abstract class Model implements \JsonSerializable
{
    /**
     * Propriedades convertidas para enum ao serem escritas, por nome. O valor é a classe do
     * enum ou, para uma lista de enums, a classe dentro de um array (`[PaymentMethod::class]`).
     * Essas propriedades são declaradas `protected` no model e passam pelos métodos mágicos,
     * que aceitam a string do valor ou o próprio caso do enum e devolvem sempre o enum.
     *
     * @var array<string, class-string<\BackedEnum>|array{0: class-string<\BackedEnum>}>
     */
    protected const ENUM_CASTS = [];

    /**
     * Propriedades `protected` que o model lê e escreve pelos próprios métodos mágicos, fora de
     * `ENUM_CASTS`, e que `fill()`, `toArray()` e `fillableKeys()` tratam como públicas.
     *
     * @var string[]
     */
    protected const MAGIC_PROPERTIES = [];

    /**
     * Capability que o gateway precisa declarar para operar este model, ou nulo quando qualquer
     * gateway serve. `requiredCapabilities()` a devolve junto com as derivadas dos atributos.
     *
     * @var Capability|null
     */
    protected const REQUIRED_CAPABILITY = null;

    /**
     * Opções extras enviadas direto ao gateway. Cada driver mescla este array ao payload que
     * monta a partir do model, e as chaves daqui sobrepõem as geradas.
     *
     * @var array
     */
    public array $gatewayOptions = [];

    /**
     * Devolve uma propriedade de enum (ver `ENUM_CASTS`) ou resolve a leitura do nome antigo
     * `gatewayAdicionalOptions` para `gatewayOptions`.
     *
     * Devolve por referência para que `$model->gatewayAdicionalOptions['chave'] = 'valor'`
     * continue alterando o array, como fazia quando a propriedade existia.
     *
     * @param  string  $name
     * @return mixed
     */
    public function &__get(string $name): mixed
    {
        if (isset(static::ENUM_CASTS[$name])) {
            return $this->{$name};
        }

        if ($name === 'gatewayAdicionalOptions') {
            self::warnGatewayAdicionalOptionsDeprecated();

            return $this->gatewayOptions;
        }

        trigger_error('Undefined property: ' . static::class . '::$' . $name, E_USER_WARNING);
        $undefined = null;

        return $undefined;
    }

    /**
     * Escreve numa propriedade de enum (ver `ENUM_CASTS`), convertendo string no caso do enum,
     * ou resolve a escrita no nome antigo `gatewayAdicionalOptions` para `gatewayOptions`.
     * Qualquer outro nome segue o comportamento padrão do PHP (propriedade dinâmica).
     *
     * @param  string  $name
     * @param  mixed  $value
     * @return void
     * @throws ModelAttributeValidationException
     */
    public function __set(string $name, mixed $value): void
    {
        if (isset(static::ENUM_CASTS[$name])) {
            $this->{$name} = $this->castToEnum($name, $value);

            return;
        }

        if ($name === 'gatewayAdicionalOptions') {
            self::warnGatewayAdicionalOptionsDeprecated();
            $this->gatewayOptions = $value;

            return;
        }

        $this->{$name} = $value;
    }

    /**
     * Mantém `isset()` e `empty()` funcionando sobre as propriedades de enum e sobre o nome
     * antigo `gatewayAdicionalOptions`.
     *
     * @param  string  $name
     * @return bool
     */
    public function __isset(string $name): bool
    {
        if (isset(static::ENUM_CASTS[$name])) {
            return isset($this->{$name});
        }

        return $name === 'gatewayAdicionalOptions';
    }

    /**
     * Emite o aviso de deprecação do nome antigo `gatewayAdicionalOptions`.
     *
     * @return void
     */
    private static function warnGatewayAdicionalOptionsDeprecated(): void
    {
        trigger_error(
            'Model::$gatewayAdicionalOptions está obsoleto desde 2026-09-02; use $gatewayOptions',
            E_USER_DEPRECATED
        );
    }

    /**
     * Converte o valor escrito numa propriedade de `ENUM_CASTS` para o enum declarado.
     *
     * Aceita o caso do enum, a string do valor ou nulo; numa lista, um array desses. Enum que
     * implementa `AcceptsUnknownValue` recebe a string desconhecida e decide o que fazer; nos
     * demais, string fora do enum lança `ModelAttributeValidationException` com os valores
     * aceitos.
     *
     * @param  string  $property
     * @param  mixed  $value
     * @return \BackedEnum|\BackedEnum[]|null
     * @throws ModelAttributeValidationException
     */
    private function castToEnum(string $property, mixed $value): mixed
    {
        $cast = static::ENUM_CASTS[$property];

        if (!is_array($cast)) {
            return $this->castScalarToEnum($property, $cast, $value);
        }

        if (is_null($value)) {
            return null;
        }

        if (!is_array($value)) {
            throw ModelAttributeValidationException::invalid(
                static::getClassName(),
                $property,
                "{$property} must be an array"
            );
        }

        return array_map(fn ($item) => $this->castScalarToEnum($property, $cast[0], $item), $value);
    }

    /**
     * Converte um único valor no caso do enum informado.
     *
     * @param  string  $property
     * @param  class-string<\BackedEnum>  $enumClass
     * @param  mixed  $value
     * @return \BackedEnum|null
     * @throws ModelAttributeValidationException
     */
    private function castScalarToEnum(string $property, string $enumClass, mixed $value): ?\BackedEnum
    {
        if (is_null($value) || $value instanceof $enumClass) {
            return $value;
        }

        $accepted = implode(', ', array_column($enumClass::cases(), 'value'));

        if (!is_string($value)) {
            throw ModelAttributeValidationException::invalid(
                static::getClassName(),
                $property,
                "{$property} must be one of: {$accepted}"
            );
        }

        if (is_subclass_of($enumClass, AcceptsUnknownValue::class)) {
            $gateway = property_exists($this, 'gateway') ? $this->gateway : null;

            return $enumClass::fromValue($value, $gateway);
        }

        return $enumClass::tryFrom($value) ?? throw ModelAttributeValidationException::invalid(
            static::getClassName(),
            $property,
            "{$property} must be one of: {$accepted}"
        );
    }

    /**
     * Converte enum em valor de string, inclusive dentro de uma lista; qualquer outro valor
     * passa intacto.
     *
     * @param  mixed  $value
     * @return mixed
     */
    private static function enumToValue(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if (is_array($value)) {
            return array_map(
                static fn ($item) => $item instanceof \BackedEnum ? $item->value : $item,
                $value
            );
        }

        return $value;
    }

    /**
     * Create a new instance of the model with an array of attributes.
     *
     * @param  array  $data
     * @param  string|GatewayContract|null  $gateway
     * @param  string|null  $idempotencyKey  idempotency key of the operation; null disables deduplication
     *
     * @return void
     * @throws GatewayException
     * @throws GatewayNotAvailableException
     * @throws ModelAttributeValidationException
     */
    public function create(array $data, $gateway = null, ?string $idempotencyKey = null): void
    {
        $this->fill($data);
        $this->save($gateway, true, $idempotencyKey);
    }

    /**
     * Salva o model no gateway: `create{Model}` sem `id`, `update{Model}` com `id` (sem
     * validação). A chave de idempotência vai para essa operação; o cliente salvo antes de uma
     * fatura ou assinatura recebe a chave derivada `{chave}:customer`. Driver que declara a
     * capability sem ter o método de despacho lança `ConfigurationException` antes da rede.
     *
     * @param  string|GatewayContract|null  $gateway
     * @param  bool  $validate
     * @param  string|null  $idempotencyKey  chave de idempotência da operação; nula não deduplica
     *
     * @return void
     * @throws GatewayException|GatewayNotAvailableException|ModelAttributeValidationException|ConfigurationException
     * @throws UnsupportedOperationException
     */
    public function save(GatewayContract|string|null $gateway = null, bool $validate = true, ?string $idempotencyKey = null): void
    {
        $class = $this->getClassName();
        if (property_exists($this, 'id') && !empty($this->id)) {
            $method = 'update';
            $validate = false;
        } else {
            $method = 'create';
        }
        $method = $method . $class;
        $gateway = $this->gatewayForSave($gateway);

        if ($validate) {
            $this->validate();
        }
        $gatewayClass = ConfigurationHelper::resolveGateway($gateway);
        $this->assertGatewaySupports($gatewayClass);
        if (!method_exists($gatewayClass, $method)) {
            throw ConfigurationException::GatewayMethodNotFound(get_class($gatewayClass), $method);
        }
        $gatewayClass->$method($this, $idempotencyKey);
    }

    /**
     * Gateway que `save()` usa: no update (com `id`), o gateway gravado no model prevalece sobre
     * o informado; na criação, o informado prevalece e o do model é o segundo candidato. Nulo
     * deixa `ConfigurationHelper` escolher o default.
     *
     * @param  GatewayContract|string|null  $gateway
     * @return GatewayContract|string|null
     */
    protected function gatewayForSave(GatewayContract|string|null $gateway): GatewayContract|string|null
    {
        $own = property_exists($this, 'gateway') && !empty($this->gateway) ? $this->gateway : null;

        if (property_exists($this, 'id') && !empty($this->id)) {
            return $own ?? $gateway;
        }

        return $gateway ?? $own;
    }

    /**
     * Capabilities que o gateway precisa declarar para a operação em curso sobre este model:
     * `REQUIRED_CAPABILITY`, quando definida, mais as que o model derivar dos seus atributos.
     *
     * @return Capability[]
     */
    public function requiredCapabilities(): array
    {
        return is_null(static::REQUIRED_CAPABILITY) ? [] : [static::REQUIRED_CAPABILITY];
    }

    /**
     * Lança `UnsupportedOperationException` na primeira capability de `requiredCapabilities()`
     * que o gateway não declara, antes de qualquer requisição.
     *
     * @param  GatewayContract  $gateway
     * @return void
     * @throws UnsupportedOperationException
     */
    protected function assertGatewaySupports(GatewayContract $gateway): void
    {
        foreach ($this->requiredCapabilities() as $capability) {
            if (!$gateway->supports($capability)) {
                throw UnsupportedOperationException::forGateway($gateway, $capability);
            }
        }
    }

    /**
     * Validate the model.
     *
     * @param  array  $attributes
     * @param  array  $excludedAttributes
     *
     * @return void
     * @throws ModelAttributeValidationException
     */
    public function validate(array $attributes = [], array $excludedAttributes = []): void
    {
        if (empty($attributes)) {
            $attributes = array_keys(get_class_vars(get_class($this)));
        }
        $attributes = array_diff_key($attributes, array_flip($excludedAttributes));
        foreach ($attributes as $attribute) {
            $validateAttributeMethod = 'validate' . ucfirst($attribute). 'Attribute';
            if (property_exists($this, $attribute) && !empty($this->$attribute) && method_exists($this, $validateAttributeMethod)) {
                $this->$validateAttributeMethod();
            }
        }
        $this->attributesExtraValidation($attributes);
    }

    /**
     * Model attributes validation for specific cases if necessary.
     * This method is called after the validation of the model attributes.
     * Need to be implemented in the child class.
     *
     * @param  array  $attributes
     *
     * @return void
     * @throws ModelAttributeValidationException
     */
    protected function attributesExtraValidation(array $attributes): void
    {
        //
    }

    /**
     * Fill the model with an array of attributes. Chave em `snake_case` vira a propriedade em
     * `camelCase`; valor de propriedade de enum (ver `ENUM_CASTS`) pode vir como string.
     *
     * Chave que não corresponde a nenhuma propriedade do model lança
     * `ModelAttributeValidationException` com a lista das chaves aceitas, exceto chave com
     * prefixo `gateway_` (ou `gateway` em `camelCase`; o conteúdo de `gateway_options` é livre
     * e chega inteiro ao gateway).
     * Com `multi-payment.strict_fill` em falso, a chave desconhecida é ignorada em silêncio.
     *
     * @param  array  $data
     *
     * @return void
     * @throws ModelAttributeValidationException
     */
    public function fill(array $data): void
    {
        foreach ($data as $key => $value) {
            $property = lcfirst(str_replace('_', '', ucwords($key, '_')));
            if ($property === 'gatewayAdicionalOptions') {
                self::warnGatewayAdicionalOptionsDeprecated();
                $property = 'gatewayOptions';
            }
            if (!static::isFillableProperty($property)) {
                if (!str_starts_with($property, 'gateway') && ConfigurationHelper::strictFill()) {
                    throw ModelAttributeValidationException::unknownAttribute(
                        static::getClassName(),
                        (string) $key,
                        static::fillableKeys()
                    );
                }
                continue;
            }
            $this->{$property} = isset(static::ENUM_CASTS[$property]) ? $this->castToEnum($property, $value) : $value;
        }
    }

    /**
     * Chaves que `fill()` aceita, em `snake_case`: as propriedades públicas do model, as de
     * enum (ver `ENUM_CASTS`) e as de `MAGIC_PROPERTIES`, na ordem de declaração.
     *
     * @return string[]
     */
    public static function fillableKeys(): array
    {
        $keys = [];
        $reflect = new \ReflectionClass(static::class);
        foreach ($reflect->getProperties(\ReflectionProperty::IS_PUBLIC | \ReflectionProperty::IS_PROTECTED) as $prop) {
            $name = $prop->getName();
            if ($prop->isStatic() || ($prop->isProtected() && !static::isMagicProperty($name))) {
                continue;
            }
            $keys[] = self::snakeCase($name);
        }

        return $keys;
    }

    /**
     * Diz se `fill()` pode escrever na propriedade: ela existe no model e é pública ou protegida
     * (propriedade privada é estado interno e conta como chave desconhecida).
     *
     * @param  string  $property
     * @return bool
     */
    private static function isFillableProperty(string $property): bool
    {
        if (!property_exists(static::class, $property)) {
            return false;
        }

        return !(new \ReflectionProperty(static::class, $property))->isPrivate();
    }

    /**
     * Diz se a propriedade `protected` é exposta pelos métodos mágicos do model (enum de
     * `ENUM_CASTS` ou nome em `MAGIC_PROPERTIES`).
     *
     * @param  string  $name
     * @return bool
     */
    protected static function isMagicProperty(string $name): bool
    {
        return isset(static::ENUM_CASTS[$name]) || in_array($name, static::MAGIC_PROPERTIES, true);
    }

    /**
     * Converte o nome de uma propriedade em `camelCase` para a chave em `snake_case`.
     *
     * @param  string  $name
     * @return string
     */
    private static function snakeCase(string $name): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
    }

    /**
     * Convert the model instance to an array. Chave em `snake_case`; propriedade de enum sai
     * como o valor de string do enum; propriedade de `MAGIC_PROPERTIES` sai como as públicas.
     *
     * @return array
     */
    public function toArray(): array
    {
        $array = [];
        $reflect = new \ReflectionClass($this);
        $props = $reflect->getProperties(\ReflectionProperty::IS_PUBLIC | \ReflectionProperty::IS_PROTECTED);
        foreach ($props as $prop) {
            $name = $prop->getName();
            if ($prop->isProtected() && !static::isMagicProperty($name)) {
                continue;
            }
            if (!empty($this->{$name})) {
                $array[self::snakeCase($name)] = self::enumToValue($this->{$name});
            }

        }
        return $array;
    }

    /**
     * Serializa o model para `json_encode()` com o nome da propriedade em `camelCase` como
     * chave, incluindo as propriedades de enum, que saem como valor de string.
     *
     * @return array
     */
    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }

    /**
     * Return the class name of the model without namespace.
     *
     * @return string
     */
    protected static function getClassName(): string
    {
        return substr(strrchr(get_called_class(), '\\'), 1);
    }

    /**
     * Get the model instance by id in the gateway.
     *
     * @param  string|GatewayContract|null  $gateway
     *
     * @return static
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     * @throws UnsupportedOperationException
     */
    public function get(GatewayContract|string|null $gateway = null): static
    {
        $method = 'get' . static::getClassName();
        $gateway = ConfigurationHelper::resolveGateway($gateway);
        $this->assertGatewaySupports($gateway);
        if (!method_exists($gateway, $method)) {
            throw ConfigurationException::GatewayMethodNotFound(get_class($gateway), $method);
        }
        return $gateway->$method($this);
    }

    /**
     * Delete the model instance by id in the gateway.
     *
     * @param  \Potelo\MultiPayment\Contracts\GatewayContract|string|null  $gateway
     * @param  string|null  $idempotencyKey  idempotency key of the operation; null disables deduplication
     * @return void
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     * @throws UnsupportedOperationException
     */
    public function delete(GatewayContract|string|null $gateway = null, ?string $idempotencyKey = null): void
    {
        $method = 'delete' . static::getClassName();
        $gateway = ConfigurationHelper::resolveGateway($gateway);
        $this->assertGatewaySupports($gateway);
        if (!method_exists($gateway, $method)) {
            throw ConfigurationException::GatewayMethodNotFound(get_class($gateway), $method);
        }
        $gateway->$method($this, $idempotencyKey);
    }

    /**
     * Refresh the model instance with the latest data from the gateway.
     */
    public function refresh(GatewayContract|string|null $gateway = null): static
    {
        $gateway = ConfigurationHelper::resolveGateway($gateway);
        return $this->get($gateway);
    }
}
