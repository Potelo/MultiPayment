<?php

namespace Potelo\MultiPayment\Gateways;

use Moip\Moip;
use Carbon\Carbon;
use Moip\Auth\BasicAuth;
use Moip\Resource\Holder;
use Moip\Resource\Payment;
use Potelo\MultiPayment\Models\Address;
use Potelo\MultiPayment\Helpers\Config;
use Potelo\MultiPayment\Models\Invoice;
use Moip\Exceptions\ValidationException;
use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Models\BankSlip;
use Potelo\MultiPayment\Contracts\Gateway;
use Potelo\MultiPayment\Models\CreditCard;
use Potelo\MultiPayment\Models\InvoiceItem;
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
        if (!empty($invoice->paymentMethod) && $invoice->paymentMethod == Invoice::PAYMENT_METHOD_PIX) {
            throw new GatewayException('Moip gateway does not support pix payment method.');
        }
        $this->init();

        $order = $this->moip->orders()->setOwnId(uniqid());
        foreach ($invoice->items as $item) {
            $order->addItem($item->description, $item->quantity, "", $item->price);
        }

        $customer = $this->multipaymentCustomerToMoipCustomer($invoice->customer);
        $order->setCustomer($customer);

        try {
            $order->create();
        } catch (ValidationException $exception) {
            throw new GatewayException('Error trying to create invoice: ' . $exception->getMessage(), $exception->getErrors());
        } catch (\Exception $exception) {
            throw new GatewayException('Error trying to create invoice: ' . $exception->getMessage());
        }

        $invoice->id = $order->getId();
        $invoice->gateway = 'moip';
        $invoice->createdAt = Carbon::createFromFormat('Y-m-d H:i:s', $order->getCreatedAt()->format('Y-m-d H:i:s'));
        $invoice->url = $order->getLinks()->getLink('checkout')->payCheckout->redirectHref;
        $invoice->original = $order;

        if (!empty($invoice->paymentMethod)) {

            $payment = $order->payments();
            $holder = $this->createHolder($invoice->customer);

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
                throw new GatewayException('Error charging invoice: ' . $exception->getMessage(), $exception->getErrors());
            } catch (\Exception $exception) {
                throw new GatewayException('Error charging invoice: ' . $exception->getMessage());
            }

            if ($invoice->paymentMethod == Invoice::PAYMENT_METHOD_CREDIT_CARD) {
                $invoice->creditCard->id = $payment->getFundingInstrument()->creditCard->id;
                $invoice->creditCard->brand = $payment->getFundingInstrument()->creditCard->brand;
                $invoice->creditCard->lastDigits = $payment->getFundingInstrument()->creditCard->last4;
            } elseif ($invoice->paymentMethod == Invoice::PAYMENT_METHOD_BANK_SLIP) {
                $invoice->bankSlip = new BankSlip();
                $invoice->bankSlip->url = $payment->getHrefPrintBoleto();
                $invoice->bankSlip->number = $payment->getLineCodeBoleto();
            }

            $order = $payment->getOrder();
        }

        $invoice->status = $this->moipStatusToMultiPayment($order->getStatus());
        $invoice->amount = $order->getAmountTotal();
        $invoice->fee = $order->getAmountFees();
        return $invoice;
    }

    /**
     * @inheritDoc
     */
    public function createCustomer(Customer $customer): Customer
    {
        $moipCustomer = $this->multipaymentCustomerToMoipCustomer($customer);
        try {
            $this->init();
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
     * @param  Customer  $customer
     *
     * @return \Moip\Resource\Customer
     * @throws GatewayException
     */
    private function multipaymentCustomerToMoipCustomer(Customer $customer): \Moip\Resource\Customer
    {
        $this->init();
        if (!empty($customer->id)) {
            try {
                $moipCustomer = $this->moip->customers()->get($customer->id);
            } catch (\Exception $exception) {
                throw new GatewayException('Error getting customer: ' . $exception->getMessage());
            }
        } else {
            $moipCustomer = $this->moip->customers()->setOwnId(uniqid());
        }
        return $this->fillMoipCustomerHolder($customer, $moipCustomer);
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
            ->setPhone('', '', '');
        return $this->fillMoipCustomerHolder($customer, $holder);
    }

    /**
     * @inheritDoc
     */
    public function getInvoice(string $invoiceId): Invoice
    {
        $this->init();

        try {
            $moipInvoice = $this->moip->payments()->get($invoiceId);
        } catch (ValidationException $e) {
            throw new GatewayException('Error getting invoice: ' . $e->getMessage(), $e->getErrors());
        } catch (\Exception $e) {
            throw new GatewayException('Error getting invoice: ' . $e->getMessage());
        }

        $invoice = new Invoice();
        $invoice->id = $moipInvoice->getId();
        $invoice->status = $this->moipStatusToMultiPayment($moipInvoice->getStatus());
        $invoice->amount = $moipInvoice->getAmount()->total;
        $moipOrder = $this->moip->orders()->getByPath($moipInvoice->getLinks()->getLink('order'));
        $invoice->fee = $moipOrder->getAmountFees() ?? null;
        $invoice->url = $moipOrder->getLinks()->getLink('checkout')->payCheckout->redirectHref;
        $invoice->gateway = 'moip';
        $invoice->original = $moipInvoice;
        $invoice->createdAt = new Carbon($moipInvoice->getCreatedAt());

        $moipCustomer = $moipOrder->getCustomer();
        $invoice->customer = new Customer();
        $invoice->customer->id = $moipCustomer->getId();
        $invoice->customer->name = $moipCustomer->getFullname();
        $invoice->customer->taxDocument = $moipCustomer->getTaxDocumentNumber();
        $invoice->customer->birthDate = $moipCustomer->getBirthDate()->format('Y-m-d');
        $invoice->customer->phoneArea = $moipCustomer->getPhoneAreaCode();
        $invoice->customer->phoneNumber = $moipCustomer->getPhoneNumber();
        $invoice->customer->phoneCountryCode = $moipCustomer->getPhoneCountryCode();

        $invoice->items = [];
        foreach($moipOrder->getItemIterator() as $item){
            $invoiceItem = new InvoiceItem();
            $invoiceItem->description = $item->product;
            $invoiceItem->price = $item->price;
            $invoiceItem->quantity = $item->quantity;
            $invoice->items[] = $invoiceItem;
        }

        if ($moipInvoice->getFundingInstrument()->method == Payment::METHOD_BOLETO) {
            $invoice->bankSlip = new BankSlip('moip');
            $invoice->paymentMethod = Invoice::PAYMENT_METHOD_BANK_SLIP;
            $invoice->bankSlip->number = $moipInvoice->getLineCodeBoleto();
            $invoice->bankSlip->url = $invoice->url;
            $invoice->expirationDate = $moipInvoice->getFundingInstrument()->boleto->expirationDate;
        } elseif ($moipInvoice->getFundingInstrument()->method == Payment::METHOD_CREDIT_CARD){
            $invoice->creditCard = new CreditCard('moip');
            $invoice->paymentMethod = Invoice::PAYMENT_METHOD_CREDIT_CARD;
            $names = explode(' ', $moipInvoice->getFundingInstrument()->creditCard->holder->fullname, 2);
            $invoice->creditCard->firstName = $names[0];
            $invoice->creditCard->lastName = count($names) > 1 ? $names[1] : null;
            $moipInvoice->getFundingInstrument()->creditCard->holder->fullname;
            $invoice->creditCard->brand = $moipInvoice->getFundingInstrument()->creditCard->brand;
            $invoice->creditCard->lastDigits = $moipInvoice->getFundingInstrument()->creditCard->last4;
            $invoice->creditCard->id = $moipInvoice->getFundingInstrument()->creditCard->id;
        }

        return $invoice;
    }

    /**
     * @param  Customer  $customer
     * @param  \Moip\Resource\Holder|\Moip\Resource\Customer  $holderOrCustomer
     *
     * @return \Moip\Resource\Holder|\Moip\Resource\Customer
     */
    private function fillMoipCustomerHolder(Customer $customer, $holderOrCustomer)
    {
        if (!empty($customer->email) && $holderOrCustomer instanceof \Moip\Resource\Customer) {
            $holderOrCustomer->setEmail($customer->email);
        }
        if (!empty($customer->name)) {
            $holderOrCustomer->setFullname($customer->name);
        }
        if (!empty($customer->birthDate)) {
            $holderOrCustomer->setBirthDate($customer->birthDate);
        }
        if (!empty($customer->taxDocument)) {
            $type = strlen($customer->taxDocument) == 11 ? 'CPF' : 'CNPJ';
            $holderOrCustomer->setTaxDocument($customer->taxDocument, $type);
        }
        if (!empty($customer->phoneArea) && !empty($customer->phoneNumber)) {
            $holderOrCustomer->setPhone(
                $customer->phoneArea,
                $customer->phoneNumber,
                $customer->phoneCountryCode ?? 55
            );
        }
        if (!empty($customer->address)) {
            $method = $holderOrCustomer instanceof \Moip\Resource\Customer ? 'addAddress' : 'setAddress';
            $holderOrCustomer->$method(
                !empty($customer->address->type) ? $customer->address->type : Address::TYPE_BILLING,
                !empty($customer->address->street) ? $customer->address->street : null,
                !empty($customer->address->number) ? $customer->address->number : 'S/N',
                !empty($customer->address->district) ? $customer->address->district : null,
                !empty($customer->address->city) ? $customer->address->city : null,
                !empty($customer->address->state) ? $customer->address->state : null,
                !empty($customer->address->zipCode) ? $customer->address->zipCode : null,
                !empty($customer->address->complement) ? $customer->address->complement : null,
            );
        }
        return $holderOrCustomer;
    }
}
