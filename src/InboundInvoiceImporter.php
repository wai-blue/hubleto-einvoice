<?php

namespace Hubleto\App\External\WaiBlue\Einvoice;

use Hubleto\App\Community\Invoices\Models\Invoice;
use Hubleto\App\Community\Invoices\Models\Item as InvoiceItem;
use Hubleto\App\Community\Invoices\Models\Profile;
use Hubleto\App\Community\Orders\Models\Item as OrderItem;
use Hubleto\App\Community\Orders\Models\Order;
use Hubleto\App\Community\Settings\Models\Country;
use Hubleto\App\Community\Settings\Models\Currency;
use Hubleto\App\Community\Suppliers\Models\Supplier;
use Hubleto\App\External\WaiBlue\Einvoice\Models\Einvoice;
use Hubleto\App\External\WaiBlue\Einvoice\Models\Log;
use Hubleto\App\External\WaiBlue\Einvoice\Providers\ProviderInterface;
use SimpleXMLElement;

class InboundInvoiceImporter
{
  private const CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';
  private const CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';
  private const DOCUMENT_TYPES = [
    'Invoice' => [Invoice::TYPE_STANDARD, 'InvoiceLine', 'InvoicedQuantity'],
    'CreditNote' => [Invoice::TYPE_CREDIT_NOTE, 'CreditNoteLine', 'CreditedQuantity'],
    'DebitNote' => [Invoice::TYPE_DEBIT_NOTE, 'DebitNoteLine', 'DebitedQuantity'],
  ];

  private const COUNTRY_ALPHA_3 = [
    'AT' => 'AUT', 'BE' => 'BEL', 'CH' => 'CHE', 'CZ' => 'CZE', 'DE' => 'DEU',
    'ES' => 'ESP', 'FR' => 'FRA', 'GB' => 'GBR', 'HR' => 'HRV', 'HU' => 'HUN',
    'IT' => 'ITA', 'NL' => 'NLD', 'PL' => 'POL', 'SI' => 'SVN', 'SK' => 'SVK',
    'RU' => 'RUS', 'UA' => 'UKR', 'US' => 'USA',
  ];

  public function __construct(private Loader $app)
  {
  }

