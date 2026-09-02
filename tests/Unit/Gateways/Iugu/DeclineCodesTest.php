<?php

namespace Potelo\MultiPayment\Tests\Unit\Gateways\Iugu;

use PHPUnit\Framework\TestCase;
use Potelo\MultiPayment\Enums\DeclineCode;
use PHPUnit\Framework\Attributes\DataProvider;
use Potelo\MultiPayment\Gateways\Iugu\DeclineCodes;

/**
 * Tabela verdade do mapa de LR da Iugu para `DeclineCode` e da leitura do LR na resposta.
 */
class DeclineCodesTest extends TestCase
{
    #[DataProvider('lrProvider')]
    public function testLrIsTranslated(string $lr, DeclineCode $expected): void
    {
        $this->assertSame($expected, DeclineCodes::toDeclineCode($lr));
    }

    public static function lrProvider(): array
    {
        return [
            '51' => ['51', DeclineCode::INSUFFICIENT_FUNDS],
            '61' => ['61', DeclineCode::INSUFFICIENT_FUNDS],
            '70' => ['70', DeclineCode::INSUFFICIENT_FUNDS],
            'DM' => ['DM', DeclineCode::INSUFFICIENT_FUNDS],
            '54' => ['54', DeclineCode::EXPIRED_CARD],
            '14' => ['14', DeclineCode::INCORRECT_NUMBER],
            '25' => ['25', DeclineCode::INCORRECT_NUMBER],
            '12' => ['12', DeclineCode::INVALID_CARD],
            '56' => ['56', DeclineCode::INVALID_CARD],
            'BM' => ['BM', DeclineCode::INVALID_CARD],
            'G4' => ['G4', DeclineCode::INVALID_CARD],
            '4' => ['4', DeclineCode::LOST_OR_STOLEN],
            '41' => ['41', DeclineCode::LOST_OR_STOLEN],
            '43' => ['43', DeclineCode::LOST_OR_STOLEN],
            '62' => ['62', DeclineCode::LOST_OR_STOLEN],
            '7' => ['7', DeclineCode::FRAUD_SUSPECTED],
            '59' => ['59', DeclineCode::FRAUD_SUSPECTED],
            'AF01' => ['AF01', DeclineCode::FRAUD_SUSPECTED],
            'BP171' => ['BP171', DeclineCode::FRAUD_SUSPECTED],
            'AI' => ['AI', DeclineCode::AUTHENTICATION_REQUIRED],
            '57' => ['57', DeclineCode::BRAND_NOT_SUPPORTED],
            '39' => ['39', DeclineCode::BRAND_NOT_SUPPORTED],
            'C1' => ['C1', DeclineCode::BRAND_NOT_SUPPORTED],
            '5' => ['5', DeclineCode::DO_NOT_HONOR],
            '63' => ['63', DeclineCode::DO_NOT_HONOR],
            '100' => ['100', DeclineCode::DO_NOT_HONOR],
            'FC' => ['FC', DeclineCode::DO_NOT_HONOR],
            'R0' => ['R0', DeclineCode::DO_NOT_HONOR],
            '91' => ['91', DeclineCode::TRY_AGAIN],
            '96' => ['96', DeclineCode::TRY_AGAIN],
            '99A' => ['99A', DeclineCode::TRY_AGAIN],
            '911' => ['911', DeclineCode::TRY_AGAIN],
            'BP902' => ['BP902', DeclineCode::TRY_AGAIN],
            '13' => ['13', DeclineCode::GENERIC],
            '94' => ['94', DeclineCode::GENERIC],
        ];
    }

    public function testLookupIgnoresCase(): void
    {
        $this->assertSame(DeclineCode::FRAUD_SUSPECTED, DeclineCodes::toDeclineCode('af02'));
    }

    #[DataProvider('zeroPaddedProvider')]
    public function testLookupIgnoresLeadingZerosOfNumericCodes(string $lr, DeclineCode $expected): void
    {
        $this->assertSame($expected, DeclineCodes::toDeclineCode($lr));
    }

    public static function zeroPaddedProvider(): array
    {
        return [
            '01' => ['01', DeclineCode::INVALID_CARD],
            '04' => ['04', DeclineCode::LOST_OR_STOLEN],
            '05' => ['05', DeclineCode::DO_NOT_HONOR],
            '06' => ['06', DeclineCode::DO_NOT_HONOR],
            '07' => ['07', DeclineCode::FRAUD_SUSPECTED],
            '051 (três dígitos, não é 51)' => ['051', DeclineCode::INSUFFICIENT_FUNDS],
        ];
    }

    public function testAllZerosStaysUnmapped(): void
    {
        // 0 e 00 são "transação autorizada" na tabela e nunca chegam numa recusa
        $this->assertNull(DeclineCodes::toDeclineCode('00'));
    }

    #[DataProvider('unmappedProvider')]
    public function testUnmappedOrEmptyLrIsNull(?string $lr): void
    {
        $this->assertNull(DeclineCodes::toDeclineCode($lr));
    }

    public static function unmappedProvider(): array
    {
        return [
            'nulo' => [null],
            'vazio' => [''],
            'senha (cartão presente)' => ['75'],
            'comerciante inválido' => ['3'],
            'inexistente' => ['ZZ9'],
        ];
    }

    public function testExtractLrPrefersTheField(): void
    {
        $charge = (object) ['LR' => '51', 'info_message' => 'Fulano, Master, XXXXXXXXXXXX1234, LR: 05'];

        $this->assertSame('51', DeclineCodes::extractLr($charge));
    }

    public function testExtractLrNormalizesTheFieldToUpperCaseAndAcceptsInteger(): void
    {
        $this->assertSame('AF02', DeclineCodes::extractLr((object) ['LR' => ' af02 ']));
        $this->assertSame('51', DeclineCodes::extractLr((object) ['LR' => 51]));
    }

    #[DataProvider('messageProvider')]
    public function testExtractLrReadsTheMessageWhenTheFieldIsAbsent(object $charge, ?string $expected): void
    {
        $this->assertSame($expected, DeclineCodes::extractLr($charge));
    }

    public static function messageProvider(): array
    {
        return [
            'info_message com dois pontos' => [(object) ['info_message' => 'Fulano, Master, XXXXXXXXXXXX1234, LR: 05'], '05'],
            'info_message sem dois pontos' => [(object) ['info_message' => 'Transação não autorizada LR 51'], '51'],
            'message alfanumérico' => [(object) ['message' => 'Recusado (LR: af02)'], 'AF02'],
            'campo LR vazio e mensagem com código' => [(object) ['LR' => '', 'info_message' => 'LR: 54'], '54'],
            'sem código' => [(object) ['info_message' => 'Transação não autorizada'], null],
            'resposta vazia' => [(object) [], null],
        ];
    }
}
