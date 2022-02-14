<?php

namespace Potelo\MultiPayment\Gateways;

use Moip\Moip;
use Carbon\Carbon;
use Moip\Auth\BasicAuth;
use Moip\Resource\Holder;
use Moip\Resource\Payment;
use Potelo\MultiPayment\Config\Config;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\Address;
use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Models\BankSlip;
use Moip\Exceptions\ValidationException;
use Potelo\MultiPayment\Contracts\Gateway;
use Potelo\MultiPayment\Exceptions\GatewayException;

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
                Config::get('gateways.moip.api_token'),
                Config::get('gateways.moip.api_key')
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
            if (!empty($invoice->creditCard->token)) {
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
            $expirationDate = !empty($invoice->expirationDate)
                ? $invoice->expirationDate->format('Y-m-d')
                : Carbon::now()->format('Y-m-d');
            $instructionLines = ['', '', ''];
            $payment->setBoleto($expirationDate, $logoUri, $instructionLines);
        }
        try {
            $payment->execute();
        } catch (ValidationException $exception) {
            throw new GatewayException('Error creating invoice: ' . $exception->getMessage(), $exception->getErrors());
        } catch (\Exception $exception) {
            throw new GatewayException('Error creating invoice: ' . $exception->getMessage());
        }

        if (Config::get('gateways.moip.sandbox')) {
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
            $invoice->bankSlip = new BankSlip();
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
     */
    public function createCustomer(Customer $customer): Customer
    {
        $this->init();
        $customerData = $customer->toArray();
        $moipCustomer = $this->moip->customers()->setOwnId(uniqid())
            ->setFullname(!empty($customerData['name']) ? $customerData['name'] : null)
            ->setEmail(!empty($customerData['email']) ? $customerData['email'] : null)
            ->setTaxDocument(!empty($customerData['tax_document']) ? $customerData['tax_document'] : null);
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
                !empty($customerData['address']['type']) ? $customerData['address']['type'] : null,
                !empty($customerData['address']['street']) ? $customerData['address']['street'] : null,
                !empty($customerData['address']['number']) ? $customerData['address']['number'] : 'S/N',
                !empty($customerData['address']['district']) ? $customerData['address']['district'] : null,
                !empty($customerData['address']['city']) ? $customerData['address']['city'] : null,
                !empty($customerData['address']['state']) ? $customerData['address']['state'] : null,
                !empty($customerData['address']['zip_code']) ? $customerData['address']['zip_code'] : null,
                !empty($customerData['address']['complement']) ? $customerData['address']['complement'] : null,
            );
        }
        try {
            $moipCustomer = $moipCustomer->create();
        } catch (ValidationException $exception) {
            throw new GatewayException('Error creating customer: ' . $exception->getMessage(), $exception->getErrors());
        } catch (\Exception $exception) {
            throw new GatewayException('Error creating customer: ' . $exception->getMessage());
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
        return Config::get('environment') != 'production'
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

        if (!empty($customer->name)) {
            $holder->setFullname($customer->name);
        }
        if (!empty($customer->birthDate)) {
            $holder->setBirthDate($customer->birthDate);
        }
        if (!empty($customer->taxDocument)) {
            $holder->setTaxDocument($customer->taxDocument);
        }
        if (!empty($customer->phoneArea) && !empty($customer->phoneNumber)) {
            $holder->setPhone(
                $customer->phoneArea,
                $customer->phoneNumber,
                $customer->phoneCountryCode ?? 55
            );
        }
        if (!empty($customer->address)) {
            $address = $customer->address;
            $holder->setAddress(
                !empty($address->type) ? $address->type : Address::TYPE_BILLING,
                !empty($address->street) ? $address->street : null,
                !empty($address->number) ? $address->number : null,
                !empty($address->district) ? $address->district : null,
                !empty($address->city) ? $address->city : null,
                !empty($address->state) ? $address->state : null,
                !empty($address->zipCode) ? $address->zipCode : null,
                !empty($address->complement) ? $address->complement : null,
            );
        }
        return $holder;
    }
}