  public function import(int $einvoiceId): array
  {
    /** @var Einvoice $mEinvoice */
    $mEinvoice = $this->app->getModel(Einvoice::class);
    $export = $mEinvoice->record->find($einvoiceId);
    if (!$export) throw new \Exception('e-Invoice not found.');
    if ((int) $export->inbound_outbound !== Einvoice::INBOUND) {
      throw new \Exception('Only inbound e-invoices can be converted to Hubleto invoices.');
    }
    if (!empty($export->id_invoice)) {
      return $this->result($einvoiceId, (int) $export->id_invoice, false);
    }

    // Provider I/O and XML parsing stay outside the database transaction.
    $data = $this->parseInvoiceXml($this->xmlContent($export));

    return $this->app->db()->eloquent->getConnection('default')->transaction(
      function () use ($mEinvoice, $einvoiceId, $data): array {
        $export = $mEinvoice->record->where('einvoices.id', $einvoiceId)->lockForUpdate()->first();
        if (!$export) throw new \Exception('e-Invoice not found.');
        if (!empty($export->id_invoice)) {
          return $this->result($einvoiceId, (int) $export->id_invoice, false);
        }

        $profile = $this->profile();
        $currency = $this->currency($data['currency'], $profile);
        $supplier = $this->supplier($data['supplier']);

        /** @var Invoice $mInvoice */
        $mInvoice = $this->app->getModel(Invoice::class);
        $invoice = $mInvoice->record
          ->where('invoices.inbound_outbound', Invoice::INBOUND_INVOICE)
          ->where('invoices.id_supplier', $supplier->id)
          ->where('invoices.number_external', $data['number'])
          ->first();

        if ($invoice) {
          $export->update([
            'id_invoice' => $invoice->id,
            'invoice_number' => $data['number'],
          ]);
          $this->log($einvoiceId, 'hubleto_invoice_linked', 'Existing inbound invoice linked.');
          return $this->result($einvoiceId, (int) $invoice->id, false);
        }

        $invoiceId = (int) $mInvoice->record->recordCreate([
          'inbound_outbound' => Invoice::INBOUND_INVOICE,
          'id_profile' => $profile->id,
          'id_issued_by' => $this->app->authProvider()->getUserId() ?: null,
          'id_supplier' => $supplier->id,
          'type' => $data['type'],
          'number_external' => $data['number'],
          'date_delivery' => $data['deliveryDate'] ?? $data['issueDate'] ?? date('Y-m-d'),
          'notes' => $data['note'],
        ])['id'];

        // recordCreate() intentionally generates the local invoice number and defaults.
        $invoice = $mInvoice->record->find($invoiceId);
        $invoice->update([
          'date_issue' => $data['issueDate'],
          'date_due' => $data['dueDate'],
          'id_currency' => $currency->id,
          'vs' => $data['paymentId'],
        ]);

        [$order, $orderCreated] = $this->order($data, $supplier, $currency);
        $usedOrderItemIds = [];
        $orderItemsCreated = 0;
        $orderItemsMatched = 0;

        /** @var InvoiceItem $mInvoiceItem */
        $mInvoiceItem = $this->app->getModel(InvoiceItem::class);
        /** @var OrderItem $mOrderItem */
        $mOrderItem = $this->app->getModel(OrderItem::class);
        foreach ($data['items'] as $position => $item) {
          [$orderItem, $orderItemCreated] = $this->orderItem(
            (int) $order->id,
            $item,
            $position + 1,
            $usedOrderItemIds,
          );

          $invoiceItemId = (int) $mInvoiceItem->record->recordCreate([
            'id_invoice' => $invoiceId,
            'id_order' => $order->id,
            'id_order_item' => $orderItem->id,
            'item' => $item['description'],
            'unit_price' => $item['unitPrice'],
            'amount' => $item['quantity'],
            'discount' => $item['discount'],
            'vat' => $item['vatRate'],
            'date_delivery' => $item['deliveryDate'] ?? $data['deliveryDate'] ?? $data['issueDate'],
          ])['id'];

          $mOrderItem->record
            ->where('orders_items.id', $orderItem->id)
            ->update(['id_invoice_item' => $invoiceItemId]);

          $usedOrderItemIds[] = (int) $orderItem->id;
          if ($orderItemCreated) {
            $orderItemsCreated++;
          } else {
            $orderItemsMatched++;
          }
        }

        $mInvoice->recalculateTotalsForInvoice($invoiceId);
        $this->recalculateOrder((int) $order->id);
        $export->update([
          'id_invoice' => $invoiceId,
          'invoice_number' => $data['number'],
        ]);
        $this->log(
          $einvoiceId,
          'hubleto_invoice_created',
          'Inbound Hubleto invoice created and linked to purchase order ' . $order->identifier . '.',
        );

        return $this->result(
          $einvoiceId,
          $invoiceId,
          true,
          (int) $order->id,
          $orderCreated,
          $orderItemsMatched,
          $orderItemsCreated,
        );
      }
    );
  }

