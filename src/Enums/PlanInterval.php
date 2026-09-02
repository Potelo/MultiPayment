<?php

namespace Potelo\MultiPayment\Enums;

/**
 * Unidade do intervalo de cobrança de um plano; `Plan::$intervalCount` diz quantas unidades.
 */
enum PlanInterval: string
{
    /** Diário. Sem suporte no driver Iugu, que só aceita semanas e meses. */
    case DAY = 'day';

    /** Semanal. */
    case WEEK = 'week';

    /** Mensal. */
    case MONTH = 'month';

    /** Anual. O driver Iugu o envia como múltiplo de 12 meses. */
    case YEAR = 'year';
}
