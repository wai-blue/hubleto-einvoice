<?php

namespace Hubleto\App\External\WaiBlue\Einvoice\Services;

class FitekXmlGenerator
{
  public function generate(string $ublXml): string
  {
    $ubl = new \DOMDocument();
    $previousUseInternalErrors = libxml_use_internal_errors(true);
    $loaded = trim($ublXml) !== '' && $ubl->loadXML($ublXml);
    $errors = libxml_get_errors();
    libxml_clear_errors();
    libxml_use_internal_errors($previousUseInternalErrors);
    if (!$loaded) {
      throw new \Exception('Generated UBL XML cannot be converted to Fitek E_Invoice: ' . ($errors[0]->message ?? 'invalid XML'));
    }

    $xpath = new \DOMXPath($ubl);
    $rootName = $ubl->documentElement?->localName ?? '';
    if (!in_array($rootName, ['Invoice', 'CreditNote', 'DebitNote'], true)) {
      throw new \Exception("Fitek does not support UBL document {$rootName}.");
    }

    $invoiceNumber = $this->requiredValue($xpath, '/*[1]/*[local-name()="ID"][1]', 'Invoice number');
    $invoiceDate = $this->requiredValue($xpath, '/*[1]/*[local-name()="IssueDate"][1]', 'Invoice date');
    $dueDate = $this->value($xpath, '/*[1]/*[local-name()="DueDate"][1]');
    $currency = $this->requiredValue($xpath, '/*[1]/*[local-name()="DocumentCurrencyCode"][1]', 'Currency');
    $supplierPath = '/*[1]/*[local-name()="AccountingSupplierParty"]/*[local-name()="Party"]';
    $customerPath = '/*[1]/*[local-name()="AccountingCustomerParty"]/*[local-name()="Party"]';
    $supplier = $this->readParty($xpath, $supplierPath, true);
    $customer = $this->readParty($xpath, $customerPath, false);
    $payToAccount = $this->value($xpath, '/*[1]/*[local-name()="PaymentMeans"]/*[local-name()="PayeeFinancialAccount"]/*[local-name()="ID"][1]');
    if ($payToAccount === '') {
      throw new \Exception('Supplier IBAN is required for Fitek sales invoices.');
    }

    $totalExcl = $this->requiredAmount($xpath, '/*[1]/*[local-name()="LegalMonetaryTotal"]/*[local-name()="TaxExclusiveAmount"][1]', 'Total excluding VAT');
    $totalIncl = $this->requiredAmount($xpath, '/*[1]/*[local-name()="LegalMonetaryTotal"]/*[local-name()="TaxInclusiveAmount"][1]', 'Total including VAT');
    $payable = $this->amount($xpath, '/*[1]/*[local-name()="LegalMonetaryTotal"]/*[local-name()="PayableAmount"][1]', $totalIncl);
    $totalVat = $this->amount($xpath, '/*[1]/*[local-name()="TaxTotal"]/*[local-name()="TaxAmount"][1]', $totalIncl - $totalExcl);

    $document = new \DOMDocument('1.0', 'UTF-8');
    $document->formatOutput = true;
    $root = $document->appendChild($document->createElement('E_Invoice'));

    $header = $root->appendChild($document->createElement('Header'));
    $this->element($document, $header, 'Date', $invoiceDate);
    $this->element($document, $header, 'FileId', substr(hash('sha256', $invoiceNumber . '|' . $invoiceDate), 0, 20));
    $this->element($document, $header, 'AppId', 'Hubleto');
    $this->element($document, $header, 'Version', '1.2');

    $invoice = $root->appendChild($document->createElement('Invoice'));
    $invoice->setAttribute('invoiceId', $invoiceNumber);
    $invoice->setAttribute('regNumber', $customer['registrationNumber']);
    $invoice->setAttribute('sellerRegnumber', $supplier['registrationNumber']);

    $parties = $invoice->appendChild($document->createElement('InvoiceParties'));
    $this->appendParty($document, $parties, 'SellerParty', $supplier);
    $this->appendParty($document, $parties, 'BuyerParty', $customer);

    $information = $invoice->appendChild($document->createElement('InvoiceInformation'));
    $type = $information->appendChild($document->createElement('Type'));
    $type->setAttribute('type', $rootName === 'CreditNote' ? 'CRE' : 'DEB');
    if ($rootName !== 'Invoice') {
      $sourceInvoice = $this->value($xpath, '/*[1]/*[local-name()="BillingReference"]//*[local-name()="ID"][1]');
      $this->optionalElement($document, $type, 'SourceInvoice', $sourceInvoice);
    }
    $this->element($document, $information, 'DocumentName', $rootName === 'CreditNote' ? 'Credit note' : 'Invoice');
    $this->element($document, $information, 'InvoiceNumber', $invoiceNumber);
    $this->optionalElement($document, $information, 'InvoiceContentText', $this->value($xpath, '/*[1]/*[local-name()="Note"][1]'));
    $this->element($document, $information, 'PaymentReferenceNumber', $invoiceNumber);
    $this->element($document, $information, 'PaymentMethod', 'Bank transfer');
    $this->element($document, $information, 'InvoiceDate', $invoiceDate);
    $this->optionalElement($document, $information, 'DueDate', $dueDate);

    $sumGroup = $invoice->appendChild($document->createElement('InvoiceSumGroup'));
    $this->element($document, $sumGroup, 'InvoiceSum', $this->decimal($totalExcl, 4));
    $taxSubtotals = $xpath->query('/*[1]/*[local-name()="TaxTotal"]/*[local-name()="TaxSubtotal"]');
    if ($taxSubtotals !== false) {
      foreach ($taxSubtotals as $taxSubtotal) {
        if (!$taxSubtotal instanceof \DOMElement) {
          continue;
        }
        $taxable = $this->amount($xpath, './*[local-name()="TaxableAmount"][1]', 0, $taxSubtotal);
        $tax = $this->amount($xpath, './*[local-name()="TaxAmount"][1]', 0, $taxSubtotal);
        $rate = $this->amount($xpath, './*[local-name()="TaxCategory"]/*[local-name()="Percent"][1]', 0, $taxSubtotal);
        $this->appendVat($document, $sumGroup, $taxable, $rate, $tax, $currency);
      }
    }
    $this->element($document, $sumGroup, 'TotalVATSum', $this->decimal($totalVat, 2));
    $this->element($document, $sumGroup, 'TotalSum', $this->decimal($totalIncl, 2));
    $this->element($document, $sumGroup, 'TotalToPay', $this->decimal($payable, 2));
    $this->element($document, $sumGroup, 'Currency', $currency);

    $invoiceItem = $invoice->appendChild($document->createElement('InvoiceItem'));
    $itemGroup = $invoiceItem->appendChild($document->createElement('InvoiceItemGroup'));
    $lineQuery = '/*[1]/*[local-name()="InvoiceLine" or local-name()="CreditNoteLine" or local-name()="DebitNoteLine"]';
    $lines = $xpath->query($lineQuery);
    if ($lines === false || $lines->length === 0) {
      throw new \Exception('At least one invoice line is required for Fitek.');
    }
    foreach ($lines as $index => $line) {
      if (!$line instanceof \DOMElement) {
        continue;
      }
      $quantityNode = $xpath->query('./*[local-name()="InvoicedQuantity" or local-name()="CreditedQuantity" or local-name()="DebitedQuantity"][1]', $line)?->item(0);
      $quantity = $quantityNode instanceof \DOMElement ? (float) $quantityNode->textContent : 0;
      $unit = $quantityNode instanceof \DOMElement ? trim($quantityNode->getAttribute('unitCode')) : '';
      $unitPrice = $this->amount($xpath, './*[local-name()="Price"]/*[local-name()="PriceAmount"][1]', 0, $line);
      $lineExcl = $this->amount($xpath, './*[local-name()="LineExtensionAmount"][1]', 0, $line);
      $vatRate = $this->amount($xpath, './/*[local-name()="ClassifiedTaxCategory"]/*[local-name()="Percent"][1]', 0, $line);
      $lineVat = round($lineExcl * $vatRate / 100, 4);

      $entry = $itemGroup->appendChild($document->createElement('ItemEntry'));
      $this->element($document, $entry, 'RowNo', (string) ($index + 1));
      $description = $this->value($xpath, './*[local-name()="Item"]/*[local-name()="Name"][1]', $line);
      if ($description === '') {
        $description = $this->value($xpath, './*[local-name()="Item"]/*[local-name()="Description"][1]', $line);
      }
      $this->element($document, $entry, 'Description', $description !== '' ? $description : 'Invoice item');
      $detail = $entry->appendChild($document->createElement('ItemDetailInfo'));
      $this->optionalElement($document, $detail, 'ItemUnit', $unit);
      $this->element($document, $detail, 'ItemAmount', $this->decimal($quantity, 4));
      $this->element($document, $detail, 'ItemPrice', $this->decimal($unitPrice, 4));
      $this->element($document, $entry, 'ItemSum', $this->decimal($lineExcl, 4));
      $this->appendVat($document, $entry, $lineExcl, $vatRate, $lineVat, null);
      $this->element($document, $entry, 'ItemTotal', $this->decimal($lineExcl + $lineVat, 4));
    }

    $payment = $invoice->appendChild($document->createElement('PaymentInfo'));
    $this->element($document, $payment, 'Currency', $currency);
    $this->element($document, $payment, 'PaymentDescription', 'Invoice ' . $invoiceNumber);
    $this->element($document, $payment, 'Payable', 'YES');
    $this->optionalElement($document, $payment, 'PayDueDate', $dueDate);
    $this->element($document, $payment, 'PaymentTotalSum', $this->decimal($payable, 2));
    $this->element($document, $payment, 'PayerName', $customer['name']);
    $this->element($document, $payment, 'PaymentId', $invoiceNumber);
    $this->element($document, $payment, 'PayToAccount', preg_replace('/\s+/', '', $payToAccount));
    $this->element($document, $payment, 'PayToName', $supplier['name']);
    $this->optionalElement($document, $payment, 'PayToBIC', $this->value($xpath, '/*[1]/*[local-name()="PaymentMeans"]/*[local-name()="PayeeFinancialAccount"]//*[local-name()="FinancialInstitutionBranch"]/*[local-name()="ID"][1]'));

    $footer = $root->appendChild($document->createElement('Footer'));
    $this->element($document, $footer, 'TotalNumberInvoices', '1');
    $this->element($document, $footer, 'TotalAmount', $this->decimal($totalIncl, 2));

    return (string) $document->saveXML();
  }