  private function xmlContent(mixed $export): string
  {
    $xmlContent = trim((string) $export->xml_content);
    if ($xmlContent !== '') return $xmlContent;

    $providerCode = strtolower(trim((string) $export->provider));
    $providerClass = $this->app->providers()->getProviderDefinitions()[$providerCode]['providerClass'] ?? null;
    if (!is_string($providerClass) || !class_exists($providerClass)) {
      throw new \Exception("Unsupported e-invoice provider '{$providerCode}'.");
    }

    $provider = new $providerClass($this->app);
    if (!$provider instanceof ProviderInterface) {
      throw new \Exception("Invalid e-invoice provider '{$providerCode}'.");
    }

    $transmissionId = trim((string) $export->transmission_id);
    if ($transmissionId === '') {
      throw new \Exception('The inbound e-Invoice has no XML content or provider document ID.');
    }

    $xmlContent = trim($provider->getDocumentXml($transmissionId, 'inbound'));
    if ($xmlContent === '') {
      throw new \Exception('The provider returned empty invoice XML. Try synchronizing the e-Invoice again.');
    }

    $export->update(['xml_content' => $xmlContent]);
    return $xmlContent;
  }

  private function parseInvoiceXml(string $xmlContent): array
  {
    $previousUseInternalErrors = libxml_use_internal_errors(true);
    $ubl = simplexml_load_string($xmlContent, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOBLANKS);
    $error = libxml_get_errors()[0] ?? null;
    libxml_clear_errors();
    libxml_use_internal_errors($previousUseInternalErrors);

    if ($ubl === false) {
      throw new \Exception('The inbound e-Invoice contains invalid XML. ' . trim((string) ($error->message ?? '')));
    }

    if ($ubl->getName() === 'E_Invoice') {
      $ubl = $ubl->xpath('./Invoice')[0] ?? null;
      if (!$ubl instanceof SimpleXMLElement) {
        throw new \Exception('The Fitek e-Invoice does not contain an invoice.');
      }
      return $this->parseFitekInvoice($ubl);
    }
    if ($ubl->getName() === 'Invoice' && $ubl->xpath('./InvoiceInformation')) {
      return $this->parseFitekInvoice($ubl);
    }

    $documentType = $ubl->getName();
    $document = self::DOCUMENT_TYPES[$documentType] ?? null;
    if (!$document) throw new \Exception("Unsupported inbound UBL document '{$documentType}'.");

    $number = $this->xmlValue($ubl, './cbc:ID');
    $supplier = $this->xmlNodes($ubl, './cac:AccountingSupplierParty/cac:Party')[0] ?? null;

    if ($number === '') throw new \Exception('The inbound e-Invoice number is missing.');
    if ($supplier === null) throw new \Exception('The inbound e-Invoice supplier is missing.');

    return [
      'type' => $document[0],
      'number' => $number,
      'issueDate' => $this->date($this->xmlValue($ubl, './cbc:IssueDate')),
      'deliveryDate' => $this->date(
        $this->xmlValue($ubl, './cac:Delivery/cbc:ActualDeliveryDate')
          ?: $this->xmlValue($ubl, './cbc:TaxPointDate')
      ),
      'dueDate' => $this->date($this->xmlValue($ubl, './cbc:DueDate')),
      'currency' => strtoupper($this->xmlValue($ubl, './cbc:DocumentCurrencyCode')),
      'orderReference' => $this->xmlValue($ubl, './cac:OrderReference/cbc:ID'),
      'paymentId' => $this->xmlValue($ubl, './cac:PaymentMeans/cbc:PaymentID'),
      'note' => $this->xmlValue($ubl, './cbc:Note'),
      'supplier' => $this->parseSupplier($supplier, $ubl),
      'items' => $this->parseItems($ubl, $document[1], $document[2]),
    ];
  }

