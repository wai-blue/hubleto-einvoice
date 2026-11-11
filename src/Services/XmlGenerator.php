<?php

namespace Hubleto\App\External\WaiBlue\Einvoice\Services;

use NumNum\UBL\AccountingParty;
use NumNum\UBL\Address;
use NumNum\UBL\BillingReference;
use NumNum\UBL\ClassifiedTaxCategory;
use NumNum\UBL\Contact;
use NumNum\UBL\Country;
use NumNum\UBL\CreditNote;
use NumNum\UBL\CreditNoteLine;
use NumNum\UBL\DebitNote;
use NumNum\UBL\DebitNoteLine;
use NumNum\UBL\FinancialInstitutionBranch;
use NumNum\UBL\Generator;
use NumNum\UBL\Invoice;
use NumNum\UBL\InvoiceDocumentReference;
use NumNum\UBL\InvoiceLine;
use NumNum\UBL\InvoiceTypeCode;
use NumNum\UBL\Item;
use NumNum\UBL\LegalEntity;
use NumNum\UBL\LegalMonetaryTotal;
use NumNum\UBL\Party;
use NumNum\UBL\PartyTaxScheme;
use NumNum\UBL\PayeeFinancialAccount;
use NumNum\UBL\PaymentMeans;
use NumNum\UBL\Price;
use NumNum\UBL\TaxCategory;
use NumNum\UBL\TaxScheme;
use NumNum\UBL\TaxSubTotal;
use NumNum\UBL\TaxTotal;
use NumNum\UBL\UnitCode;

class XmlGenerator
{
  private const CUSTOMIZATION_ID = 'urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0';
  private const PROFILE_ID = 'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0';
  private const DEFAULT_CURRENCY = 'EUR';
  private const DEFAULT_PEPPOL_SCHEME = '0245';

  private const COUNTRY_MAP = [
    'SVK' => 'SK',
    'CZE' => 'CZ',
    'AUT' => 'AT',
    'HUN' => 'HU',
    'POL' => 'PL',
    'DEU' => 'DE',
    'USA' => 'US',
    'GBR' => 'GB',
    'FRA' => 'FR',
    'ITA' => 'IT',
    'ESP' => 'ES',
    'NLD' => 'NL',
    'BEL' => 'BE',
    'CHE' => 'CH',
    'HRV' => 'HR',
    'SVN' => 'SI',
    'UKR' => 'UA',
    'RUS' => 'RU',
  ];

  public function generate(array $data): string
  {
    $type = (int) ($data['type'] ?? 3);

    if ($type === 4) { $document = new CreditNote(); $document->setInvoiceTypeCode(InvoiceTypeCode::CREDIT_NOTE);
    } elseif ($type === 5) { $document = new DebitNote(); $document->setInvoiceTypeCode(InvoiceTypeCode::DEBIT_NOTE);} 
    else { $document = new Invoice(); $document->setInvoiceTypeCode(380); }

    $currencyCode = $data['CURRENCY']['code'] ?? self::DEFAULT_CURRENCY;

    // UBL identification
    $document->setUBLVersionId('2.1')
      ->setCustomizationId(self::CUSTOMIZATION_ID)
      ->setProfileId(self::PROFILE_ID)
      ->setId($data['number'] ?? '')
      ->setIssueDate(new \DateTime($data['date_issue'] ?? 'now'))
      ->setDocumentCurrencyCode($currencyCode);

    if (!empty($data['date_due']) && method_exists($document, 'setDueDate')) { $document->setDueDate(new \DateTime($data['date_due'])); }
    if (!empty($data['notes'])) { $document->setNote($data['notes']); }
    if (!empty($data['date_delivery'])) {
      if ($document instanceof Invoice && !$document instanceof CreditNote && !$document instanceof DebitNote) {
        $document->setTaxPointDate(new \DateTime($data['date_delivery']));
      }
    }
    if (!empty($data['vs']) && $document instanceof Invoice && !$document instanceof CreditNote && !$document instanceof DebitNote) {
      $document->setBuyerReference($data['vs']);
    }

    if (($document instanceof CreditNote || $document instanceof DebitNote) && !empty($data['number_external'])) {
      $billingReference = (new BillingReference())
        ->setInvoiceDocumentReference(
          (new InvoiceDocumentReference())
            ->setOriginalInvoiceId($data['number_external'])
        );
      $document->setBillingReference($billingReference);
    }

    // Parties
    $document->setAccountingSupplierParty($this->createSupplierParty($data));
    $document->setAccountingCustomerParty($this->createCustomerParty($data));

    // Payment means
    $paymentMeans = $this->createPaymentMeans($data);
    if ($paymentMeans) { $document->setPaymentMeans($paymentMeans); }

    // Lines
    $lines = $this->createLines($data['ITEMS'] ?? [], $type);
    if ($document instanceof CreditNote) { $document->setCreditNoteLines($lines); } 
    elseif ($document instanceof DebitNote) { $document->setDebitNoteLines($lines); } 
    else { $document->setInvoiceLines($lines);}

    // Tax and Totals
    $document->setTaxTotal($this->createTaxTotal($data));
    $document->setLegalMonetaryTotal($this->createMonetaryTotal($data));

    $generator = new Generator();
    if ($document instanceof CreditNote) { return $generator->creditNote($document);} 
    elseif ($document instanceof DebitNote) { return $generator->debitNote($document);}

    return $generator->invoice($document);
  }

