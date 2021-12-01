<?php

namespace Potelo\MultiPayment\Gateways;

use Moip\Moip;
use Moip\Auth\BasicAuth;
use Moip\Resource\Payment;
use InvalidArgumentException;
use Potelo\MultiPayment\MultiPayment;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\BankSlip;
use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Contracts\Gateway;
use Potelo\MultiPayment\Models\InvoiceItem;

class MoipGateway implements Gateway
{

    /**
     * Moip instance.
     *
     * @var Moip
     */
    protected $moip;

    /**
     * Initialize Moip gateway.
     */
    private function init()
    {
        $this->moip = new Moip(new BasicAuth(config('multi-payment.gateways.moip.api_token'), config('multi-payment.gateways.moip.api_key')), $this->getMoipEndpoint());
    }

    /**
     * Get Moip endpoint depending on the environment.
     *
     * @return string
     */
    private function getMoipEndpoint()
    {
        return config('multi-payment.environment') != 'production' ? Moip::ENDPOINT_SANDBOX : Moip::ENDPOINT_PRODUCTION;
    }

    /**
     * @inheritDoc
     */
    public function createInvoice(Invoice $invoice): Invoice
    {
        $this->init();

        $order = $this->moip->orders()->setOwnId(uniqid());
        foreach ($invoice->items as $item) {
            $order->addItem($item->description, $item->quantity, "", $item->price);
        }

        $customer = $this->moip->customers()->get($invoice->customer->id);
        $order->setCustomer($customer)->create();
        $holder = $this->createHolderByCustomer($customer);
        $payment = $order->payments();

        if ($invoice->paymentMethod == MultiPayment::PAYMENT_METHOD_CREDIT_CARD) {
            if (! is_null($invoice->creditCard->token)) {
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
        } elseif ($invoice->paymentMethod == MultiPayment::PAYMENT_METHOD_BANK_SLIP) {
            $logoUri = '';
            $expirationDate = $invoice->bankSlip->expirationDate;
            $instructionLines = ['', '', ''];
            $payment->setBoleto($expirationDate, $logoUri, $instructionLines);
        }
        $payment->execute();

        if (config('multi-payment.gateways.moip.sandbox')) {
            $payment->authorize();
        }

        $invoice->id = $payment->getId();
        $invoice->gateway = 'moip';

        $invoice->status = $this->moipStatusToMultiPayment($payment->getStatus());
        $invoice->amount = $payment->getAmount()->total;
        $invoice->orderId = $payment->getOrder()->getId();

        if ($invoice->paymentMethod == MultiPayment::PAYMENT_METHOD_BANK_SLIP) {
            $invoice->bankSlip->url = $payment->getHrefPrintBoleto();
            $invoice->bankSlip->number = $payment->getLineCodeBoleto();
        }
        $invoice->url = $payment->getLinks()->getSelf();
        $invoice->fee = $payment->getOrder()->getAmountFees() ?? null;
        $invoice->original = $payment;
        $invoice->createdAt = $payment->getCreatedAt();

        return $invoice;
    }

    /**
     * Create a MultiPayment invoice from a Moip payment.
     *
     * @param  Payment  $payment
     * @return Invoice
     */
    private function createMultiPaymentInvoice(Payment $payment): Invoice
    {
        $invoice = new Invoice();
        $invoice->id = $payment->getId();
        $invoice->gateway = 'moip';

        $invoice->status = $this->moipStatusToMultiPayment($payment->getStatus());
        $invoice->amount = $payment->getAmount()->total;
        $invoice->orderId = $payment->getOrder()->getId();
        $invoice->customerId = $payment->getOrder()->getCustomer()->getId();
        $invoice->paymentMethod = $payment->getFundingInstrument()->method == \Moip\Resource\Payment::METHOD_BOLETO
            ? MultiPayment::PAYMENT_METHOD_BANK_SLIP
            : MultiPayment::PAYMENT_METHOD_CREDIT_CARD;

        $itensTemp = [];
        foreach ($payment->getOrder()->getItemIterator() as $item) {
            if (is_array($item)) {
                $item = (object) $item;
            }
            $itensTemp[] = new InvoiceItem($item->product, $item->price, $item->quantity);
        }
        $invoice->items = $itensTemp;

        if ($payment->getFundingInstrument()->method == \Moip\Resource\Payment::METHOD_BOLETO) {
            $invoice->bankSlip = new BankSlip(
                $payment->getExpirationDateBoleto(),
                $payment->getHrefPrintBoleto(),
                $payment->getLineCodeBoleto(),
                null,
                null
            );
        }
        $invoice->url = $payment->getLinks()->getSelf();
        $invoice->fee = $payment->getOrder()->getAmountFees() ?? null;
        $invoice->original = $payment;
        $invoice->createdAt = $payment->getCreatedAt();
        return $invoice;
    }

    /**
     * Convert Moip status to MultiPayment status.
     *
     * @param $moipStatus
     * @return string
     */
    private static function moipStatusToMultiPayment($moipStatus)
    {
        switch ($moipStatus) {
            case \Moip\Resource\Payment::STATUS_AUTHORIZED:
                return Invoice::STATUS_AUTHORIZED;
            case \Moip\Resource\Payment::STATUS_CANCELLED:
                return Invoice::STATUS_CANCELLED;
            case \Moip\Resource\Payment::STATUS_REFUNDED:
                return Invoice::STATUS_REFUNDED;
//            case \Moip\Resource\Payment::STATUS_WAITING:
//            case \Moip\Resource\Payment::STATUS_SETTLED:
//            case \Moip\Resource\Payment::STATUS_IN_ANALYSIS:
//            case \Moip\Resource\Payment::STATUS_CREATED:
//            case \Moip\Resource\Payment::STATUS_PRE_AUTHORIZED:
            default:
                return Invoice::STATUS_PENDING;
        }
    }

    /**
     * @inheritDoc
     */
    public function createCustomer(Customer $customer): Customer
    {
        if (is_null($customer->name)) {
            throw new InvalidArgumentException('The name of Costumer is required.');
        }
        if (is_null($customer->email)) {
            throw new InvalidArgumentException('The email of Costumer is required.');
        }
        if (is_null($customer->taxDocument)) {
            throw new InvalidArgumentException('The taxDocument of Costumer is required.');
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
            $moipCustomer->setPhone($customerData['phone_area'], $customerData['phone_number'], $customerData['phone_country_code'] ?? 55);
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
        $moipCustomer = $moipCustomer->create();
        $customer->id = $moipCustomer->getId();
        $customer->createdAt = new \DateTimeImmutable();
        $customer->original = $customer;
        $customer->gateway = 'moip';
        return $customer;
    }

    /**
     * Create holder by costumer data
     *
     * @param  \Moip\Resource\Customer  $customer
     * @return \Moip\Resource\Holder
     */
    private function createHolderByCustomer(\Moip\Resource\Customer $customer): \Moip\Resource\Holder
    {
        $holder = $this->moip->holders()
            ->setFullname('')
            ->setBirthDate('')
            ->setTaxDocument('')
            ->setPhone('', '', '');

        if (!is_null($customer->getFullname())) {
            $holder->setFullname($customer->getFullname());
        }
        if (!is_null($customer->getBirthDate())) {
            $holder->setBirthDate($customer->getBirthDate());
        }
        if (!is_null($customer->getTaxDocumentNumber())) {
            $holder->setTaxDocument($customer->getTaxDocumentNumber());
        }
        if (!is_null($customer->getPhoneAreaCode()) && !is_null($customer->getPhoneNumber()) && !is_null($customer->getPhoneCountryCode())) {
            $holder->setPhone($customer->getPhoneAreaCode(), $customer->getPhoneNumber(), $customer->getPhoneCountryCode());
        }
        if (!is_null($customer->getBillingAddress())) {
            $address = $customer->getBillingAddress();
            $holder->setAddress(
                'BILLING',
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