  private function readParty(\DOMXPath $xpath, string $path, bool $seller): array
  {
    $name = $this->value($xpath, $path . '/*[local-name()="PartyName"]/*[local-name()="Name"][1]');
    if ($name === '') {
      $name = $this->value($xpath, $path . '/*[local-name()="PartyLegalEntity"]/*[local-name()="RegistrationName"][1]');
    }
    $registrationNumber = $this->value($xpath, $path . '/*[local-name()="PartyIdentification"]/*[local-name()="ID"][1]');
    if ($registrationNumber === '') {
      $registrationNumber = $this->value($xpath, $path . '/*[local-name()="PartyLegalEntity"]/*[local-name()="CompanyID"][1]');
    }
    if ($registrationNumber === '') {
      $registrationNumber = $this->endpointIdentifier($this->value($xpath, $path . '/*[local-name()="EndpointID"][1]'));
    }
    if ($name === '' || $registrationNumber === '') {
      throw new \Exception(($seller ? 'Supplier' : 'Customer') . ' name and registration identifier are required for Fitek.');
    }

    $addressPath = $path . '/*[local-name()="PostalAddress"]';
    return [
      'name' => $name,
      'registrationNumber' => $registrationNumber,
      'vatNumber' => $this->value($xpath, $path . '/*[local-name()="PartyTaxScheme"]/*[local-name()="CompanyID"][1]'),
      'email' => $this->value($xpath, $path . '/*[local-name()="Contact"]/*[local-name()="ElectronicMail"][1]'),
      'phone' => $this->value($xpath, $path . '/*[local-name()="Contact"]/*[local-name()="Telephone"][1]'),
      'street1' => $this->value($xpath, $addressPath . '/*[local-name()="StreetName"][1]'),
      'street2' => $this->value($xpath, $addressPath . '/*[local-name()="AdditionalStreetName"][1]'),
      'city' => $this->value($xpath, $addressPath . '/*[local-name()="CityName"][1]'),
      'postalCode' => $this->value($xpath, $addressPath . '/*[local-name()="PostalZone"][1]'),
      'country' => $this->value($xpath, $addressPath . '/*[local-name()="Country"]/*[local-name()="IdentificationCode"][1]'),
    ];
  }

