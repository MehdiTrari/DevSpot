<?php

namespace App\Enum;

enum NotificationType: string
{
    // =========================
    // AUTH / ACCOUNT
    // =========================
    case ACCOUNT_PENDING = 'account_pending';
    case ACCOUNT_APPROVED = 'account_approved';
    case ACCOUNT_REJECTED = 'account_rejected';
    case ACCOUNT_SUSPENDED = 'account_suspended';
    case ACCOUNT_BANNED = 'account_banned';

    // =========================
    // MESSAGES / CONTACT
    // =========================
    case NEW_MESSAGE = 'new_message';
    case NEW_CONVERSATION = 'new_conversation';
    case MESSAGE_REPLY = 'message_reply';

    // =========================
    // PROFILE
    // =========================
    case PROFILE_UPDATED = 'profile_updated';
    case PROFILE_PUBLISHED = 'profile_published';
    case PROFILE_UNPUBLISHED = 'profile_unpublished';
    case PROFILE_VIEWED = 'profile_viewed'; // optionnel
    case PROFILE_MODERATED = 'profile_moderated';
    case PROFILE_INCOMPLETE = 'profile_incomplete';

    // =========================
    // FAVORITES
    // =========================
    case PROFILE_FAVORITED = 'profile_favorited';
    case PROFILE_UNFAVORITED = 'profile_unfavorited';

    // =========================
    // ADMIN
    // =========================
    case NEW_USER_PENDING = 'new_user_pending';
    case ROLE_REQUEST = 'role_request';
    case SLUG_CHANGE_REQUEST = 'slug_change_request';
    case CONTENT_REPORTED = 'content_reported';

    // =========================
    // JOB OFFERS (si utilisé)
    // =========================
    case JOB_OFFER_CREATED = 'job_offer_created';
    case JOB_OFFER_UPDATED = 'job_offer_updated';
    case JOB_OFFER_EXPIRED = 'job_offer_expired';

    // =========================
    // SYSTEM
    // =========================
    case SYSTEM_NOTIFICATION = 'system_notification';
}