  private function createSupplierParty(array $data): AccountingParty
  {
    $profile = $data['PROFILE'] ?? [];
    $company = $profile['COMPANY'] ?? [];

    $countryCode = $company['COUNTRY']['code'] ?? $company['country'] ?? 'SK';
    $countryCode = self::COUNTRY_MAP[$countryCode] ?? (strlen($countryCode) === 2 ? strtoupper($countryCode) : 'SK');

    $address = (new Address())
      ->setCountry((new Country())->setIdentificationCode($countryCode));

    if (!empty($company['street_1'])) { $address->setStreetName($company['street_1']); }
    if (!empty($company['city'])) { $address->setCityName($company['city']); }
    if (!empty($company['zip'])) { $address->setPostalZone($company['zip']); }
    if (!empty($company['street_2'])) { $address->setAdditionalStreetName($company['street_2']); }

    $name = $company['name'] ?? $profile['name'] ?? '';

    $party = (new Party())
      ->setName($name)
      ->setPostalAddress($address);

    if (!empty($company['company_id'])) { $party->setPartyIdentificationId($company['company_id']); }
    $supplierEndpoint = $this->resolveEndpoint($company['peppol_id'] ?? null, $company['tax_id'] ?? $company['vat_id'] ?? null);
    if ($supplierEndpoint) {
      $party->setEndpointId($supplierEndpoint['identifier'], $supplierEndpoint['scheme']);
    }

    $supplierVatId = $this->normalizeVatId($company['vat_id'] ?? null, $countryCode);
    if (!empty($supplierVatId)) {
      $party->setPartyTaxScheme((new PartyTaxScheme())
        ->setCompanyId($supplierVatId)
        ->setTaxScheme((new TaxScheme())->setId('VAT')));
    } elseif (trim((string) ($company['tax_id'] ?? '')) !== '') {
      $party->setPartyTaxScheme((new PartyTaxScheme())
        ->setCompanyId(trim((string) $company['tax_id']))
        ->setTaxScheme((new TaxScheme())->setId('FC')));
    }

    $legalEntity = (new LegalEntity())->setRegistrationName($name);
    if (!empty($company['company_id'])) {
      $legalEntity->setCompanyId($company['company_id']);
    } elseif (!empty($company['tax_id'])) {
      $legalEntity->setCompanyId($company['tax_id']);
    }
    $party->setLegalEntity($legalEntity);

    if (!empty($profile['email']) || !empty($profile['phone'])) {
      $contact = new Contact();
      if (!empty($profile['email']))
        $contact->setElectronicMail($profile['email']);
      if (!empty($profile['phone']))
        $contact->setTelephone($profile['phone']);
      $party->setContact($contact);
    }

    return (new AccountingParty())->setParty($party);
  }

