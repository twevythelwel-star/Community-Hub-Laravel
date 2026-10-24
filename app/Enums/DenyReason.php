<?php

namespace App\Enums;

/**
 * Mirrors `DenyReason` from src/lib/gate-pass-engine/types.ts.
 */
enum DenyReason: string
{
    case InvalidQrStructure = 'INVALID_QR_STRUCTURE';
    case NotAGpeGatePass = 'NOT_A_GPE_GATE_PASS';
    case UnauthorizedCommunity = 'UNAUTHORIZED_COMMUNITY_ID';
    case SignatureMismatch = 'CRYPTOGRAPHIC_SIGNATURE_MISMATCH';
    case TokenExpired = 'TOKEN_EXPIRED';
    case TokenNotYetValid = 'TOKEN_NOT_YET_VALID';
    case ReplayAttack = 'REPLAY_ATTACK_DETECTED';
    case PassRevoked = 'PASS_REVOKED_BY_ADMIN';
    case OutsideHours = 'OUTSIDE_PERMITTED_HOURS';
    case UnauthorizedGate = 'UNAUTHORIZED_GATE';
    case UserNotActive = 'USER_NOT_ACTIVE';
    case UnknownPass = 'PASS_NOT_REGISTERED';
    case ClaimMismatch = 'PASS_CLAIM_MISMATCH';
    case PassSuperseded = 'PASS_SUPERSEDED';
    case OutsideValidity = 'OUTSIDE_PASS_VALIDITY';
    case PassNotUsable = 'PASS_NOT_USABLE';
    case HolderBlocked = 'HOLDER_BLOCKLISTED';
    case UnauthorizedZone = 'UNAUTHORIZED_ZONE';
}
