import React from 'react';
import Translator from '@hubleto/react-ui/core/Translator';
import { type FormProps } from '@hubleto/react-ui/components/fc/FormInterfaces';
import Form, { FormMetaContext } from '@hubleto/react-ui/components/fc/Form';
import Input from '@hubleto/react-ui/components/fc/FormComponents/Input';
import { useRecordField } from '@hubleto/react-ui/components/fc/FormRecordStore';

export interface FormEinvoiceProps extends FormProps {}

const componentName = 'FormEinvoice'; // must be the same as the exported const
const parentApp = 'Hubleto/App/External/WaiBlue/Einvoice';
const T = new Translator(parentApp + '/Loader', 'Components/' + componentName);

const extractJsonFromResponse = (responseText: string): any => {
  const trimmed = responseText.trim();
  if (trimmed === '') return null;

  try {
    return JSON.parse(trimmed);
  } catch (error) {
    const jsonStart = trimmed.indexOf('{');
    const jsonEnd = trimmed.lastIndexOf('}');
    if (jsonStart >= 0 && jsonEnd > jsonStart) {
      return JSON.parse(trimmed.slice(jsonStart, jsonEnd + 1));
    }

    throw error;
  }
};

const extractHumanError = (responseText: string): string => {
  const stripped = responseText
    .replace(/<pre[\s\S]*?>/gi, '')
    .replace(/<\/pre>/gi, '')
    .replace(/<[^>]+>/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();

  if (/sign in|forgot password/i.test(stripped)) {
    return 'The request returned the Hubleto sign-in page instead of JSON. Refresh the page and sign in again.';
  }

  return stripped || 'Request failed';
};

export const callEinvoiceAction = async (
  url: string,
  successMessage: string,
  reload = true,
): Promise<any> => {
  const response = await globalThis.fetch(url);
  const responseText = await response.text();

  let json: any = null;
  try {
    json = extractJsonFromResponse(responseText);
  } catch (_error) {
    throw new Error(extractHumanError(responseText));
  }

  if (!response.ok || json?.status === 'error') {
    throw new Error(json?.message || json?.error || extractHumanError(responseText));
  }

  const status =
    json?.result?.send?.status
    || json?.result?.status
    || json?.result?.decision
    || '';

  globalThis.alert(
    `${successMessage}${status ? ` (${String(status).toUpperCase()})` : ''}`,
  );
  if (reload) globalThis.location.reload();

  return json;
};

const getErrorMessage = (error: unknown, fallback: string): string => {
  return error instanceof Error && error.message ? error.message : fallback;
};

const InboundInvoiceAction = () => {
  const form = React.useContext(FormMetaContext);
  const invoiceId = Number(useRecordField<number>('id_invoice', 0));
  const direction = Number(useRecordField<number>('inbound_outbound', 0));

  if (form.id <= 0 || direction !== 1) return null;

  if (invoiceId > 0) {
    return <a
      className='btn btn-primary'
      href={`${globalThis.hubleto.config.projectUrl}/invoices/${invoiceId}`}
    >
      <span className='icon'><i className='fas fa-file-invoice'></i></span>
      <span className='text'>{T.translate('Open Hubleto invoice')}</span>
    </a>;
  }

  return <button
    type='button'
    className='btn btn-add'
    onClick={async () => {
      try {
        const json = await callEinvoiceAction(
          `${globalThis.hubleto.config.projectUrl}/einvoice/api/einvoices/${form.id}/create-invoice`,
          T.translate('Inbound Hubleto invoice created'),
          false,
        );
        const invoiceUrl = json?.result?.invoiceUrl;
        if (invoiceUrl) globalThis.location.href = invoiceUrl;
        else form.reload();
      } catch (error) {
        globalThis.alert(getErrorMessage(error, T.translate('Failed to create inbound Hubleto invoice')));
      }
    }}
  >
    <span className='icon'><i className='fas fa-file-circle-plus'></i></span>
    <span className='text'>{T.translate('Create Hubleto invoice')}</span>
  </button>;
};

/** TabDefault */
const TabDefault = (props: FormEinvoiceProps) => {
  return <div>
    <Input field='id_invoice' />
    <Input field='inbound_outbound' />
    <Input field='provider' />
    <Input field='invoice_number' />
    <Input field='xml_content' />
    <Input field='status' />
    <Input field='transmission_id' />
    <Input field='date_generated' />
    <Input field='date_sent' />
    <Input field='date_confirmed' />
    <Input field='peppol_response' />
    <Input field='error_message' />
  </div>;
};

const HeaderLeft = () => {
  const form = React.useContext(FormMetaContext);
  const direction = Number(useRecordField<number>('inbound_outbound', 0));
  const transmissionId = useRecordField<string>('transmission_id', '');
  const status = Number(useRecordField<number>('status', 0));

  if (form.id <= 0) return form.renderDefaultHeaderLeft();
  if (direction === 1) return form.renderDefaultHeaderLeft();
  if (status === 5) return form.renderDefaultHeaderLeft();

  const canSend = direction === 2 && !transmissionId && [0, 1, 4].includes(status);

  return <button
    type='button'
    className={canSend ? 'btn btn-add btn-large' : 'btn btn-disabled btn-large'}
    disabled={!canSend}
    onClick={async () => {
      try {
        await callEinvoiceAction(
          `${globalThis.hubleto.config.projectUrl}/einvoice/api/einvoices/${form.id}/send`,
          T.translate('e-Invoice sent to provider'),
        );
      } catch (error) {
        globalThis.alert(getErrorMessage(error, T.translate('Failed to send e-Invoice')));
      }
    }}
  >
    <span className='icon'><i className='fas fa-paper-plane'></i></span>
    <span className='text'>{T.translate('Send to provider')}</span>
  </button>;
};

const Footer = () => {
  const form = React.useContext(FormMetaContext);
  const transmissionId = useRecordField<string>('transmission_id', '');

  if (form.id <= 0) return form.renderDefaultFooter();

  return <>
    <div className='w-full flex flex-wrap gap-2 mb-2'>
      <button
        type='button'
        className='btn btn-primary-outline'
        disabled={!transmissionId}
        onClick={async () => {
          try {
            await callEinvoiceAction(
              `${globalThis.hubleto.config.projectUrl}/einvoice/api/einvoices/${form.id}/sync-status`,
              T.translate('Remote status synchronized'),
            );
          } catch (error) {
            globalThis.alert(getErrorMessage(error, T.translate('Failed to synchronize status')));
          }
        }}
      >
        <span className='icon'><i className='fas fa-rotate'></i></span>
        <span className='text'>{T.translate('Sync remote status')}</span>
      </button>
      <a
        className='btn btn-primary-outline'
        href={`${globalThis.hubleto.config.projectUrl}/einvoice?action=download&id=${form.id}`}
        target='_blank'
        rel='noreferrer'
      >
        <span className='icon'><i className='fas fa-download'></i></span>
        <span className='text'>{T.translate('Download XML')}</span>
      </a>
      <InboundInvoiceAction />
    </div>
    {form.renderDefaultFooter()}
  </>;
};

/** FormEinvoice */
const FormEinvoice = (props: FormEinvoiceProps) => {
  return <Form
    componentName={componentName}
    parentApp={parentApp}
    model={parentApp + '/Models/Einvoice'}
    urlSlug='einvoice'
    endpointParams={{}}
    title={{field: 'invoice_number', sub: T.translate('e-Invoice')}}
    tabs={{default: {content: () => <TabDefault {...props} />}}}
    renderHeaderLeft={() => <HeaderLeft />}
    renderFooter={() => <Footer />}
    {...props}
  ></Form>;
};

export default FormEinvoice;
