<?php

declare(strict_types=1);

namespace VonNeumannGame\Domain;

enum AuthProvider: string
{
    case Password = 'password';
}
