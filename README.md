# Hubleto e-Invoice

external application for sending and receiving electronic invoices
through providers connected to Hubleto.

## Features

- UBL 2.1 XML generation using `num-num/ubl-invoice` for Peppol invoicing.
- EPOSTAK, Qvalia, Fitek and MRP provider adapters.
- Fitek-specific sales-invoice XML conversion.
- Sending invoices, checking provider statuses and downloading stored XML.
- Importing incoming and outgoing documents from the selected provider.
- Creating inbound Hubleto invoices from received documents.
- Matching suppliers, purchase orders and order items during inbound import.
- Status and direction filters, notifications and invoice change listeners.

## Installation

Requires an existing Hubleto installation, PHP 8.2 or newer, and the curl, DOM,
OpenSSL and SimpleXML extensions. Required Hubleto apps are listed in
`src/manifest.yaml`.

The app is available on [Packagist](https://packagist.org/packages/wai-blue/hubleto-einvoice).
Follow the standard [Hubleto external app installation](https://www.hubleto.eu/en/apps/external)
below. Cloning this repository or adding a custom Composer repository is not
required when installing from Packagist.

### 1. Install the Composer package

Run from the Hubleto project's root:

```sh
composer require wai-blue/hubleto-einvoice
```

Only alpha releases are currently published. If your Hubleto project only allows
stable packages, explicitly allow alpha versions for this app:

```sh
composer require "wai-blue/hubleto-einvoice:^2.0@alpha"
```

Use either command as appropriate for your project's stability settings. There
is no need to lower the stability requirement for the entire project.

Composer installs the package, its UBL library and PHPMailer dependencies, and
configures autoloading for the external app.

### 2. Install the app in Hubleto

From the same Hubleto project directory, run:

```sh
php hubleto app install Hubleto/App/External/WaiBlue/Einvoice
```

Compile frontend assets through the host project's normal build process,
including this app's `src/Loader.tsx` in its bundle.

These steps describe a new installation. The external and enterprise versions
share URLs and database tables and must not be enabled together. Existing
enterprise configuration and migration history are not transferred automatically;
review that transition before installing over an existing database.

## Provider setup

1. Open **e-Invoice > Providers** or `/settings/einvoice`.
2. Select a provider and enter the credentials issued for your company.
3. Click **Save and use provider** to activate it.

| Provider | Configuration |
| --- | --- |
| EPOSTAK | API base URL, OAuth client ID and secret, default receiver Peppol scheme |
| Qvalia | API base URL, API key and AccountRegNo |
| Fitek | API base URL, Integrator ID and authorization token |
| MRP | API base URL, Client ID, Client secret and participant Identifier |

Use the provider's sandbox URL and test participants when testing. Available
document formats, statuses and XML downloads depend on the provider API.

## Sending invoices

1. Configure a company and invoicing profile in Hubleto Invoices, including
   the supplier identification, address, currency and payment details.
2. Create an outbound invoice with its customer and invoice items.
3. Invoice model events create a linked e-Invoice and refresh unsent documents
   when the invoice changes. To add an existing invoice manually, use
   **Add e-invoice** and select the linked **Invoice**.
4. Open the e-Invoice and click **Send to provider**.

Before sending, the app regenerates XML from the linked invoice and runs the
selected provider's preflight checks. A successful send stores the provider's
transmission ID for tracking; it does not necessarily mean final delivery.

Use **Sync remote status** in the record to check its progress and
**Download XML** to download the locally stored document. Inspect the response
and error fields if sending fails. Records already tracked by a provider cannot
be deleted or sent again through the send action.

## Receiving invoices

1. Click **Sync from provider** in the e-Invoice sidebar.
2. Select **Inbound** and open a received document.
3. Click **Create Hubleto invoice** to import it into Hubleto Invoices.

Creating a Hubleto invoice requires the document's XML. The app uses the local
XML when available; otherwise it requests it from the provider. A document can
appear in the synchronized list even when its XML is unavailable for import.

During import, the app:

- reuses a local supplier matching DIČ, IČ DPH or IČO, in that order, or creates
  a supplier from the XML;
- links an existing inbound invoice with the same supplier and external invoice
  number, instead of creating another one;
- otherwise creates an inbound invoice using the configured invoicing profile,
  preserving the supplier's number as its external number;
- matches a purchase order using the supplier and order reference, or creates
  a new purchase order when no match is found;
- matches unlinked order items by their descriptions, quantities, prices,
  discounts and VAT rates, using an order-line reference when applicable,
  or creates missing items, then links them to the invoice items.

Create an invoicing profile and configure the required currency before import.
Once linked, the action becomes **Open Hubleto invoice**. Repeating the import
uses the existing link.

## Synchronization

**Sync from provider** checks incoming and outgoing documents for the selected
provider, processing at most 100 per click. The next click continues the saved
position. Existing records are updated or counted as unchanged. After a complete
cycle, the next synchronization starts a new cycle.

The app synchronizes all documents exposed by the configured provider account.
A shared sandbox may therefore return other users' documents as well.

**Sync statuses now** refreshes sent records with a transmission ID. A registered
cron performs the same status update hourly (`0 * * * *`) when the host Hubleto
cron runner is scheduled. It does not fetch new provider invoices automatically.
The sidebar shows the timestamps of the manual synchronization actions.

## Statuses

| Status | Meaning |
| --- | --- |
| Pending | Initial or pending state, depending on the document and provider. |
| Waiting to be sent | XML has been generated and the document has not been sent. |
| Sent | Sent or accepted for processing; further status synchronization may be needed. |
| Confirmed | Provider status mapped to confirmation or delivery. |
| Failed | Generation, preflight, sending or provider processing reported a failure. |
| Will not be sent | Sending is disabled and the record is excluded from unsent reminders. |

Use the **Status**, **Inbound** and **Outbound** filters to narrow the list.
Sidebar badges and priority notifications count documents waiting to be sent
and failed documents. Delivery confirmation does not indicate invoice payment.
