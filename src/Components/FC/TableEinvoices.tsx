import React from 'react';
import Translator from '@hubleto/react-ui/core/Translator';
import Table from '@hubleto/react-ui/components/fc/Table';
import { type TableMeta, type TableProps } from '@hubleto/react-ui/components/fc/TableInterfaces';
import FormEinvoice, { callEinvoiceAction, getErrorMessage } from './FormEinvoice';

interface TableEinvoicesProps extends TableProps {}

interface EinvoiceSummary {
  type: string;
  supplier: string;
  customer: string;
  currency: string;
  total: number | null;
  tax: number | null;
  issued: string;
  due: string;
  items: { name: string; quantity: string; total: number | null }[];
}

const componentName = 'TableEinvoices';
const parentApp = 'Hubleto/App/External/WaiBlue/Einvoice';
const T = new Translator(parentApp + '/Loader', 'Components/' + componentName);

const children = (node: Element | null, name: string): Element[] =>
  Array.from(node?.children ?? []).filter((child) => child.localName === name);
const path = (node: Element | null, ...names: string[]): Element | null =>
  names.reduce<Element | null>((current, name) => children(current, name)[0] ?? null, node);
const value = (node: Element | null, ...names: string[]): string => path(node, ...names)?.textContent?.trim() ?? '';
const amount = (text: string): number | null => text !== '' && Number.isFinite(Number(text)) ? Number(text) : null;

const parseEinvoiceSummary = (xml: string): EinvoiceSummary | null => {
  if (!xml || xml.length > 5_000_000 || /<!DOCTYPE/i.test(xml)) return null;
  try {
    const doc = new DOMParser().parseFromString(xml, 'application/xml');
    if (doc.getElementsByTagName('parsererror').length) return null;
    let root: Element | null = doc.documentElement;
    if (root.localName === 'E_Invoice') root = path(root, 'Invoice');
    if (!root) return null;
    if (path(root, 'InvoiceInformation')) {
      const type = path(root, 'InvoiceInformation', 'Type')?.getAttribute('type');
      return {
        type: type === 'CRE' ? 'Credit Note' : 'Standard',
        supplier: value(root, 'InvoiceParties', 'SellerParty', 'Name'),
        customer: value(root, 'InvoiceParties', 'BuyerParty', 'Name'),
        currency: value(root, 'InvoiceSumGroup', 'Currency') || value(root, 'PaymentInfo', 'Currency'),
        total: amount(value(root, 'InvoiceSumGroup', 'TotalSum') || value(root, 'PaymentInfo', 'PaymentTotalSum')),
        tax: amount(value(root, 'InvoiceSumGroup', 'TotalVATSum')),
        issued: value(root, 'InvoiceInformation', 'InvoiceDate'),
        due: value(root, 'InvoiceInformation', 'DueDate') || value(root, 'PaymentInfo', 'PayDueDate'),
        items: Array.from(root.getElementsByTagName('ItemEntry')).map((item) => ({
          name: value(item, 'Description'), quantity: value(item, 'ItemDetailInfo', 'ItemAmount'), total: amount(value(item, 'ItemSum')),
        })),
      };
    }
    const definitions: Record<string, string[]> = {
      Invoice: ['Standard', 'InvoiceLine', 'InvoicedQuantity'],
      CreditNote: ['Credit Note', 'CreditNoteLine', 'CreditedQuantity'],
      DebitNote: ['Debit Note', 'DebitNoteLine', 'DebitedQuantity'],
    };
    const definition = definitions[root.localName];
    if (!definition) return null;
    const partyName = (role: string): string => {
      const party = path(root, role, 'Party');
      return value(party, 'PartyLegalEntity', 'RegistrationName') || value(party, 'PartyName', 'Name');
    };
    return {
      type: definition[0],
      supplier: partyName('AccountingSupplierParty'),
      customer: partyName('AccountingCustomerParty'),
      currency: value(root, 'DocumentCurrencyCode'),
      total: amount(value(root, 'LegalMonetaryTotal', 'TaxInclusiveAmount') || value(root, 'LegalMonetaryTotal', 'PayableAmount')),
      tax: amount(value(root, 'TaxTotal', 'TaxAmount')),
      issued: value(root, 'IssueDate'),
      due: value(root, 'DueDate') || value(root, 'PaymentMeans', 'PaymentDueDate'),
      items: children(root, definition[1]).map((line) => ({
        name: value(line, 'Item', 'Name') || value(line, 'Item', 'Description'),
        quantity: value(line, definition[2]), total: amount(value(line, 'LineExtensionAmount')),
      })),
    };
  } catch {
    return null;
  }
};

