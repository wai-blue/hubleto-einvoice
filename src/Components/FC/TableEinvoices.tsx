import React from 'react';
import Translator from '@hubleto/react-ui/core/Translator';
import Table from '@hubleto/react-ui/components/fc/Table';
import { type TableMeta, type TableProps } from '@hubleto/react-ui/components/fc/TableInterfaces';
import FormEinvoice, { callEinvoiceAction } from './FormEinvoice';

interface TableEinvoicesProps extends TableProps {}

const componentName = 'TableEinvoices'; // must be the same as the exported const
const parentApp = 'Hubleto/App/External/WaiBlue/Einvoice';
const T = new Translator(parentApp + '/Loader', 'Components/' + componentName);

const getErrorMessage = (error: unknown, fallback: string): string => {
  return error instanceof Error && error.message ? error.message : fallback;
};

const TableEinvoices = (props: TableEinvoicesProps) => {
  return <Table
    componentName={componentName}
    parentApp={parentApp}
    model={parentApp + '/Models/Einvoice'}
    endpointParams={{}}
    baseUrlSlug='einvoice'
    formModalProps={{type: 'right wide'}}
    renderActionsColumn={(table: TableMeta, row: any) => {
      const direction = Number(row.inbound_outbound || 0);
      const invoiceId = Number(row.id_invoice || row.INVOICE?.id || 0);
      const transmissionId = String(row.transmission_id || '');
      const status = Number(row.status || 0);
      const canSend = direction === 2 && !transmissionId && [0, 1, 4].includes(status);

      return <div className='flex items-center justify-end gap-1'>
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
        {table.renderDefaultActionsColumn(row)}
      </div>;
    }}
    renderForm={(table: TableMeta): React.JSX.Element => {
      return <FormEinvoice {...table.getDefaultFormProps()} />;
    }}
    {...props}
  ></Table>;
};

export default TableEinvoices;
