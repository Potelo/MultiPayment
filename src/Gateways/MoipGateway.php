<?php

namespace Potelo\MultiPayment\Gateways;

use Moip\Moip;
use Carbon\Carbon;
use Moip\Auth\BasicAuth;
use Moip\Resource\Holder;
use Moip\Resource\Orders;
use Moip\Resource\Payment;
use Illuminate\Support\Facades\Config;
use Potelo\MultiPayment\Models\Address;
use Potelo\MultiPayment\Models\Invoice;
use Moip\Exceptions\ValidationException;
use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Models\BankSlip;
use Moip\Exceptions\UnexpectedException;
use Moip\Exceptions\UnautorizedException;
use Potelo\MultiPayment\Contracts\Gateway;
use Potelo\MultiPayment\Models\CreditCard;
use Potelo\MultiPayment\Models\InvoiceItem;
use Potelo\MultiPayment\Exceptions\GatewayException;
use Potelo\MultiPayment\Exceptions\GatewayNotAvailableException;

class MoipGateway implements Gateway
{

    private const STATUS_CREATED = 'CREATED';
    private const STATUS_WAITING = 'WAITING';
    private const STATUS_PAID = 'PAID';
    private const STATUS_NOT_PAID = 'NOT_PAID';
    private const STATUS_REVERTED = 'REVERTED';

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
                Config::get('multi-payment.gateways.moip.api_token'),
                Config::get('multi-payment.gateways.moip.api_key')
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
        } catch (UnexpectedException|UnautorizedException $exception) {
            throw new GatewayNotAvailableException('Error creating customer: ' . $exception->getMessage());
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
                $expiresAt = !empty($invoice->expiresAt)
                    ? $invoice->expiresAt->format('Y-m-d')
                    : Carbon::now()->format('Y-m-d');
                $instructionLines = ['', '', ''];
                $payment->setBoleto($expiresAt, $logoUri, $instructionLines);
            }

            try {
                $payment->execute();
            } catch (ValidationException $exception) {
                throw new GatewayException('Error charging invoice: ' . $exception->getMessage(), $exception->getErrors());
            } catch (UnexpectedException|UnautorizedException $exception) {
                throw new GatewayNotAvailableException('Error creating customer: ' . $exception->getMessage());
            } catch (\Exception $exception) {
                throw new GatewayException('Error charging invoice: ' . $exception->getMessage());
            }

            if ($invoice->paymentMethod == Invoice::PAYMENT_METHOD_CREDIT_CARD) {
                if (Config::get('multi-payment.environment') != 'production') {
                    $payment->authorize();
                    $order = $order->get($order->getId());
                    /** @noinspection PhpParamsInspection */
                    $payment = $payment->get($payment->getId());
                    /** @noinspection PhpUndefinedMethodInspection */
                    $payment->setOrder($order);

                }
                $invoice->creditCard->id = $payment->getFundingInstrument()->creditCard->id;
                $invoice->creditCard->brand = $payment->getFundingInstrument()->creditCard->brand;
                $invoice->creditCard->lastDigits = $payment->getFundingInstrument()->creditCard->last4;
                $invoice->creditCard->gateway = 'moip';
            } elseif ($invoice->paymentMethod == Invoice::PAYMENT_METHOD_BANK_SLIP) {
                $invoice->bankSlip = new BankSlip();
                /** @noinspection PhpFieldAssignmentTypeMismatchInspection */
                $invoice->bankSlip->url = $payment->getHrefPrintBoleto();
                /** @noinspection PhpFieldAssignmentTypeMismatchInspection */
                $invoice->bankSlip->number = $payment->getLineCodeBoleto();
            }

            $order = $payment->getOrder();
        }

        $invoice->status = $this->moipStatusToMultiPayment($order->getStatus());
        $invoice->paidAt = $invoice->status == Invoice::STATUS_PAID ? new Carbon($payment->getCreatedAt()) : null;
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
            /** @var \Moip\Resource\Customer $moipCustomer*/
            $moipCustomer = $moipCustomer->create();
        } catch (ValidationException $exception) {
            throw new GatewayException('Error creating customer: ' . $exception->getMessage(), $exception->getErrors());
        } catch (UnexpectedException|UnautorizedException $exception) {
            throw new GatewayNotAvailableException('Error creating customer: ' . $exception->getMessage());
        } catch (\Exception $exception) {
            throw new GatewayException('Error creating customer: ' . $exception->getMessage());
        }
        $customer->id = $moipCustomer->getId();
        $customer->createdAt = Carbon::now();
        $customer->original = $moipCustomer;
        $customer->gateway = 'moip';
        return $customer;
    }

    /**
     * @inheritDoc
     */
    public function createCreditCard(CreditCard $creditCard): CreditCard
    {
        $this->init();
        $customer = $this->moip->customers()->get($creditCard->customer->id);
        $verificationValue = rand(200, 300);
        $verificationOrder = $this->moip->orders()->setOwnId(uniqid())
            ->addItem(
                'Verificação de Cartão',
                1,
                'Verificação de Cartão',
                $verificationValue
            )
            ->setCustomerId($customer->getId())
            ->create();

        $holder = $this->moip->holders();
        $holder->setFullname($customer->getFullname())
            ->setTaxDocument($customer->getTaxDocumentNumber())
            ->setBirthDate($customer->getBirthDate() ?? '')
            ->setPhone(
                $customer->getPhoneAreaCode() ?? '',
                $customer->getPhoneNumber() ?? '',
                $customer->getPhoneCountryCode() ?? ''
            );
        $payment = $verificationOrder->payments();

        if (!empty($creditCard->token)) {
            $payment->setCreditCardHash($creditCard->token, $holder);
        } else {
            $payment->setCreditCard(
                $creditCard->month,
                substr($creditCard->year, -2),
                $creditCard->number,
                $creditCard->cvv,
                $holder
            )
                ->setInstallmentCount(1)
                ->setStatementDescriptor('Verificação');
        }

        try {
            $payment->execute();
            if (Config::get('multi-payment.environment') != 'production') {
                $payment->authorize();
            }

            //wait for the payment to be authorized
            $maxAttempts = 3;
            $attempt = 0;
            while ($attempt < $maxAttempts) {
                $attempt++;
                try {
                    $payment->refunds()->creditCardFull();
                    break;
                } catch (\Exception $exception) {
                    if ($attempt == $maxAttempts) {
                        throw $exception;
                    } else {
                        sleep(1);
                    }
                }
            }

        } catch (ValidationException $exception) {
            throw new GatewayException('Error creating credit card: ' . $exception->getMessage(), $exception->getErrors());
        } catch (UnexpectedException|UnautorizedException $exception) {
            throw new GatewayNotAvailableException('Error creating credit card: ' . $exception->getMessage());
        } catch (\Exception $exception) {
            throw new GatewayException('Error creating credit card: ' . $exception->getMessage());
        }

        if (!empty($payment->getFundingInstrument()->creditCard->holder->fullname)) {
            $names = explode(' ', $payment->getFundingInstrument()->creditCard->holder->fullname, 2);
            $creditCard->firstName = $names[0];
            $creditCard->lastName = count($names) > 1 ? $names[1] : null;
        }
        $creditCard->brand = $payment->getFundingInstrument()->creditCard->brand;
        $creditCard->lastDigits = $payment->getFundingInstrument()->creditCard->last4;
        $creditCard->id = $payment->getFundingInstrument()->creditCard->id;
        $creditCard->original = $payment->getFundingInstrument()->creditCard;
        $creditCard->gateway = 'moip';
        return $creditCard;
    }

    /**
     * Parse a MultiPayment Customer to a Moip Customer
     *
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
        return Config::get('multi-payment.environment') != 'production'
            ? Moip::ENDPOINT_SANDBOX
            : Moip::ENDPOINT_PRODUCTION;
    }

    /**
     * Convert Moip status to MultiPayment status.
     *
     * @param $moipStatus
     *
     * @return string
     * @throws GatewayException
     */
    private static function moipStatusToMultiPayment($moipStatus): string
    {
        switch ($moipStatus) {
            case self::STATUS_CREATED:
            case self::STATUS_WAITING:
                return Invoice::STATUS_PENDING;
            case self::STATUS_PAID:
                return Invoice::STATUS_PAID;
            case self::STATUS_NOT_PAID:
                return Invoice::STATUS_CANCELED;
            case self::STATUS_REVERTED:
                return Invoice::STATUS_REFUNDED;
            default:
                throw new GatewayException('Invalid Moip status: ' . $moipStatus);
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
    public function getInvoice(string $id): Invoice
    {
        $this->init();

        try {
            /** @var Orders $moipOrder*/
            $moipOrder = $this->moip->orders()->get($id);
        } catch (ValidationException $e) {
            throw new GatewayException('Error getting invoice: ' . $e->getMessage(), $e->getErrors());
        } catch (\Exception $e) {
            throw new GatewayException('Error getting invoice: ' . $e->getMessage());
        }

        $invoice = new Invoice();

        $invoice->id = $moipOrder->getId();
        $invoice->status = $this->moipStatusToMultiPayment($moipOrder->getStatus());
        $invoice->paidAt = null;
        foreach ($moipOrder->getPaymentIterator() as $payment) {
            if ($payment->getStatus() == Payment::STATUS_AUTHORIZED) {
                $invoice->paidAt = new Carbon($payment->getCreatedAt());
            }
        }
        $invoice->amount = $moipOrder->getAmountTotal();
        $invoice->fee = $moipOrder->getAmountFees() ?? null;
        $invoice->url = $moipOrder->getLinks()->getLink('checkout')->payCheckout->redirectHref ?? null;
        $invoice->gateway = 'moip';
        $invoice->original = $moipOrder;
        $invoice->createdAt = new Carbon($moipOrder->getCreatedAt());

        $moipCustomer = $moipOrder->getCustomer();
        $invoice->customer = new Customer();
        $invoice->customer->id = $moipCustomer->getId() ?? null;
        $invoice->customer->name = $moipCustomer->getFullname() ?? null;
        $invoice->customer->taxDocument = $moipCustomer->getTaxDocumentNumber() ?? null;
        $invoice->customer->birthDate = !is_null($moipCustomer->getBirthDate()) ? $moipCustomer->getBirthDate()->format('Y-m-d') : null;
        $invoice->customer->phoneArea = $moipCustomer->getPhoneAreaCode() ?? null;
        $invoice->customer->phoneNumber = $moipCustomer->getPhoneNumber() ?? null;
        $invoice->customer->phoneCountryCode = $moipCustomer->getPhoneCountryCode() ?? null;

        if (!empty($moipOrder->getItemIterator())) {
            $invoice->items = [];
            foreach($moipOrder->getItemIterator() as $item){
                $invoiceItem = new InvoiceItem();
                $invoiceItem->description = $item->product;
                $invoiceItem->price = $item->price;
                $invoiceItem->quantity = $item->quantity;
                $invoice->items[] = $invoiceItem;
            }

        }
        $paymentsIterator = $moipOrder->getPaymentIterator();
        if ($paymentsIterator->count() > 0) {
            $paymentsIterator->seek($paymentsIterator->count() - 1);
            $moipLastPayment = $paymentsIterator->current();
            if ($moipLastPayment->getFundingInstrument()->method == Payment::METHOD_BOLETO) {
                $invoice->bankSlip = new BankSlip();
                $invoice->paymentMethod = Invoice::PAYMENT_METHOD_BANK_SLIP;
                $invoice->bankSlip->number = $moipLastPayment->getLineCodeBoleto();
                $invoice->bankSlip->url = $invoice->url;
                $invoice->expiresAt = !empty($moipLastPayment->getFundingInstrument()->boleto->expiresAt)
                    ? new Carbon($moipLastPayment->getFundingInstrument()->boleto->expiresAt)
                    : null;
            } elseif ($moipLastPayment->getFundingInstrument()->method == Payment::METHOD_CREDIT_CARD){
                $invoice->creditCard = new CreditCard();
                $invoice->paymentMethod = Invoice::PAYMENT_METHOD_CREDIT_CARD;
                if (!empty($moipLastPayment->getFundingInstrument()->creditCard->holder->fullname)) {
                    $names = explode(' ', $moipLastPayment->getFundingInstrument()->creditCard->holder->fullname, 2);
                    $invoice->creditCard->firstName = $names[0];
                    $invoice->creditCard->lastName = count($names) > 1 ? $names[1] : null;
                }
                $invoice->creditCard->brand = $moipLastPayment->getFundingInstrument()->creditCard->brand;
                $invoice->creditCard->lastDigits = $moipLastPayment->getFundingInstrument()->creditCard->last4;
                $invoice->creditCard->id = $moipLastPayment->getFundingInstrument()->creditCard->id;
            }
        }
        return $invoice;
    }

    /**
     * Fill a moip customer or holder with multipayment customer data
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
    /**
     * @inheritDoc
     */
    public function refundInvoice(Invoice $invoice): Invoice
    {
        $this->init();
        $this->moip->refunds()->creditCard($invoice->id);

        return $this->getInvoice($invoice->id);
    }

    /**
     * @inheritDoc
     */
    public function __toString()
    {
        return 'moip';
    }
}
