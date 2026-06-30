<?php

function estimate_auth_user(): string
{
    return isset($_SERVER['PHP_AUTH_USER']) ? (string) $_SERVER['PHP_AUTH_USER'] : '';
}

function estimate_user_role(): string
{
    if (strtolower(estimate_auth_user()) === 'manager') {
        return 'manager';
    }

    return 'admin';
}

function estimate_is_manager(): bool
{
    return estimate_user_role() === 'manager';
}

function estimate_is_admin(): bool
{
    return !estimate_is_manager();
}
