# Fuxt API Email Endpoint

`POST /wp-json/fuxt/v1/email` sends a plain-text email from a frontend form (contact form, RFP request, etc.) via `wp_mail()`.

The endpoint is public, so it is deliberately narrow: the request picks a recipient **from an allowlist**, a subject, a message, and a Reply-To address. Sender, CC/BCC, headers, and attachments are never read from the request.

**The endpoint is disabled until a site opts in** by adding recipients (see [Setup](#setup)). Until then it returns `503 rest_email_not_configured`.

## Setup

Add recipients in the theme:

```php
add_filter( 'fuxt_api_email_allowed_recipients', function ( $list ) {
	return array_merge( $list, array( 'contact@example.com' ) );
} );
```

A common pattern is to read them from a Site Options ACF field so they can be edited in wp-admin (see `pearl-backend/functions/email-recipients.php`).

The sender comes from WordPress (`wp_mail_from` / `wp_mail_from_name`), so mail always goes out from the site's own domain. For reliable delivery, install an SMTP plugin (e.g. WP Mail SMTP with Postmark or SES) rather than relying on the host's PHP mail.

## Request

| Param | Required | Notes |
| --- | --- | --- |
| `to` | yes | Must be on the allowlist. |
| `subject` | yes | Plain text. |
| `message` | yes | Plain text, max 10,000 characters. HTML is stripped. |
| `reply_to` | no | Usually the submitter's email, so replies go straight to them. |
| `trap` | yes | Spam trap. Must equal `clientRequestId`. |
| `clientRequestId` | yes | Client-generated unique ID. |
| `recaptchaToken` | when configured | reCAPTCHA v2 response token. Required only when a secret is set. |

Any other params (`cc`, `bcc`, `attachments`, `from_email`, `headers`, …) are ignored.

```bash
curl -X POST "https://yoursite.com/wp-json/fuxt/v1/email" \
  -H "Content-Type: application/json" \
  -d '{
    "to": "contact@example.com",
    "subject": "Website contact from Jane Doe",
    "message": "Name: Jane Doe\nEmail: jane@example.com\n\nHello!",
    "reply_to": "jane@example.com",
    "trap": "contact-1696600000000-abc123",
    "clientRequestId": "contact-1696600000000-abc123"
  }'
```

### Frontend

```ts
const config = useRuntimeConfig()
const clientRequestId = `contact-${Date.now()}-${Math.random().toString(36).slice(2, 11)}`

await $fetch('/email', {
    baseURL: config.public.wordpressApiUrl,
    method: 'POST',
    body: {
        to: 'contact@example.com',
        subject: 'Website contact from Jane Doe',
        message: 'Name: Jane Doe\nEmail: jane@example.com\n\nHello!',
        reply_to: 'jane@example.com',
        trap: clientRequestId,
        clientRequestId
    }
})
```

## Response

```json
{ "success": true, "message": "Email sent successfully.", "data": {} }
```

| Status | Code | Cause |
| --- | --- | --- |
| 400 | `rest_email_missing_fields` | `to`, `subject`, or `message` empty. |
| 400 | `rest_email_message_too_long` | Message over 10,000 characters. |
| 400 | `rest_email_spam_trap_failed` | `trap` missing or doesn't match `clientRequestId`. |
| 400 | `rest_email_recaptcha_failed` | reCAPTCHA configured and token missing/invalid. |
| 403 | `rest_email_recipient_not_allowed` | `to` isn't on the allowlist. |
| 403 | `rest_email_forbidden` | Blocked by `fuxt_api_email_permissions`. |
| 429 | `rest_email_rate_limited` | Too many requests from this IP. |
| 503 | `rest_email_not_configured` | Allowlist is empty. |

Note: some hosts (e.g. Flywheel) replace 4xx JSON bodies with their own HTML error page, so treat any non-2xx as a failure rather than relying on the error code.

## Filters

| Filter | Default | Purpose |
| --- | --- | --- |
| `fuxt_api_email_allowed_recipients` | `[]` | Recipient allowlist. Empty disables the endpoint. |
| `fuxt_api_email_recaptcha_secret` | `FUXT_API_RECAPTCHA_SECRET` constant, else `''` | reCAPTCHA v2 secret. When set, `recaptchaToken` is required and verified. |
| `fuxt_api_email_rate_limit` | `['max' => 5, 'window' => 600]` | Requests allowed per IP per window (seconds). |
| `fuxt_api_email_permissions` | `true` | Return `false` to block a request. Receives the `WP_REST_Request`. |
| `fuxt_api_email_data` | — | Modify `to`/`subject`/`message`/`headers` before `wp_mail()`. |
| `fuxt_api_email_response` | — | Modify the response. Receives the request and whether the send succeeded. |
