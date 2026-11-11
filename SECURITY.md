# Security Policy

## Reporting a Vulnerability

If you discover a security vulnerability in Point of Sales, **do not create a public issue**. Send your report directly to:

**Email:** abduldaim2002@gmail.com

We will acknowledge reports within **48 hours** and release a patch as soon as possible after confirming the vulnerability.

## What to Report

We accept reports for:
- XSS (Cross-Site Scripting)
- CSRF
- SQL Injection
- Authentication/Authorization bypass
- Sensitive data exposure
- Remote code execution
- Privilege escalation

## Required Information

Please include the following in your report:
- Application version (commit hash or tag)
- Steps to reproduce
- Potential impact
- (Optional) Suggested mitigation

## Security Practices in This Repository

| Area | Practice |
|------|---------|
| Password | Bcrypt hashing |
| Session | Regenerate after login, absolute lifetime timeout |
| CSRF | Laravel CSRF protection on all routes |
| Auth | Rate limiting, honeypot + timer (bot.guard middleware) |
| RBAC | Spatie Permission + step_up middleware for sensitive actions |
| Payment secrets | Encrypted at rest (Xendit/Midtrans keys) |
| Webhook | Signature verification for Midtrans & Xendit |
| Headers | SecureHeaders middleware (CSP, HSTS, X-Frame-Options) |
| User data | Input validation on all requests |

## Supported Versions

| Version | Supported |
|---------|-----------|
| v2.x | ✅ |
| v1.x | ❌ (legacy) |