  private function appendParty(\DOMDocument $document, \DOMElement $parent, string $name, array $party): void
  {
    $element = $parent->appendChild($document->createElement($name));
    $this->element($document, $element, 'Name', $party['name']);
    $this->element($document, $element, 'RegNumber', $party['registrationNumber']);
    $this->optionalElement($document, $element, 'VATRegNumber', $party['vatNumber']);

    $hasAddress = $party['street1'] !== '' && $party['city'] !== '';
    if ($party['email'] === '' && $party['phone'] === '' && !$hasAddress) {
      return;
    }
    $contact = $element->appendChild($document->createElement('ContactData'));
    $this->optionalElement($document, $contact, 'PhoneNumber', $party['phone']);
    $this->optionalElement($document, $contact, 'E-mailAddress', $party['email']);
    if ($hasAddress) {
      $address = $contact->appendChild($document->createElement('LegalAddress'));
      $this->element($document, $address, 'PostalAddress1', $party['street1']);
      $this->optionalElement($document, $address, 'PostalAddress2', $party['street2']);
      $this->element($document, $address, 'City', $party['city']);
      $this->optionalElement($document, $address, 'PostalCode', $party['postalCode']);
      $this->optionalElement($document, $address, 'Country', $party['country']);
    }
  }

  private function appendVat(\DOMDocument $document, \DOMElement $parent, float $taxable, float $rate, float $tax, ?string $currency): void
  {
    $vat = $parent->appendChild($document->createElement('VAT'));
    $vat->setAttribute('vatId', $rate > 0 ? 'TAX' : 'TAXEX');
    $this->element($document, $vat, 'SumBeforeVAT', $this->decimal($taxable, 4));
    $this->element($document, $vat, 'VATRate', $this->decimal($rate, 2));
    $this->element($document, $vat, 'VATSum', $this->decimal($tax, 4));
    $this->optionalElement($document, $vat, 'Currency', $currency ?? '');
    if ($currency !== null) {
      $this->element($document, $vat, 'SumAfterVAT', $this->decimal($taxable + $tax, 4));
    }
  }

