<?php

namespace Potelo\MultiPayment\Gateways;

use Moip\Moip;
use Carbon\Carbon;
use Moip\Auth\BasicAuth;
use Moip\Resource\Holder;
use Moip\Resource\Payment;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Contracts\Gateway;
use Potelo\MultiPayment\Exceptions\GatewayException;
use Potelo\MultiPayment\Exceptions\PropertyValidationException;

class MoipGateway implements Gateway
{

    /**
     * Moip instance.
     *
     * @var Moip
     */
    protected Moip $moip;

    /**
     * Initialize Moip gateway.
     */
    private function init()
    {
        $this->moip = new Moip(
            new BasicAuth(
                config('multi-payment.gateways.moip.api_token'),
                config('multi-payment.gateways.moip.api_key')
            ),
            $this->getMoipEndpoint()
        );
    }

    /**
     * @inheritDoc
     */
    public function createInvoice(Invoice $invoice): Invoice
    {
        if ($invoice->paymentMethod == Invoice::PAYMENT_METHOD_PIX) {
            throw new GatewayException('Moip gateway does not support pix payment method.');
        }
        $this->init();

        $order = $this->moip->orders()->setOwnId(uniqid());
        foreach ($invoice->items as $item) {
            $order->addItem($item->description, $item->quantity, "", $item->price);
        }
        $customer = $this->moip->customers()->get($invoice->customer->id);
        $order->setCustomer($customer)->create();
        $holder = $this->createHolder($invoice->customer);
        $payment = $order->payments();

        if ($invoice->paymentMethod == Invoice::PAYMENT_METHOD_CREDIT_CARD) {
            if (!is_null($invoice->creditCard->token)) {
                $payment->setCreditCardHash($invoice->creditCard->token, $holder);
            } else {
                $payment->setCreditCard(
                    $invoice->creditCard->month,
                    substr($invoice->creditCard->year, -2),
                    $invoice->creditCard->number,
                    $invoice->creditCard->cvv,
                    $holder
                )
                    ->setInstallmentCount(1)
                    ->setStatementDescriptor('Nova cobrança');
            }
        } elseif ($invoice->paymentMethod == Invoice::PAYMENT_METHOD_BANK_SLIP) {
            $logoUri = '';
            $expirationDate = $invoice->bankSlip->expirationDate->format('Y-m-d');
            $instructionLines = ['', '', ''];
            $payment->setBoleto($expirationDate, $logoUri, $instructionLines);
        }
        try {
            $payment->execute();
        } catch (\Exception $exception) {
            throw new GatewayException($exception->getMessage());
        }

        if (config('multi-payment.gateways.moip.sandbox')) {
            $payment->authorize();
        }

        $invoice->id = $payment->getId();
        $invoice->gateway = 'moip';

        $invoice->status = $this->moipStatusToMultiPayment($payment->getStatus());
        $invoice->amount = $payment->getAmount()->total;
        $invoice->orderId = $payment->getOrder()->getId();

        if ($invoice->paymentMethod == Invoice::PAYMENT_METHOD_CREDIT_CARD) {
            $invoice->creditCard->id = $payment->getFundingInstrument()->creditCard->id;
            $invoice->creditCard->brand = $payment->getFundingInstrument()->creditCard->brand;
            $invoice->creditCard->lastDigits = $payment->getFundingInstrument()->creditCard->last4;
        } elseif ($invoice->paymentMethod == Invoice::PAYMENT_METHOD_BANK_SLIP) {
            $invoice->bankSlip->url = $payment->getHrefPrintBoleto();
            $invoice->bankSlip->number = $payment->getLineCodeBoleto();
        }

        $invoice->url = $payment->getLinks()->getSelf();
        $invoice->fee = $payment->getOrder()->getAmountFees() ?? null;
        $invoice->original = $payment;
        $invoice->createdAt = Carbon::createFromFormat('Y-m-d H:i:s', $payment->getCreatedAt()->format('Y-m-d H:i:s'));

        return $invoice;
    }

