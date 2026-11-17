<?php

function normalizeFarmerMobile(string $mobile): ?string
{
    $mobile = trim($mobile);
    if ($mobile === '' || !preg_match('/\A\+?[0-9()\s-]{5,19}\z/', $mobile)) {
        return null;
    }

    $digits = preg_replace('/\D/', '', $mobile);
    if (!is_string($digits)) {
        return null;
    }
    if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
        $digits = substr($digits, 2);
    } elseif (strlen($digits) === 11 && str_starts_with($digits, '0')) {
        $digits = substr($digits, 1);
    }

    return preg_match('/\A[6-9][0-9]{9}\z/', $digits) === 1 ? $digits : null;
}
