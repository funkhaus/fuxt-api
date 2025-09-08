# Fuxt API Email Endpoint Examples

The Fuxt API now includes a REST endpoint for sending emails. This document provides examples of how to use the email functionality.

## Endpoint

**POST** `/wp-json/fuxt/v1/email`

## Basic Usage

### Simple Text Email

```bash
curl -X POST "https://yoursite.com/wp-json/fuxt/v1/email" \
  -H "Content-Type: application/json" \
  -d '{
    "to": "recipient@example.com",
    "subject": "Test Email",
    "message": "This is a test email from the Fuxt API."
  }'
```

### HTML Email

```bash
curl -X POST "https://yoursite.com/wp-json/fuxt/v1/email" \
  -H "Content-Type: application/json" \
  -d '{
    "to": "recipient@example.com",
    "subject": "HTML Test Email",
    "message": "<h1>Hello World!</h1><p>This is an <strong>HTML</strong> email.</p>",
    "is_html": true
  }'
```

### Email with Custom Sender

```bash
curl -X POST "https://yoursite.com/wp-json/fuxt/v1/email" \
  -H "Content-Type: application/json" \
  -d '{
    "to": "recipient@example.com",
    "subject": "Email from Custom Sender",
    "message": "This email is sent from a custom sender.",
    "from_name": "John Doe",
    "from_email": "john@example.com",
    "reply_to": "noreply@example.com"
  }'
```

### Email with CC and BCC

```bash
curl -X POST "https://yoursite.com/wp-json/fuxt/v1/email" \
  -H "Content-Type: application/json" \
  -d '{
    "to": "recipient@example.com",
    "subject": "Email with CC and BCC",
    "message": "This email has CC and BCC recipients.",
    "cc": "cc1@example.com, cc2@example.com",
    "bcc": "bcc@example.com"
  }'
```

### Email with Attachments

```bash
curl -X POST "https://yoursite.com/wp-json/fuxt/v1/email" \
  -H "Content-Type: application/json" \
  -d '{
    "to": "recipient@example.com",
    "subject": "Email with Attachments",
    "message": "Please find the attached files.",
    "attachments": [
      "/path/to/local/file.pdf",
      "https://example.com/remote-file.jpg"
    ]
  }'
```

### Email with Custom Headers

```bash
curl -X POST "https://yoursite.com/wp-json/fuxt/v1/email" \
  -H "Content-Type: application/json" \
  -d '{
    "to": "recipient@example.com",
    "subject": "Email with Custom Headers",
    "message": "This email has custom headers.",
    "headers": {
      "X-Priority": "1",
      "X-MSMail-Priority": "High",
      "Importance": "high"
    }
  }'
```

## JavaScript/Fetch Examples

### Basic JavaScript Example

```javascript
const sendEmail = async (emailData) => {
  try {
    const response = await fetch('/wp-json/fuxt/v1/email', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(emailData)
    });
    
    const result = await response.json();
    
    if (result.success) {
      console.log('Email sent successfully:', result.message);
    } else {
      console.error('Failed to send email:', result.message);
    }
    
    return result;
  } catch (error) {
    console.error('Error sending email:', error);
    throw error;
  }
};

// Usage
sendEmail({
  to: 'recipient@example.com',
  subject: 'Test Email',
  message: 'This is a test email from JavaScript.'
});
```

### React Hook Example

```javascript
import { useState } from 'react';

const useEmail = () => {
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);

  const sendEmail = async (emailData) => {
    setLoading(true);
    setError(null);

    try {
      const response = await fetch('/wp-json/fuxt/v1/email', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify(emailData)
      });

      const result = await response.json();

      if (!result.success) {
        throw new Error(result.message);
      }

      return result;
    } catch (err) {
      setError(err.message);
      throw err;
    } finally {
      setLoading(false);
    }
  };

  return { sendEmail, loading, error };
};

// Usage in component
const ContactForm = () => {
  const { sendEmail, loading, error } = useEmail();

  const handleSubmit = async (formData) => {
    try {
      await sendEmail({
        to: 'contact@example.com',
        subject: 'New Contact Form Submission',
        message: `Name: ${formData.name}\nEmail: ${formData.email}\nMessage: ${formData.message}`,
        from_name: formData.name,
        from_email: formData.email,
        reply_to: formData.email
      });
      alert('Message sent successfully!');
    } catch (err) {
      alert('Failed to send message: ' + err.message);
    }
  };

  return (
    <form onSubmit={handleSubmit}>
      {/* form fields */}
    </form>
  );
};
```

## PHP Examples

### Using WordPress HTTP API

```php
function send_email_via_api($email_data) {
    $response = wp_remote_post('https://yoursite.com/wp-json/fuxt/v1/email', [
        'headers' => [
            'Content-Type' => 'application/json',
        ],
        'body' => json_encode($email_data),
        'timeout' => 30,
    ]);

    if (is_wp_error($response)) {
        return false;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    return $data['success'] ?? false;
}

// Usage
$result = send_email_via_api([
    'to' => 'recipient@example.com',
    'subject' => 'Test Email from PHP',
    'message' => 'This email was sent using the Fuxt API from PHP.',
    'from_name' => 'WordPress Site',
    'from_email' => 'noreply@yoursite.com'
]);
```

## Response Format

### Success Response

```json
{
  "success": true,
  "message": "Email sent successfully.",
  "data": {
    "to": "recipient@example.com",
    "subject": "Test Email"
  }
}
```

### Error Response

```json
{
  "success": false,
  "message": "Failed to send email.",
  "data": {}
}
```

## Parameters

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `to` | string | Yes | - | Email address to send to |
| `subject` | string | Yes | - | Email subject |
| `message` | string | Yes | - | Email message content |
| `from_name` | string | No | Site name | Sender name |
| `from_email` | string | No | Admin email | Sender email address |
| `reply_to` | string | No | from_email | Reply-to email address |
| `cc` | string | No | - | CC email addresses (comma separated) |
| `bcc` | string | No | - | BCC email addresses (comma separated) |
| `headers` | object | No | - | Additional email headers as key-value pairs |
| `attachments` | array | No | - | File attachments (array of file paths or URLs) |
| `is_html` | boolean | No | false | Whether the message is HTML content |

## Security Considerations

1. **Permissions**: By default, the endpoint allows public access. You can restrict access using the `fuxt_api_email_permissions` filter:

```php
add_filter('fuxt_api_email_permissions', function($allowed, $request) {
    // Only allow logged-in users
    return is_user_logged_in();
}, 10, 2);
```

2. **Rate Limiting**: Consider implementing rate limiting to prevent abuse.

3. **Input Validation**: All inputs are sanitized, but you may want to add additional validation based on your needs.

## Filters

### fuxt_api_email_data

Filter the email data before sending:

```php
add_filter('fuxt_api_email_data', function($email_data, $request) {
    // Add custom logic here
    $email_data['message'] = 'Prefix: ' . $email_data['message'];
    return $email_data;
}, 10, 2);
```

### fuxt_api_email_response

Filter the response data:

```php
add_filter('fuxt_api_email_response', function($response_data, $request, $sent) {
    // Add custom response data
    $response_data['timestamp'] = current_time('mysql');
    return $response_data;
}, 10, 3);
```

### fuxt_api_email_permissions

Control access to the email endpoint:

```php
add_filter('fuxt_api_email_permissions', function($allowed, $request) {
    // Custom permission logic
    return current_user_can('manage_options');
}, 10, 2);
```