    /**
     * @inheritDoc
     * @throws PropertyValidationException
     */
    public function createCustomer(Customer $customer): Customer
    {
        if (is_null($customer->name)) {
            throw new PropertyValidationException('The Costumer name is required.');
        }
        if (is_null($customer->email)) {
            throw new PropertyValidationException('The Costumer email is required.');
        }
        if (is_null($customer->taxDocument)) {
            throw new PropertyValidationException('The Costumer taxDocument is required.');
        }

        $this->init();
        $customerData = $customer->toArrayWithoutEmpty();
        $moipCustomer = $this->moip->customers()->setOwnId(uniqid())
            ->setFullname($customerData['name'])
            ->setEmail($customerData['email'])
            ->setTaxDocument($customerData['tax_document']);
        if (array_key_exists('phone_area', $customerData) &&
            array_key_exists('phone_number', $customerData)
        ) {
            $moipCustomer->setPhone(
                $customerData['phone_area'],
                $customerData['phone_number'],
                $customerData['phone_country_code'] ?? 55
            );
        }
        if (array_key_exists('birthDate', $customerData)) {
            $moipCustomer->setBirthDate($customerData['birthDate']);
        }
        if (array_key_exists('address', $customerData)) {
            $moipCustomer->addAddress(
                $customerData['address']['type'],
                $customerData['address']['street'],
                $customerData['address']['number'],
                $customerData['address']['district'],
                $customerData['address']['city'],
                $customerData['address']['state'],
                $customerData['address']['zip_code'],
                $customerData['address']['complement']
            );
        }
        try {
            $moipCustomer = $moipCustomer->create();
        } catch (\Exception $exception) {
            throw new GatewayException($exception->getMessage());
        }
        $customer->id = $moipCustomer->getId();
        $customer->createdAt = Carbon::now();
        $customer->original = $customer;
        $customer->gateway = 'moip';
        return $customer;
    }

    /**
     * Get Moip endpoint depending on the environment.
     *
     * @return string
     */
    private function getMoipEndpoint(): string
    {
        return config('multi-payment.environment') != 'production'
            ? Moip::ENDPOINT_SANDBOX
            : Moip::ENDPOINT_PRODUCTION;
    }

    /**
     * Convert Moip status to MultiPayment status.
     *
     * @param $moipStatus
     *
     * @return string
     */
    private static function moipStatusToMultiPayment($moipStatus): string
    {
        switch ($moipStatus) {
            case Payment::STATUS_AUTHORIZED:
                return Invoice::STATUS_AUTHORIZED;
            case Payment::STATUS_CANCELLED:
                return Invoice::STATUS_CANCELLED;
            case Payment::STATUS_REFUNDED:
                return Invoice::STATUS_REFUNDED;
//            case Payment::STATUS_WAITING:
//            case Payment::STATUS_SETTLED:
//            case Payment::STATUS_IN_ANALYSIS:
//            case Payment::STATUS_CREATED:
//            case Payment::STATUS_PRE_AUTHORIZED:
            default:
                return Invoice::STATUS_PENDING;
        }
    }

    /**
     * Create holder by costumer data
     *
     * @param  Customer  $customer
     *
     * @return Holder
     */
    private function createHolder(Customer $customer): Holder
    {
        $holder = $this->moip->holders()
            ->setFullname('')
            ->setBirthDate('')
            ->setTaxDocument('')
            ->setPhone('', '', '');

        if (!is_null($customer->name)) {
            $holder->setFullname($customer->name);
        }
        if (!is_null($customer->birthDate)) {
            $holder->setBirthDate($customer->birthDate);
        }
        if (!is_null($customer->taxDocument)) {
            $holder->setTaxDocument($customer->taxDocument);
        }
        if (!is_null($customer->phoneArea) && !is_null($customer->phoneNumber)) {
            $holder->setPhone(
                $customer->phoneArea,
                $customer->phoneNumber,
                $customer->phoneCountryCode ?? 55
            );
        }
        if (!is_null($customer->address)) {
            $address = $customer->address;
            $holder->setAddress(
                $address->type,
                $address->street,
                $address->number,
                $address->district,
                $address->city,
                $address->state,
                $address->zipCode,
                $address->complement
            );
        }
        return $holder;
    }
}
