<?php

declare(strict_types=1);

namespace App\Enums;

enum AuthSecurityEventType: string
{
    case LoginSuccess = 'login_success';
    case LoginFailed = 'login_failed';
    case NewDeviceLogin = 'new_device_login';
    case RiskDetected = 'risk_detected';
    case SessionRevoked = 'session_revoked';
    case OtherSessionsRevoked = 'other_sessions_revoked';
    case DeviceLimitReached = 'device_limit_reached';
}
