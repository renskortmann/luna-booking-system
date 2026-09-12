# Request: register a SAML service provider for the LUNA OD6 booking system

*Draft to send to TU Delft ICT. Replace every `<...>` placeholder before sending.
Written for ICT identity-management staff, not for the lab.*

## Summary of the request

We ask for the web application below to be registered as a SAML 2.0 service
provider with the TU Delft identity provider at `login.tudelft.nl`, so that lab
members can sign in with their netID.

## The application

| | |
|---|---|
| Name | LUNA OD6 booking system |
| Purpose | Reserving time on the LUNA OD6 instrument in `<faculty / department / lab>` |
| URL | `https://<host>/` |
| Owner / contact | `<name>`, `<email>`, `<phone>` |
| Hosted on | TU Delft LAMP hosting, `<server or hosting request reference>` |
| Expected users | `<n>` staff, PhD candidates and students of `<group>` |
| Software | Purpose-built PHP application; SAML handled by the `onelogin/php-saml` library (v4.3) |

## Service provider details

| | |
|---|---|
| entityID | `https://<host>/auth/saml/metadata` |
| Metadata URL | `https://<host>/auth/saml/metadata` (signed, served over HTTPS) |
| Assertion Consumer Service | `https://<host>/auth/saml/acs`, binding HTTP-POST |
| Single Logout Service | `https://<host>/auth/saml/sls`, binding HTTP-Redirect |
| NameID format requested | `urn:oasis:names:tc:SAML:2.0:nameid-format:persistent` |
| Signing / digest algorithm | RSA-SHA256 / SHA-256 |
| AuthnRequests signed | Yes |
| Assertions must be signed | Yes (we reject unsigned assertions) |

The SP certificate is published in the metadata at the URL above.

## Attributes we need released

The application identifies a user by **netID**. Because the IdP issues a
*persistent* (opaque, pairwise) NameID, the netID cannot be derived from it, so
we need it as an attribute.

| Purpose | Attribute requested | Required? |
|---|---|---|
| Account identity - matched against a lab-maintained allowlist | **netID** (`uid`) | **Required** |
| Shown in the calendar so colleagues can see who booked a slot | `displayName` | Optional but wanted |
| Contact address for the lab administrator | `mail` | Optional |

If your standard release uses different attribute names or OIDs, please tell us
which ones - the application maps attribute names in configuration, so any
naming works without a code change.

## Data we store

Only what the function needs:

- netID, display name, email address (if released)
- the user's own bookings (start time, end time, optional purpose)
- an audit log of bookings and logins, pruned after 12 months

No other personal data is collected, and the application sends no email and
makes no outbound network connections. Access is restricted to netIDs that the
lab administrator has explicitly added to an allowlist; an authenticated netID
that is not on that list is refused and cannot see or create bookings.

## Questions for ICT

1. Do you require AuthnRequests and/or SAML messages to be **signed** by the SP?
   (We sign AuthnRequests by default and can also require signed messages.)
2. Is a **test or acceptance IdP** available that we can register against first?
3. Is there a **multi-factor** policy that will apply to this SP? We deliberately
   send no `RequestedAuthnContext`, so your own policy governs.
4. What is the expected **lead time**, and is there a form or ticket queue we
   should use instead of this document?
5. How should we notify you when the **SP certificate** is rotated?