  private function parseFitekInvoice(SimpleXMLElement $invoice): array
  {
    $number = $this->plainValue($invoice, './InvoiceInformation/InvoiceNumber');
    if ($number === '') throw new \Exception('The inbound e-Invoice number is missing.');

    $supplier = $invoice->xpath('./InvoiceParties/SellerParty')[0] ?? null;
    if (!$supplier instanceof SimpleXMLElement) {
      throw new \Exception('The inbound e-Invoice supplier is missing.');
    }

    $type = strtoupper($this->plainValue($invoice, './InvoiceInformation/Type/@type'));
    $address = './ContactData/LegalAddress';

    return [
      'type' => $type === 'CRE' ? Invoice::TYPE_CREDIT_NOTE : Invoice::TYPE_STANDARD,
      'number' => $number,
      'issueDate' => $this->date($this->plainValue($invoice, './InvoiceInformation/InvoiceDate')),
      'deliveryDate' => $this->date($this->plainValue($invoice, './InvoiceInformation/DeliveryDate')),
      'dueDate' => $this->date(
        $this->plainValue($invoice, './InvoiceInformation/DueDate')
          ?: $this->plainValue($invoice, './PaymentInfo/PayDueDate')
      ),
      'currency' => strtoupper(
        $this->plainValue($invoice, './InvoiceSumGroup/Currency')
          ?: $this->plainValue($invoice, './PaymentInfo/Currency')
      ),
      'orderReference' => $this->firstPlainValue($invoice, [
        './InvoiceInformation/OrderReference',
        './InvoiceInformation/OrderNumber',
        './InvoiceInformation/PurchaseOrderNumber',
      ]),
      'paymentId' => $this->plainValue($invoice, './InvoiceInformation/PaymentReferenceNumber')
        ?: $this->plainValue($invoice, './PaymentInfo/PaymentId'),
      'note' => $this->plainValue($invoice, './InvoiceInformation/InvoiceContentText'),
      'supplier' => [
        'name' => $this->plainValue($supplier, './Name') ?: 'Imported supplier',
        'companyId' => $this->plainValue($supplier, './RegNumber'),
        'taxId' => '',
        'vatId' => strtoupper(str_replace(' ', '', $this->plainValue($supplier, './VATRegNumber'))),
        'address' => $this->plainValue($supplier, $address . '/PostalAddress1'),
        'city' => $this->plainValue($supplier, $address . '/City'),
        'postalCode' => $this->plainValue($supplier, $address . '/PostalCode'),
        'country' => strtoupper($this->plainValue($supplier, $address . '/Country')),
        'paymentAccount' => $this->plainValue($invoice, './PaymentInfo/PayToAccount'),
      ],
      'items' => $this->parseFitekItems($invoice),
    ];
  }

  private function parseFitekItems(SimpleXMLElement $invoice): array
  {
    $items = [];
    foreach ($invoice->xpath('.//InvoiceItemGroup/ItemEntry') ?: [] as $line) {
      $quantity = abs((float) $this->plainValue($line, './ItemDetailInfo/ItemAmount'));
      if ($quantity == 0.0) {
        throw new \Exception('An inbound e-Invoice line has an invalid quantity.');
      }

      $unitPrice = (float) $this->plainValue($line, './ItemDetailInfo/ItemPrice');
      $gross = $quantity * $unitPrice;
      $lineTotal = abs((float) $this->plainValue($line, './ItemSum'));
      $discount = $gross > 0 ? max(0, min(100, (1 - $lineTotal / $gross) * 100)) : 0;
      $description = $this->plainValue($line, './Description') ?: 'Imported e-Invoice item';

      $items[] = [
        'description' => $description,
        'name' => $description,
        'orderLineReference' => $this->plainValue($line, './RowNo'),
        'quantity' => $quantity,
        'unitPrice' => $unitPrice,
        'discount' => round($discount, 4),
        'vatRate' => (float) $this->plainValue($line, './VAT/VATRate'),
        'deliveryDate' => $this->date($this->plainValue($line, './DeliveryDate')),
      ];
    }

    if ($items === []) throw new \Exception('The inbound e-Invoice does not contain any supported lines.');
    return $items;
  }

