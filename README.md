# IraniU Website

## Setup

1. **Install PHP dependencies**
   ```bash
   composer install
   ```

2. **Configure environment**
   - Copy `.env.example` to `.env` if needed
   - Edit `.env` with your Zoho SMTP credentials (already configured for hello@iraniu.uk)

3. **Deploy**  
   Upload files to any PHP-enabled web host (Apache, Nginx + PHP-FPM, etc.).

## Contact Form

The contact form (`contact.php`) sends emails via Zoho SMTP to `hello@iraniu.uk`. Submissions are formatted in site-themed HTML. On success, users are redirected to `thank-you.html` with "We will be in touch."

## Careers

Job listings are stored in `data/careers.json`.

## Files

- `contact.php` - PHP contact form handler (Zoho SMTP)
- `load_env.php` - Loads `.env` credentials
- `data/careers.json` - Careers data
- `thank-you.html` - Thank you page after form submission
- `.env` - SMTP credentials (do not commit)
