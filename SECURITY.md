# Security Policy

## Supported Versions

| Version | Supported |
|---------|-----------|
| 1.0-beta | ✅ Current |
| 0.5.x   | ⚠️ Maintenance only |
| < 0.5   | ❌ No longer supported |

## Reporting a Vulnerability

If you discover a security vulnerability in Razy, please report it responsibly:

1. **Do NOT** open a public GitHub issue
2. **Email**: Send a detailed report to the maintainer (see [composer.json](composer.json) for contact)
3. **Include**:
   - Description of the vulnerability
   - Steps to reproduce
   - Potential impact
   - Suggested fix (if any)

## Response Timeline

- **Acknowledgment**: Within 48 hours
- **Initial assessment**: Within 1 week
- **Fix release**: As soon as practical, depending on severity

## Scope

The following are in scope for security reports:

- Authentication / authorization bypasses
- SQL injection or other injection attacks
- Cross-site scripting (XSS) in template engine output
- Cryptographic weaknesses in `Crypt` class
- Path traversal in file handling
- Sensitive data exposure
- Remote code execution

## Out of Scope

- Vulnerabilities in third-party dependencies (report to the upstream project)
- Issues requiring physical access to the server
- Denial of service via resource exhaustion (unless trivially exploitable)

## Disclosure Policy

- We follow **coordinated disclosure** — please allow time for a fix before public disclosure
- Credit will be given to reporters in the release notes (unless anonymity is preferred)
