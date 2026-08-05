<?php

namespace App\Enums;

enum OutboundAbility: string
{
    case Create = 'outbounds:create';
    case Read = 'outbounds:read';
    case Replay = 'outbounds:replay';
}
