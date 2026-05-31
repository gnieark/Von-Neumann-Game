<?php

declare(strict_types=1);

namespace VonNeumannGame\Domain;

enum AuthProvider: string
{
    case Password = 'password';
    case Google = 'google';
    case Discord = 'discord';

    /**
     * @return array<string>
     */
    public static function externalValues(): array
    {
        return [
            self::Google->value,
            self::Discord->value,
        ];
    }
}