const formatEinvoiceAmount = (amount: number | null, currency: string): string => {
  if (amount === null) return '';
  try {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(amount);
  } catch {
    return `${amount.toFixed(2)} ${currency}`.trim();
  }
};

const EinvoiceItems = ({ summary, limit = 3 }: { summary: EinvoiceSummary; limit?: number }) => <ul className='text-sm space-y-1'>
  {summary.items.slice(0, limit).map((item, index) => <li key={index} className='flex justify-between gap-3'>
    <span className='min-w-0 break-words'>{item.name || '?'}{Number(item.quantity) !== 0 && item.quantity ? ` (${item.quantity})` : ''}</span>
    {item.total !== null && item.total !== 0 ? <span className='whitespace-nowrap text-gray-500'>{formatEinvoiceAmount(item.total, summary.currency)}</span> : null}
  </li>)}
  {summary.items.length > limit ? <li className='text-gray-500'>+{summary.items.length - limit}</li> : null}
</ul>;

const TableEinvoices = (props: TableEinvoicesProps) => {
  return <Table
    componentName={componentName}
    parentApp={parentApp}
    model={parentApp + '/Models/Einvoice'}
    baseUrlSlug='einvoice'
    formModalProps={{type: 'right wide'}}
    view='cards'
    readonly={true}
    inlineEditingEnabled={false}
    renderAddButton={() => null}
    renderDeleteButton={() => null}
    getCellCssStyle={(table, columnName, column, row) => ({
      ...table.getDefaultCellCssStyle(columnName, column, row),
      ...(columnName === 'invoice_number' ? {maxHeight: 'none', overflow: 'hidden'} : {}),
    })}
    renderCell={(table, columnName, column, row, options) => {
      if (columnName !== 'invoice_number') return table.renderDefaultCell(columnName, column, row, options);
      const summary = parseEinvoiceSummary(String(row.xml_content || ''));
      return <div className='py-1 min-w-40 max-w-80'>
        <div className='truncate font-semibold' title={String(row.invoice_number || '')}>{row.invoice_number || `#${row.id}`}</div>
        {summary ? <div className='flex items-center gap-2 text-xs text-gray-500 whitespace-nowrap'>
          <span>{formatEinvoiceAmount(summary.total, summary.currency)}</span>
          <span>{summary.items.length} {T.translate('items')}</span>
        </div> : null}
      </div>;
    }}
    renderCard={(table, row) => {
      const summary = parseEinvoiceSummary(String(row.xml_content || ''));
      const inbound = Number(row.inbound_outbound) === 1;
      const statusColumn = table.description?.columns.status;
      const statusClass = statusColumn?.enumCssClasses?.[row.status] || '';
      const statusLabel = row['_ENUM[status]'] || statusColumn?.enumValues?.[row.status] || T.translate('Unknown');
      return <article key={row.id} className='card m-2 min-w-0 max-w-2xl flex-1 basis-80 overflow-hidden flex flex-col'>
        <div className='card-header gap-2'>
          <span className='text-xs uppercase tracking-wider'><i className={`fas ${inbound ? 'fa-arrow-down' : 'fa-arrow-up'} mr-2`} />{T.translate(inbound ? 'Inbound' : 'Outbound')}</span>
          <span className={`badge ${statusClass}`}>{statusLabel}</span>
        </div>
        <div className='card-body p-4'>
          <a className='block text-lg font-bold break-words' href={`${globalThis.hubleto.config.projectUrl}/einvoice/${row.id}`} onClick={(event) => {
            if (event.ctrlKey || event.metaKey) return;
            event.preventDefault();
            if (!row._PERMISSIONS || row._PERMISSIONS[1]) table.openForm(row.id);
          }}>{row.invoice_number || `#${row.id}`}</a>
          <div className='text-sm text-gray-500 mb-4'>{summary ? T.translate(summary.type) : T.translate('e-Invoice')} / {row.provider}</div>
          {summary ? <>
            <div className='font-semibold break-words'>{inbound ? summary.supplier : summary.customer}</div>
            <div className='text-sm text-gray-500 mb-4'>{inbound ? summary.customer : summary.supplier}</div>
            <div className='rounded bg-primary/10 p-3 mb-4'>
              <div className='text-xs text-gray-500'>{T.translate('Total incl. VAT')}</div>
              <div className='text-2xl font-bold'>{formatEinvoiceAmount(summary.total, summary.currency) || T.translate('Not available')}</div>
              {summary.tax !== null ? <div className='text-xs text-gray-500'>{T.translate('VAT')}: {formatEinvoiceAmount(summary.tax, summary.currency)}</div> : null}
            </div>
            <div className='flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500 mb-4'>
              {summary.issued ? <span>{T.translate('Issued')}: {summary.issued}</span> : null}
              {summary.due ? <span>{T.translate('Due')}: {summary.due}</span> : null}
            </div>
            <EinvoiceItems summary={summary} limit={4} />
          </> : <p className='text-sm text-gray-500'>{T.translate('XML preview unavailable')}</p>}
          {row.error_message ? <p className='alert alert-danger text-sm mt-3 break-words'>{row.error_message}</p> : null}
        </div>
        <div className='card-footer'>{table.props.renderActionsColumn?.(table, row)}</div>
      </article>;
    }}
    renderActionsColumn={(table: TableMeta, row: any) => {
      const direction = Number(row.inbound_outbound || 0);
      const invoiceId = Number(row.id_invoice || row.INVOICE?.id || 0);
      const transmissionId = String(row.transmission_id || '');
      const status = Number(row.status || 0);
      const canSend = direction === 2 && !transmissionId && [0, 1, 4].includes(status);

      return <div className='flex flex-wrap items-center justify-end gap-1'>
        {direction === 1 && invoiceId <= 0 ? <button
            type='button'
            className='btn btn-small btn-transparent text-nowrap'
            onClick={async (event) => {
              event.stopPropagation();
              try {
                const json = await callEinvoiceAction(
                  `${globalThis.hubleto.config.projectUrl}/einvoice/api/einvoices/${row.id}/create-invoice`,
                  T.translate('Inbound Hubleto invoice created'),
                  false,
                );
                const invoiceUrl = json?.result?.invoiceUrl;
                if (invoiceUrl) globalThis.location.href = invoiceUrl;
                else globalThis.location.reload();
              } catch (error) {
                globalThis.alert(getErrorMessage(error, T.translate('Failed to create inbound Hubleto invoice')));
              }
            }}
            title={T.translate('Create Hubleto invoice')}
          >
            <span className='icon'><i className='fas fa-file-circle-plus'></i></span>
            <span className='text'>{T.translate('Create Hubleto invoice')}</span>
          </button> : null}
        {direction === 1 && invoiceId > 0 ? <a
            className='btn btn-small btn-transparent text-nowrap'
            href={`${globalThis.hubleto.config.projectUrl}/invoices/${invoiceId}`}
            onClick={(event) => event.stopPropagation()}
            title={T.translate('Open Hubleto invoice')}
          >
            <span className='icon'><i className='fas fa-file-invoice'></i></span>
            <span className='text'>{T.translate('Open Hubleto invoice')}</span>
          </a> : null}
        {direction === 2 && status !== 5 ? <button
            type='button'
            className='btn btn-small btn-transparent text-nowrap'
            disabled={!canSend}
            onClick={async (event) => {
              event.stopPropagation();
              try {
                await callEinvoiceAction(
                  `${globalThis.hubleto.config.projectUrl}/einvoice/api/einvoices/${row.id}/send`,
                  T.translate('e-Invoice sent to provider'),
                );
              } catch (error) {
                globalThis.alert(getErrorMessage(error, T.translate('Failed to send e-Invoice')));
              }
            }}
            title={T.translate('Send to provider')}
          >
            <span className='icon'><i className='fas fa-paper-plane'></i></span>
            <span className='text'>{T.translate('Send to provider')}</span>
          </button> : null}
        {transmissionId ? <button
            type='button'
            className='btn btn-small btn-transparent text-nowrap'
            onClick={async (event) => {
              event.stopPropagation();
              try {
                await callEinvoiceAction(
                  `${globalThis.hubleto.config.projectUrl}/einvoice/api/einvoices/${row.id}/sync-status`,
                  T.translate('Remote status synchronized'),
                );
              } catch (error) {
                globalThis.alert(getErrorMessage(error, T.translate('Failed to synchronize status')));
              }
            }}
            title={T.translate('Sync remote status')}
          >
            <span className='icon'><i className='fas fa-rotate'></i></span>
            <span className='text'>{T.translate('Sync remote status')}</span>
          </button> : null}
        <a
          className='btn btn-small btn-transparent text-nowrap'
          href={`${globalThis.hubleto.config.projectUrl}/einvoice?action=download&id=${row.id}`}
          onClick={(event) => event.stopPropagation()}
          title={T.translate('Download XML')}
          target='_blank'
          rel='noreferrer'
        >
          <span className='icon'><i className='fas fa-download'></i></span>
          <span className='text'>{T.translate('Download XML')}</span>
        </a>
      </div>;
    }}
    renderForm={(table: TableMeta): React.JSX.Element => {
      return <FormEinvoice {...table.getDefaultFormProps()} />;
    }}
    {...props}
  ></Table>;
};

export default TableEinvoices;