  private function parseSupplier(SimpleXMLElement $party, SimpleXMLElement $ubl): array
  {
    $endpoint = $this->xmlValue($party, './cbc:EndpointID');
    $vatId = '';

    foreach ($this->xmlNodes($party, './cac:PartyTaxScheme') as $taxScheme) {
      if (strtoupper($this->xmlValue($taxScheme, './cac:TaxScheme/cbc:ID')) === 'VAT') {
        $vatId = strtoupper(str_replace(' ', '', $this->xmlValue($taxScheme, './cbc:CompanyID')));
        break;
      }
    }

    return [
      'name' => $this->xmlValue($party, './cac:PartyLegalEntity/cbc:RegistrationName')
        ?: $this->xmlValue($party, './cac:PartyName/cbc:Name')
        ?: 'Imported supplier',
      'companyId' => $this->xmlValue($party, './cac:PartyIdentification/cbc:ID')
        ?: $this->xmlValue($party, './cac:PartyLegalEntity/cbc:CompanyID'),
      'taxId' => strtoupper($this->xmlValue($party, './cbc:EndpointID/@schemeID')) === '0245' ? $endpoint : '',
      'vatId' => $vatId,
      'address' => $this->xmlValue($party, './cac:PostalAddress/cbc:StreetName'),
      'city' => $this->xmlValue($party, './cac:PostalAddress/cbc:CityName'),
      'postalCode' => $this->xmlValue($party, './cac:PostalAddress/cbc:PostalZone'),
      'country' => strtoupper($this->xmlValue($party, './cac:PostalAddress/cac:Country/cbc:IdentificationCode')),
      'paymentAccount' => $this->xmlValue($ubl, './cac:PaymentMeans/cac:PayeeFinancialAccount/cbc:ID'),
    ];
  }

  private function parseItems(SimpleXMLElement $ubl, string $lineName, string $quantityName): array
  {
    $items = [];
    foreach ($this->xmlNodes($ubl, "./cac:{$lineName}") as $line) {
      $quantity = abs((float) $this->xmlValue($line, "./cbc:{$quantityName}"));
      $baseQuantity = (float) $this->xmlValue($line, './cac:Price/cbc:BaseQuantity') ?: 1;
      if ($quantity == 0.0 || $baseQuantity == 0.0) {
        throw new \Exception('An inbound e-Invoice line has an invalid quantity.');
      }

      $unitPrice = (float) $this->xmlValue($line, './cac:Price/cbc:PriceAmount') / $baseQuantity;
      $gross = $quantity * $unitPrice;
      $lineTotal = abs((float) $this->xmlValue($line, './cbc:LineExtensionAmount'));
      $discount = $gross > 0 ? max(0, min(100, (1 - $lineTotal / $gross) * 100)) : 0;
      $name = $this->xmlValue($line, './cac:Item/cbc:Name');
      $description = $this->xmlValue($line, './cac:Item/cbc:Description');

      $items[] = [
        'description' => trim($name . ($description !== '' && $description !== $name ? ' — ' . $description : ''))
          ?: 'Imported e-Invoice item',
        'name' => $name ?: $description ?: 'Imported e-Invoice item',
        'orderLineReference' => $this->xmlValue($line, './cac:OrderLineReference/cbc:LineID'),
        'quantity' => $quantity,
        'unitPrice' => $unitPrice,
        'discount' => round($discount, 4),
        'vatRate' => (float) $this->xmlValue($line, './cac:Item/cac:ClassifiedTaxCategory/cbc:Percent'),
        'deliveryDate' => $this->date($this->xmlValue($line, './cac:Delivery/cbc:ActualDeliveryDate')),
      ];
    }

    if ($items === []) throw new \Exception('The inbound e-Invoice does not contain any supported lines.');
    return $items;
  }

  private function xmlValue(SimpleXMLElement $xml, string $path): string
  {
    return trim((string) ($this->xmlNodes($xml, $path)[0] ?? ''));
  }

  private function xmlNodes(SimpleXMLElement $xml, string $path): array
  {
    $xml->registerXPathNamespace('cac', self::CAC);
    $xml->registerXPathNamespace('cbc', self::CBC);
    return $xml->xpath($path) ?: [];
  }