  private function value(\DOMXPath $xpath, string $query, ?\DOMNode $context = null): string
  {
    return trim((string) $xpath->evaluate('string(' . $query . ')', $context));
  }

  private function requiredValue(\DOMXPath $xpath, string $query, string $label): string
  {
    $value = $this->value($xpath, $query);
    if ($value === '') {
      throw new \Exception("{$label} is required for Fitek.");
    }
    return $value;
  }

  private function amount(\DOMXPath $xpath, string $query, float $default = 0, ?\DOMNode $context = null): float
  {
    $value = $this->value($xpath, $query, $context);
    return $value === '' ? $default : (float) $value;
  }

  private function requiredAmount(\DOMXPath $xpath, string $query, string $label): float
  {
    $value = $this->requiredValue($xpath, $query, $label);
    return (float) $value;
  }

  private function element(\DOMDocument $document, \DOMElement $parent, string $name, string $value): \DOMElement
  {
    $element = $parent->appendChild($document->createElement($name));
    $element->appendChild($document->createTextNode($value));
    return $element;
  }

  private function optionalElement(\DOMDocument $document, \DOMElement $parent, string $name, string $value): void
  {
    if (trim($value) !== '') {
      $this->element($document, $parent, $name, $value);
    }
  }

  private function decimal(float $value, int $scale): string
  {
    return number_format($value, $scale, '.', '');
  }

  private function endpointIdentifier(string $endpoint): string
  {
    return str_contains($endpoint, ':') ? substr($endpoint, strpos($endpoint, ':') + 1) : $endpoint;
  }
}
