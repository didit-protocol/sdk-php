# Security policy

## Reporting a vulnerability

Report vulnerabilities privately, never in a public issue or pull request:

- GitHub private vulnerability reporting: [Report a vulnerability](https://github.com/didit-protocol/sdk-php/security/advisories/new)
- Email: support@didit.me (subject "Security: sdk-php")

Include the SDK version, the PHP version, what an attacker can do and the steps to reproduce.
Do not include real API keys, webhook secrets, tokens or personal data.
We acknowledge reports within three working days and keep you informed until the fix is released.

## Supported versions

Security fixes go to the latest minor version.
Before 1.0.0 every release may contain breaking changes, so upgrade to the latest 0.x.

## Handling secrets with this SDK

- Keep the API key and webhook secrets in environment variables or a secret manager, never in source code.
- The SDK never logs the API key, never puts it in exception messages and sends it only to the configured `baseUrl`.
- Verify every webhook with `Webhook::verify()` on the raw request body before acting on it.
- Authentication tokens are verified offline (ES256 only); call `getSession()` before sensitive actions to see revocations immediately.