  private function createCustomerParty(array $data): AccountingParty
  {
    $customer = $data['CUSTOMER'] ?? [];

    $countryCode = $customer['COUNTRY']['code'] ?? $customer['country'] ?? 'SK';
    $countryCode = self::COUNTRY_MAP[$countryCode] ?? (strlen($countryCode) === 2 ? strtoupper($countryCode) : 'SK');

    $address = (new Address())
      ->setCountry((new Country())->setIdentificationCode($countryCode));

    if (!empty($customer['street_line_1'])) { $address->setStreetName($customer['street_line_1']); }
    if (!empty($customer['city'])) { $address->setCityName($customer['city']); }
    if (!empty($customer['postal_code'])) { $address->setPostalZone($customer['postal_code']); }
    if (!empty($customer['street_line_2'])) { $address->setAdditionalStreetName($customer['street_line_2']); }

    $name = $customer['name'] ?? '';

    $party = (new Party())
      ->setName($name)
      ->setPostalAddress($address);

    if (!empty($customer['company_id'])) { $party->setPartyIdentificationId($customer['company_id']); }
    $customerEndpoint = $this->resolveEndpoint($customer['peppol_id'] ?? null, $customer['tax_id'] ?? $customer['vat_id'] ?? null);
    if ($customerEndpoint) {
      $party->setEndpointId($customerEndpoint['identifier'], $customerEndpoint['scheme']);
    }

    $customerVatId = $this->normalizeVatId($customer['vat_id'] ?? null, $countryCode);
    if (!empty($customerVatId)) {
      $party->setPartyTaxScheme((new PartyTaxScheme())
        ->setCompanyId($customerVatId)
        ->setTaxScheme((new TaxScheme())->setId('VAT')));
    }

    $legalEntity = (new LegalEntity())->setRegistrationName($name);
    if (!empty($customer['company_id'])) {
      $legalEntity->setCompanyId($customer['company_id']);
    } elseif (!empty($customer['tax_id'])) {
      $legalEntity->setCompanyId($customer['tax_id']);
    }
    $party->setLegalEntity($legalEntity);

    if (!empty($customer['email']) || !empty($customer['phone'])) {
      $contact = new Contact();
      if (!empty($customer['email'])) { $contact->setElectronicMail($customer['email']); }
      if (!empty($customer['phone'])) { $contact->setTelephone($customer['phone']); }
      $party->setContact($contact);
    }

    return (new AccountingParty())->setParty($party);
  }

  private function createPaymentMeans(array $data): ?array
  {
    $iban = $data['PROFILE']['iban'] ?? null;
    if (!$iban) return null;

    $account = (new PayeeFinancialAccount())->setId($iban);
    if (!empty($data['PROFILE']['swift'])) {
      $account->setFinancialInstitutionBranch(
        (new FinancialInstitutionBranch())->setId($data['PROFILE']['swift'])
      );
    }

    return [
(new PaymentMeans())
        ->setPaymentMeansCode(30, [])
        ->setPayeeFinancialAccount($account)
    ];
  }

  private function createLines(array $items, int $documentType): array
  {
    $lines = [];
    $lineId = 1;

    foreach ($items as $item) {
      $vatRate = (float) ($item['vat'] ?? $item['vat_rate'] ?? $item['VAT_RATE']['rate'] ?? 20);

      $classifiedTaxCategory = (new ClassifiedTaxCategory())
        ->setId($this->getTaxCategoryId($vatRate))
        ->setPercent($vatRate)
        ->setTaxScheme((new TaxScheme())->setId('VAT'));

      $productItem = (new Item())
        ->setName($item['item'] ?? 'Item')
        ->setClassifiedTaxCategory($classifiedTaxCategory);

      if (!empty($item['description'])) { $productItem->setDescription($item['description']); }

      $price = (new Price())
        ->setBaseQuantity(1)
        ->setUnitCode(UnitCode::UNIT)
        ->setPriceAmount((float) ($item['unit_price'] ?? 0));

      $qty = (float) ($item['amount'] ?? 1);

      if ($documentType === 4) { // Credit Note
        $line = new CreditNoteLine();
        $line->setCreditedQuantity($qty);
      } elseif ($documentType === 5) { // Debit Note
        $line = new DebitNoteLine();
        $line->setDebitedQuantity($qty);
      } else {
        $line = new InvoiceLine();
        $line->setInvoicedQuantity($qty);
      }

      $line->setId($lineId++)
        ->setItem($productItem)
        ->setPrice($price);

      $lineExtension = (float) ($item['price_excl_vat'] ?? 0);
      $line->setLineExtensionAmount($lineExtension);

      $lines[] = $line;
    }

    return $lines;
  }

