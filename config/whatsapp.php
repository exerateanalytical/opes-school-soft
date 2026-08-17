<?php

declare(strict_types=1);

/*
 * Meta WhatsApp Business Cloud API.
 *
 * Every value here comes from the school's OWN Meta account - there are no
 * defaults and there is no shared Opes account. To fill these in:
 *
 *   developers.facebook.com -> your App -> WhatsApp -> API Setup
 *
 * NOTHING is invented or hard-coded in this repository. Until a real token
 * and phone number id are supplied, `WhatsAppConfig::isConfigured()` is false
 * and every send path refuses loudly (WhatsAppNotConfiguredException) rather
 * than pretending a parent was messaged.
 *
 * These env values are the FLOOR. The admin screen at /settings/whatsapp
 * writes the same three values into the audited `settings` store, and
 * WhatsAppConfig prefers those - so a principal can rotate a token without
 * shell access, and a deployment can still pre-seed one via .env.
 */
return [

    /*
     * Master switch. Even with credentials present, `false` keeps the channel
     * inert - the way to stop a mis-configured blast without deleting the
     * token you just pasted in.
     */
    'enabled' => env('WHATSAPP_ENABLED', false),

    /*
     * The permanent System User access token.
     * Meta dashboard: WhatsApp -> API Setup -> "Temporary access token", or
     * Business Settings -> System Users -> Generate token (permanent).
     * Scopes required: whatsapp_business_messaging.
     */
    'access_token' => env('WHATSAPP_ACCESS_TOKEN'),

    /*
     * The numeric id of the SENDING number (NOT the number itself).
     * Meta dashboard: WhatsApp -> API Setup -> "Phone number ID".
     */
    'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),

    /*
     * The WhatsApp Business Account id. Not needed to send; kept because
     * template management and support tickets both ask for it.
     * Meta dashboard: WhatsApp -> API Setup -> "WhatsApp Business Account ID".
     */
    'business_account_id' => env('WHATSAPP_BUSINESS_ACCOUNT_ID'),

    /*
     * Graph API version, e.g. `v21.0`. Meta deprecates versions on a rolling
     * ~2-year schedule, so this is configurable rather than compiled in.
     */
    'api_version' => env('WHATSAPP_API_VERSION', 'v21.0'),

    /*
     * Graph host. Configurable only so tests and a future on-premise/BSP
     * gateway can point elsewhere; schools never change it.
     */
    'base_url' => env('WHATSAPP_BASE_URL', 'https://graph.facebook.com'),

    /*
     * Seconds to wait on the Meta call. Sending happens inside a queued job,
     * so this bounds the WORKER, never a web request.
     */
    'timeout' => (int) env('WHATSAPP_TIMEOUT', 15),

    /*
     * The default approved template used to OPEN a conversation.
     *
     * Meta only allows free-form text to a parent inside the 24-hour customer
     * service window (i.e. within 24h of that parent messaging the school).
     * Outside it, ONLY an approved template is delivered - a plain-text send
     * is rejected by the API with error code 131047. Since a school almost
     * always initiates (fee due, absence, results published), the template
     * path is the normal one and plain text is the exception.
     *
     * Null until the school has a template approved in the Meta dashboard.
     */
    'default_template' => env('WHATSAPP_DEFAULT_TEMPLATE'),

    /*
     * Language code the template was approved under (`en`, `fr`, `en_US`).
     * Must match the approved template exactly or Meta rejects the send.
     */
    'default_template_language' => env('WHATSAPP_DEFAULT_TEMPLATE_LANGUAGE', 'en'),

];
