# 237Biz.net — Cameroon Business Directory

The easiest way to discover and list Cameroonian businesses. From Douala to Yaoundé — completely free, forever.

## Stack
- PHP / MySQL
- Shared cPanel hosting (biz97h)
- n8n automation (email sequences)
- IONOS SMTP

## Local Setup
1. Clone the repo
2. Copy `includes/config.example.php` to `includes/config.php`
3. Fill in your DB credentials and SMTP settings
4. Import the database schema

## Deployment
Push to `main` → cPanel Git Version Control auto-deploys to `/home/biz97h/public_html/`

## Key URLs
- Site: https://237biz.net
- Admin: https://237biz.net/admin/
- Automation queue: https://237biz.net/automation/queue.php

## Structure
```
├── admin/          Admin panel
├── automation/     Email automation (queue, tracking, n8n webhook)
├── includes/       Config, header, footer, helpers
├── uploads/        User-uploaded images
└── *.php           Public pages
```