  private function createTaxTotal(array $data): TaxTotal
  {
    $totalVat = (float) ($data['total_vat'] ?? 0);

    if ($totalVat == 0 && isset($data['total_incl_vat']) && isset($data['total_excl_vat'])) {
      $totalVat = (float) $data['total_incl_vat'] - (float) $data['total_excl_vat'];
    }

    $taxTotal = (new TaxTotal())->setTaxAmount($totalVat);

    // Group by VAT rate
    $vatGroups = [];
    foreach ($data['ITEMS'] ?? [] as $item) {
      $rate = (float) ($item['vat'] ?? 20);
      $key = (string) $rate;

      if (!isset($vatGroups[$key])) {
        $vatGroups[$key] = ['rate' => $rate, 'taxable' => 0, 'tax' => 0];
      }
      $lineExtension = (float) ($item['price_excl_vat'] ?? 0);
      $itemVat = (float) ($item['price_vat'] ?? 0);

      $vatGroups[$key]['taxable'] += $lineExtension;
      $vatGroups[$key]['tax'] += $itemVat;
    }

    foreach ($vatGroups as $group) {
      $taxTotal->addTaxSubTotal(
        (new TaxSubTotal())
          ->setTaxableAmount($group['taxable'])
          ->setTaxAmount($group['tax'])
          ->setTaxCategory(
            (new TaxCategory())
              ->setId($this->getTaxCategoryId($group['rate']), [])
              ->setPercent($group['rate'])
              ->setTaxScheme((new TaxScheme())->setId('VAT'))
          )
      );
    }

    return $taxTotal;
  }

  private function createMonetaryTotal(array $data): LegalMonetaryTotal
  {
    $totalExcl = (float) ($data['total_excl_vat'] ?? 0);
    $totalIncl = (float) ($data['total_incl_vat'] ?? 0);

    $lineExtensionAmount = 0.0;
    foreach ($data['ITEMS'] ?? [] as $item) {
      $lineExtensionAmount += (float) ($item['price_excl_vat'] ?? 0);
    }
    if ($lineExtensionAmount == 0)
      $lineExtensionAmount = $totalExcl;

    $prepaid = 0.0;
    foreach ($data['PAYMENTS'] ?? [] as $payment) {
      if (!empty($payment['is_advance_payment'])) {
        $prepaid += (float) ($payment['amount'] ?? 0);
      }
    }

    $total = (new LegalMonetaryTotal())
      ->setLineExtensionAmount($lineExtensionAmount)
      ->setTaxExclusiveAmount($totalExcl)
      ->setTaxInclusiveAmount($totalIncl)
      ->setPayableAmount(max(0, $totalIncl - $prepaid));

    if ($prepaid > 0) {
      $total->setPrepaidAmount($prepaid);
    }
    
    return $total;
  }

  private function getTaxCategoryId(float $rate): string
  {
    if ($rate == 0) return 'Z';
    if ($rate < 10) return 'AA';
    return 'S';
  }

  private function resolveEndpoint(?string $explicitPeppolId, ?string $fallbackIdentifier): ?array
  {
    $explicitPeppolId = trim((string) $explicitPeppolId);
    if ($explicitPeppolId !== '') {
      $parts = explode(':', $explicitPeppolId, 2);
      if (count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '') {
        return [
          'scheme' => $parts[0],
          'identifier' => $parts[1],
        ];
      }
    }

    $identifier = preg_replace('/[^0-9]/', '', (string) $fallbackIdentifier);
    if ($identifier === '') {
      return null;
    }

    return [
      'scheme' => self::DEFAULT_PEPPOL_SCHEME,
      'identifier' => $identifier,
    ];
  }

  private function normalizeVatId(?string $vatId, string $countryCode): ?string
  {
    $vatId = strtoupper(trim((string) $vatId));
    if ($vatId === '') {
      return null;
    }

    $normalizedCountryCode = strtoupper($countryCode);
    if (str_starts_with($vatId, $normalizedCountryCode)) {
      return $vatId;
    }

    return $normalizedCountryCode . preg_replace('/\s+/', '', $vatId);
  }
}
