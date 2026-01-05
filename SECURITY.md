Security Policy
Overview

This project is designed and operated with a security-first and trust-aware mindset, explicitly acknowledging both modern web security threats and the unique operational realities of the EVE Online ecosystem.

Security controls focus on:

Minimizing attack surface

Limiting blast radius

Enforcing least privilege

Maintaining auditability

Preserving organizational autonomy over data

Absolute prevention is neither claimed nor assumed. Instead, the system prioritizes resilience, containment, and observability.

Threat Model
Assumptions

Users may act maliciously or deceptively.

Credentials may be compromised.

Accounts with valid access may not be trustworthy.

Espionage is a normal and expected behavior within EVE Online organizations.

External services are treated as partially trusted at best.

Out of Scope

Client-side compromise (infected browsers, compromised machines)

In-game mechanics and CCP-controlled systems

Social engineering outside the platform itself

Secure Transport & Browser Enforcement

All client interactions occur over secure connections.

Browser-enforced transport security policies are enabled to prevent downgrade attacks.

Protocol and cipher configurations align with current industry best practices.

Transport security is rolled out conservatively to avoid operational lock-in.

Content Security Policy (CSP)

A strict Content Security Policy is enforced across the application.

Design Goals

Prevent script injection (XSS)

Prevent data exfiltration

Restrict execution to known, required sources

Reduce impact of compromised components

Key Properties

Inline JavaScript execution is fully disabled.

Legacy plugin-based content is explicitly blocked.

External resources are allowlisted on a per-origin basis.

Outbound connections are restricted to required APIs and authentication endpoints.

Form submissions are limited to trusted destinations only.

Unauthorized framing and clickjacking are prevented.

Operational Approach

The CSP is designed to be:

Explicit rather than permissive

Incrementally tighten-able

Enforced (not report-only) in production

Browser Security Controls

The application enforces a comprehensive set of browser-level protections, including:

Prevention of MIME type sniffing

Clickjacking protection

Restriction of browser feature access (e.g. camera, microphone, geolocation)

Controlled referrer data exposure

These controls reduce exploitability even in the presence of application-level flaws.

Authentication & External Integrations
Authentication

Authentication relies exclusively on official EVE Online Single Sign-On services.

Tokens are treated as sensitive credentials and handled accordingly.

Authentication state is validated server-side.

External APIs

External integrations are limited to official EVE Online endpoints.

External data is treated as untrusted input.

Retrieved data is normalized and stored locally under internal access controls.

No third-party analytics or tracking services are used.

Authorization & Access Control
Core Principles

Least privilege by default

Role-based access control

Explicit permission boundaries

No implicit trust based on membership

Implementation

Access is granted based on role and responsibility, not status.

Sensitive data and operations are segmented.

Privileged actions are restricted to a minimal set of roles.

Changes to access rights are explicit and traceable.

Auditability & Accountability

Security-relevant actions are logged where technically feasible.

Logs are designed to support incident analysis and accountability.

The system favors traceability over obscurity.

Silent failure modes are avoided.

Auditability is considered a defensive control, not merely an operational convenience.

Espionage & Trust Model (EVE Online Context)
Explicit Assumption

Espionage is treated as an expected operational condition, not an exception.

Design Implications

The platform does not assume users act in good faith.

Sensitive information is compartmentalized by design.

Access to operational, financial, or strategic data is intentionally restricted.

The impact of compromised or malicious accounts is intentionally limited.

Philosophy

The objective is not to eliminate spies—an unrealistic goal in EVE—but to ensure their potential impact is:

Contained

Observable

Manageable

This approach aligns technical controls with in-game realities and established best practices within experienced EVE organizations.

Data Residency & Control
Data Handling

All application data is processed and stored under direct organizational control.

No data is sold, shared, or monetized.

There is no external behavioral tracking or analytics.

External Data

Data retrieved from external services is stored locally.

External data is governed by the same access controls as internal data.

External dependencies are minimized intentionally.

Responsibility

Data stewardship, retention, and deletion remain internal responsibilities.

Control over data location and access is considered a strategic asset.

Performance as a Security Property

Performance optimizations are treated as a security concern:

Reduced attack surface through fewer dependencies

Faster responses reduce exposure to certain abuse patterns

Efficient asset delivery lowers the incentive for unsafe caching or third-party offloading

Security and performance are considered complementary, not competing goals.

Vulnerability Disclosure

Responsible disclosure is encouraged.

If you identify a security issue:

Do not publicly disclose details before coordination.

Provide a clear description and reproduction steps if possible.

Allow reasonable time for assessment and mitigation.

There is no formal bug bounty program, but good-faith reports are appreciated.

Limitations & Reality Check

No system is perfectly secure.

This platform:

Reduces risk, it does not eliminate it

Assumes adversarial conditions

Prioritizes containment over absolute prevention

Evolves security controls incrementally

Security is treated as a continuous process, not a one-time configuration.

Final Note

This security posture reflects both modern web security best practices and the adversarial, trust-constrained environment inherent to EVE Online.

The goal is not theoretical perfection, but operational survivability.