  private function plainValue(SimpleXMLElement $xml, string $path): string
  {
    return trim((string) ($xml->xpath($path)[0] ?? ''));
  }

  private function firstPlainValue(SimpleXMLElement $xml, array $paths): string
  {
    foreach ($paths as $path) {
      $value = $this->plainValue($xml, $path);
      if ($value !== '') return $value;
    }
    return '';
  }

  private function date(string $value): ?string
  {
    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
  }

  private function supplier(array $data): mixed
  {
    /** @var Supplier $model */
    $model = $this->app->getModel(Supplier::class);
    foreach (['tax_id' => 'taxId', 'vat_id' => 'vatId', 'company_id' => 'companyId'] as $column => $key) {
      if ($data[$key] === '') continue;
      $supplier = $model->record->where("suppliers.{$column}", $data[$key])->first();
      if ($supplier) return $supplier;
    }

    $countryCode = self::COUNTRY_ALPHA_3[$data['country']] ?? $data['country'];
    /** @var Country $mCountry */
    $mCountry = $this->app->getModel(Country::class);
    $country = $mCountry->record->where('countries.code', $countryCode)->first();

    $id = $model->record->recordCreate([
      'name' => $data['name'],
      'address' => $data['address'],
      'city' => $data['city'],
      'postal_code' => $data['postalCode'],
      'id_country' => $country?->id,
      'tax_id' => $data['taxId'],
      'company_id' => $data['companyId'],
      'vat_id' => $data['vatId'],
      'payment_account' => $data['paymentAccount'],
    ])['id'];

    return $model->record->find((int) $id);
  }

  private function profile(): mixed
  {
    /** @var Profile $model */
    $model = $this->app->getModel(Profile::class);
    $profile = $model->record->where('invoice_profiles.is_default', 1)->first();
    $profile ??= $model->record->orderBy('invoice_profiles.id')->first();
    if (!$profile) throw new \Exception('Create an invoicing profile before importing an inbound e-Invoice.');
    return $profile;
  }

  private function currency(string $code, mixed $profile): mixed
  {
    /** @var Currency $model */
    $model = $this->app->getModel(Currency::class);
    $currency = $code !== '' ? $model->record->where('currencies.code', $code)->first() : null;
    $currency ??= $profile->id_currency ? $model->record->find($profile->id_currency) : null;
    if (!$currency) throw new \Exception("Currency '{$code}' is not configured in Hubleto.");
    return $currency;
  }

  private function order(array $data, mixed $supplier, mixed $currency): array
  {
    /** @var Order $model */
    $model = $this->app->getModel(Order::class);
    $reference = trim((string) $data['orderReference']);

    if ($reference !== '') {
      foreach (['identifier', 'identifier_external'] as $column) {
        $order = $model->record
          ->where('orders.purchase_sales', Order::PURCHASE_ORDER)
          ->where('orders.id_supplier', $supplier->id)
          ->where("orders.{$column}", $reference)
          ->lockForUpdate()
          ->first();
        if ($order) return [$order, false];
      }
    }

    $userId = $this->app->authProvider()->getUserId() ?: null;
    $orderId = (int) $model->record->recordCreate([
      'purchase_sales' => Order::PURCHASE_ORDER,
      'identifier' => $reference,
      'title' => 'Inbound e-Invoice ' . $data['number'] . ' - ' . $supplier->name,
      'id_supplier' => $supplier->id,
      'id_owner' => $userId,
      'id_manager' => $userId,
      'id_currency' => $currency->id,
      'date_order' => $data['issueDate'] ?? date('Y-m-d'),
      'required_delivery_date' => $data['deliveryDate'],
      'note' => 'Created from inbound e-Invoice ' . $data['number'] . '.',
    ])['id'];

    return [$model->record->find($orderId), true];
  }

