<?php

function moneyValue($amount): float
{
    return round((float)$amount, 2);
}

function moneyFormat($amount): string
{
    $value = moneyValue($amount);
    $decimals = abs($value - round($value)) < 0.00001 ? 0 : 2;
    return number_format($value, $decimals, ',', ' ') . ' ₽';
}

function moneyDecimalInput($value, string $field = 'Сумма'): string
{
    $raw = str_replace([' ', ','], ['', '.'], trim((string)$value));
    if (!preg_match('/^\d+(?:\.\d{1,2})?$/D', $raw) || (float)$raw > 999999999.99) {
        throw new InvalidArgumentException($field . ' должна быть неотрицательным числом с точностью до копеек.');
    }
    return number_format((float)$raw, 2, '.', '');
}