  private function orderItem(
    int $orderId,
    array $item,
    int $position,
    array $usedOrderItemIds,
  ): array {
    /** @var OrderItem $model */
    $model = $this->app->getModel(OrderItem::class);
    $candidates = $model->record
      ->where('orders_items.id_order', $orderId)
      ->whereNull('orders_items.id_invoice_item')
      ->lockForUpdate()
      ->get();

    $matches = [];
    foreach ($candidates as $candidate) {
      if (
        !in_array((int) $candidate->id, $usedOrderItemIds, true)
        && (
          $this->sameText((string) $candidate->title, $item['name'])
          || $this->sameText((string) $candidate->title, $item['description'])
        )
        && $this->sameNumber((float) $candidate->amount, $item['quantity'])
        && $this->sameNumber((float) $candidate->unit_price, $item['unitPrice'])
        && $this->sameNumber((float) $candidate->discount, $item['discount'])
        && $this->sameNumber((float) $candidate->vat, $item['vatRate'])
      ) {
        $matches[] = $candidate;
      }
    }

    $referencedPosition = ctype_digit((string) $item['orderLineReference'])
      ? (int) $item['orderLineReference']
      : 0;
    if ($referencedPosition > 0) {
      foreach ($matches as $candidate) {
        if ((int) $candidate->position === $referencedPosition) {
          return [$candidate, false];
        }
      }
    }
    if ($matches !== []) return [$matches[0], false];

    $userId = $this->app->authProvider()->getUserId() ?: null;
    $orderItemId = (int) $model->record->recordCreate([
      'id_order' => $orderId,
      'title' => $item['description'],
      'unit_price' => $item['unitPrice'],
      'amount' => $item['quantity'],
      'discount' => $item['discount'],
      'vat' => $item['vatRate'],
      'date_delivery' => $item['deliveryDate'] ?? date('Y-m-d'),
      'date_due' => $item['deliveryDate'] ?? date('Y-m-d'),
      'id_owner' => $userId,
      'id_manager' => $userId,
      'position' => $position,
    ])['id'];

    return [$model->record->find($orderItemId), true];
  }

  private function recalculateOrder(int $orderId): void
  {
    /** @var Order $model */
    $model = $this->app->getModel(Order::class);
    $order = $model->record->find($orderId);
    if ($order) {
      $record = $order->toArray();
      $model->record->recordUpdate($record, $record);
    }
  }

  private function sameText(string $first, string $second): bool
  {
    $normalize = fn (string $value): string => strtolower(trim((string) preg_replace('/\s+/', ' ', $value)));
    return $normalize($first) === $normalize($second);
  }

  private function sameNumber(float $first, float $second): bool
  {
    return abs($first - $second) < 0.0001;
  }

  private function result(
    int $einvoiceId,
    int $invoiceId,
    bool $created,
    ?int $orderId = null,
    bool $orderCreated = false,
    int $orderItemsMatched = 0,
    int $orderItemsCreated = 0,
  ): array
  {
    return [
      'created' => $created,
      'einvoiceId' => $einvoiceId,
      'invoiceId' => $invoiceId,
      'invoiceUrl' => $this->app->env()->projectUrl . '/invoices/' . $invoiceId,
      'orderId' => $orderId,
      'orderUrl' => $orderId ? $this->app->env()->projectUrl . '/orders/' . $orderId : null,
      'orderCreated' => $orderCreated,
      'orderItemsMatched' => $orderItemsMatched,
      'orderItemsCreated' => $orderItemsCreated,
    ];
  }

  private function log(int $einvoiceId, string $action, string $message): void
  {
    /** @var Log $model */
    $model = $this->app->getModel(Log::class);
    $model->record->recordCreate([
      'id_einvoice' => $einvoiceId,
      'action_type' => $action,
      'status' => Log::STATUS_SUCCESS,
      'message' => $message,
      'timestamp' => date('Y-m-d H:i:s'),
    ]);
  }
}
